<?php

namespace App\Services;

use App\Enums\FieldType;

/**
 * The gates every AI-produced schema must clear before SchemaService::normalize()
 * gets a chance to quietly repair the problem away.
 *
 * normalize() is a repair function, and three of its repairs are destructive
 * here: an unrecognised type falls back to text, a duplicate key is suffixed to
 * key_2, and a key is slugified outright. Each would turn a model mistake into a
 * silently valid form, so they are caught upstream instead.
 *
 * This lives in its own class because AI generation, AI editing and document
 * import all need the identical judgement, and security logic that exists in
 * more than one copy eventually stops matching.
 */
class SchemaCandidateGate
{
    /**
     * Every gate that applies to any candidate, whatever produced it.
     *
     * A structural failure short-circuits, because the field walk below it has
     * nothing meaningful to say about a candidate with no sections array.
     *
     * $stored is the schema being edited, and is passed only by callers that
     * are editing. Import leaves it null: an import replaces the schema rather
     * than editing it, so there is no retained field to hold to its key.
     *
     * @param  array<string, mixed>|null  $candidate
     * @param  array<string, mixed>|null  $stored
     * @return array<string, list<string>>
     */
    public function errorsFor(?array $candidate, ?array $stored = null): array
    {
        if ($candidate === null) {
            return ['response' => ['The reply was not a JSON object. Return the schema as raw JSON.']];
        }

        $errors = $this->structuralErrors($candidate);

        if ($errors !== []) {
            return $errors;
        }

        $errors = array_merge(
            $this->fieldTypeErrors($candidate),
            $this->duplicateKeyErrors($candidate),
        );

        if ($stored !== null) {
            $errors = array_merge($errors, $this->keyPreservationErrors($stored, $candidate));
        }

        return $errors;
    }

    /**
     * The hard rule for AI edits: a field that survives the edit keeps its key.
     *
     * Identity is the field id, which the edit prompt requires the model to copy
     * verbatim. That is what separates an accidental rename, which is rejected,
     * from a deliberate removal or replacement, which is allowed. slugifyKey()
     * and uniqueKey() are both capable of moving a key without saying so, so
     * callers run this against the raw candidate and again against normalize()
     * output.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $candidate
     * @return array<string, list<string>>
     */
    public function keyPreservationErrors(array $stored, array $candidate): array
    {
        $storedKeys = [];

        foreach ($this->walk($stored) as [, $field]) {
            $id = $this->fieldId($field);

            if ($id !== null && is_string($field['key'] ?? null)) {
                $storedKeys[$id] = $field['key'];
            }
        }

        $errors = [];

        foreach ($this->walk($candidate) as [$path, $field]) {
            $id = $this->fieldId($field);

            // No id, or an id we have never seen, is a new field. Allowed.
            if ($id === null || ! isset($storedKeys[$id])) {
                continue;
            }

            $expected = $storedKeys[$id];
            $actual = $field['key'] ?? null;

            if ($actual === $expected) {
                continue;
            }

            $shown = is_scalar($actual) ? (string) $actual : 'nothing';

            $errors["{$path}.key"][] = "Field key must not change: expected [{$expected}], received [{$shown}]. "
                .'Keep the original key for every field you are not removing.';
        }

        return $errors;
    }

    /**
     * Reject a candidate that normalize() had to repair to make valid.
     *
     * errorsFor() catches the repairs that matter to a machine-authored
     * schema. A hand-authored one needs more, because normalize() will also
     * slugify "Full Name" into full_name, replace any id that is not a ULID,
     * and drop a section or field whose shape it cannot read. Each of those
     * silently produces a form the author did not write, which is the one
     * outcome a raw editor must not have.
     *
     * Comparison is positional, which is safe because normalize() preserves
     * document order. A count mismatch means something was dropped, and that
     * is reported first because it invalidates every index after it.
     *
     * @param  array<string, mixed>  $candidate  as authored
     * @param  array<string, mixed>  $normalized  the output of SchemaService::normalize()
     * @return array<string, list<string>>
     */
    public function repairsFor(array $candidate, array $normalized): array
    {
        $authored = is_array($candidate['sections'] ?? null) ? array_values($candidate['sections']) : [];
        $result = $normalized['sections'] ?? [];

        if (count($authored) !== count($result)) {
            return ['sections' => [
                'Every section must be an object with a fields array. '
                    .count($authored).' were written and '.count($result).' could be read.',
            ]];
        }

        $errors = [];

        foreach ($authored as $index => $section) {
            $fields = is_array($section) && is_array($section['fields'] ?? null)
                ? array_values($section['fields'])
                : [];
            $normalizedFields = $result[$index]['fields'] ?? [];

            if (count($fields) !== count($normalizedFields)) {
                $errors["sections.{$index}.fields"][] = 'Every field must be an object. '
                    .count($fields).' were written and '.count($normalizedFields).' could be read.';

                continue;
            }

            foreach ($fields as $fieldIndex => $field) {
                if (! is_array($field)) {
                    continue;
                }

                $path = "sections.{$index}.fields.{$fieldIndex}";

                foreach (['key', 'id', 'type'] as $property) {
                    $written = $field[$property] ?? null;

                    // Absent means "you choose", so only an authored value is held to.
                    if (! is_string($written) || $written === '') {
                        continue;
                    }

                    $read = $normalizedFields[$fieldIndex][$property] ?? null;

                    // ids are compared case-insensitively; normalize() upper-cases them.
                    $same = $property === 'id'
                        ? strtoupper($written) === strtoupper((string) $read)
                        : $written === $read;

                    if (! $same) {
                        $errors["{$path}.{$property}"][] = "The {$property} [{$written}] is not usable as written "
                            ."and would become [{$read}]. Change it yourself rather than letting it be rewritten.";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * normalize() invents a title and an empty section list, which would turn
     * "the model replied with prose" into a valid but empty form.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, list<string>>
     */
    private function structuralErrors(array $candidate): array
    {
        $errors = [];

        $title = $candidate['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            $errors['title'][] = 'The schema must include a non-empty title.';
        }

        if (! is_array($candidate['sections'] ?? null)) {
            $errors['sections'][] = 'The schema must include a sections array.';
        }

        return $errors;
    }

    /**
     * normalizeField() falls back to FieldType::Text for anything it does not
     * recognise, so without this gate an invented type is silently rewritten.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, list<string>>
     */
    private function fieldTypeErrors(array $candidate): array
    {
        $errors = [];
        $allowed = implode(', ', FieldType::values());

        foreach ($this->walk($candidate) as [$path, $field]) {
            $type = $field['type'] ?? null;

            if (! is_string($type) || FieldType::tryFrom($type) === null) {
                $shown = is_scalar($type) ? (string) $type : 'nothing';
                $errors["{$path}.type"][] = "Unsupported field type [{$shown}]. Use one of: {$allowed}.";
            }
        }

        return $errors;
    }

    /**
     * uniqueKey() would suffix a collision to key_2, detaching that field from
     * any answers already filed under the original key.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, list<string>>
     */
    private function duplicateKeyErrors(array $candidate): array
    {
        $errors = [];
        $seen = [];

        foreach ($this->walk($candidate) as [$path, $field]) {
            $key = $field['key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                $errors["{$path}.key"][] = "Duplicate field key [{$key}]. Every field key must be unique.";

                continue;
            }

            $seen[$key] = true;
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function fieldId(array $field): ?string
    {
        $id = $field['id'] ?? null;

        return is_string($id) && $id !== '' ? strtoupper($id) : null;
    }

    /**
     * Yield [dot path, field] for every field in a schema, tolerating the
     * malformed shapes an AI can produce.
     *
     * @param  array<string, mixed>  $schema
     * @return iterable<array{0: string, 1: array<string, mixed>}>
     */
    private function walk(array $schema): iterable
    {
        foreach ($schema['sections'] ?? [] as $sIndex => $section) {
            if (! is_array($section)) {
                continue;
            }

            foreach ($section['fields'] ?? [] as $fIndex => $field) {
                if (is_array($field)) {
                    yield ["sections.{$sIndex}.fields.{$fIndex}", $field];
                }
            }
        }
    }
}
