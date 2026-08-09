<?php

namespace App\Services;

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchemaService
{
    public function blank(string $title = 'Untitled Form', string $description = ''): array
    {
        return $this->normalize([
            'schema_version' => 1,
            'title' => $title,
            'description' => $description,
            'settings' => [
                'multi_step' => false,
                'submit_button_text' => 'Submit',
                'success_message' => 'Thanks for your submission.',
            ],
            'sections' => [
                [
                    'title' => 'Section 1',
                    'description' => null,
                    'fields' => [],
                ],
            ],
        ]);
    }

    public function load(Form $form): array
    {
        return $this->normalize($form->schema ?? $this->blank($form->title ?: 'Untitled Form'));
    }

    public function normalize(array $schema): array
    {
        $title = $this->trimString($schema['title'] ?? 'Untitled Form');
        if ($title === '') {
            $title = 'Untitled Form';
        }

        $settings = is_array($schema['settings'] ?? null) ? $schema['settings'] : [];

        $normalized = [
            'schema_version' => (int) ($schema['schema_version'] ?? 1),
            'title' => $title,
            'description' => $this->trimString($schema['description'] ?? ''),
            'settings' => [
                'multi_step' => (bool) ($settings['multi_step'] ?? false),
                'submit_button_text' => $this->trimString($settings['submit_button_text'] ?? $settings['submit_button'] ?? 'Submit') ?: 'Submit',
                'success_message' => $this->trimString($settings['success_message'] ?? 'Thanks for your submission.') ?: 'Thanks for your submission.',
            ],
            'sections' => [],
        ];

        $sections = is_array($schema['sections'] ?? null) ? $schema['sections'] : [];
        $usedKeys = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $sectionId = $this->normalizeId($section['id'] ?? null);
            $fields = [];

            foreach (($section['fields'] ?? []) as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $normalizedField = $this->normalizeField($field, $usedKeys);
                $usedKeys[] = $normalizedField['key'];
                $fields[] = $normalizedField;
            }

            $normalized['sections'][] = [
                'id' => $sectionId,
                'title' => $this->trimString($section['title'] ?? 'Untitled Section') ?: 'Untitled Section',
                'description' => $this->nullableTrimmedString($section['description'] ?? null),
                'fields' => $fields,
            ];
        }

        return $normalized;
    }

    public function isValid(array $schema): bool
    {
        try {
            $this->assertValid($schema);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function assertValid(array $schema): void
    {
        $errors = $this->validationErrors($schema);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function validationErrors(array $schema): array
    {
        $errors = [];

        foreach (['schema_version', 'title', 'settings', 'sections'] as $root) {
            if (! array_key_exists($root, $schema)) {
                $errors[$root][] = "The {$root} key is required.";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        if (! is_int($schema['schema_version']) && ! ctype_digit((string) $schema['schema_version'])) {
            $errors['schema_version'][] = 'The schema_version must be an integer.';
        }

        if (! is_string($schema['title']) || trim($schema['title']) === '') {
            $errors['title'][] = 'The title must be a non-empty string.';
        }

        if (! is_array($schema['settings'])) {
            $errors['settings'][] = 'The settings must be an object.';
        }

        if (! is_array($schema['sections'])) {
            $errors['sections'][] = 'The sections must be an array.';

            return $errors;
        }

        $sectionIds = [];
        $fieldIds = [];
        $fieldKeys = [];
        $allKeys = [];

        foreach ($schema['sections'] as $sIndex => $section) {
            $sectionPath = "sections.{$sIndex}";

            if (! is_array($section)) {
                $errors[$sectionPath][] = 'Each section must be an object.';

                continue;
            }

            $sectionId = (string) ($section['id'] ?? '');
            if ($sectionId === '') {
                $errors["{$sectionPath}.id"][] = 'Section id is required.';
            } elseif (in_array($sectionId, $sectionIds, true)) {
                $errors["{$sectionPath}.id"][] = 'Duplicate section id.';
            } else {
                $sectionIds[] = $sectionId;
            }

            if (! is_array($section['fields'] ?? null)) {
                $errors["{$sectionPath}.fields"][] = 'Section fields must be an array.';

                continue;
            }

            foreach ($section['fields'] as $fIndex => $field) {
                $fieldPath = "{$sectionPath}.fields.{$fIndex}";

                if (! is_array($field)) {
                    $errors[$fieldPath][] = 'Each field must be an object.';

                    continue;
                }

                $fieldId = (string) ($field['id'] ?? '');
                if ($fieldId === '') {
                    $errors["{$fieldPath}.id"][] = 'Field id is required.';
                } elseif (in_array($fieldId, $fieldIds, true)) {
                    $errors["{$fieldPath}.id"][] = 'Duplicate field id.';
                } else {
                    $fieldIds[] = $fieldId;
                }

                $key = (string) ($field['key'] ?? '');
                if ($key === '' || ! preg_match('/^[a-z0-9_]+$/', $key)) {
                    $errors["{$fieldPath}.key"][] = 'Field key must match [a-z0-9_]+.';
                } elseif (in_array($key, $fieldKeys, true)) {
                    $errors["{$fieldPath}.key"][] = 'Duplicate field key.';
                } else {
                    $fieldKeys[] = $key;
                    $allKeys[] = $key;
                }

                $type = FieldType::tryFrom((string) ($field['type'] ?? ''));
                if ($type === null) {
                    $errors["{$fieldPath}.type"][] = 'Unsupported field type.';

                    continue;
                }

                $options = $field['options'] ?? [];
                if (! is_array($options)) {
                    $errors["{$fieldPath}.options"][] = 'Field options must be an array.';
                } elseif ($type->requiresOptions()) {
                    if ($options === []) {
                        $errors["{$fieldPath}.options"][] = 'Dropdown, radio, and checkbox fields require at least one option.';
                    } else {
                        foreach ($options as $oIndex => $option) {
                            if (! is_array($option)
                                || ! array_key_exists('value', $option)
                                || ! array_key_exists('label', $option)
                                || $option['value'] === null
                                || $option['label'] === null
                                || trim((string) $option['value']) === ''
                                || trim((string) $option['label']) === ''
                            ) {
                                $errors["{$fieldPath}.options.{$oIndex}"][] = 'Each option must include non-empty value and label.';
                            }
                        }
                    }
                }

                $validation = $field['validation'] ?? [];
                if (! is_array($validation)) {
                    $errors["{$fieldPath}.validation"][] = 'Field validation must be an object.';
                } else {
                    $regex = $validation['regex'] ?? null;
                    if ($regex !== null && $regex !== '') {
                        if (! is_string($regex) || @preg_match($regex, '') === false) {
                            $errors["{$fieldPath}.validation.regex"][] = 'Invalid regex definition.';
                        }
                    }
                }

                $conditions = $field['conditions'] ?? [];
                if (! is_array($conditions)) {
                    $errors["{$fieldPath}.conditions"][] = 'Field conditions must be an array.';
                }
            }
        }

        // Second pass: condition field_key references
        foreach ($schema['sections'] as $sIndex => $section) {
            if (! is_array($section) || ! is_array($section['fields'] ?? null)) {
                continue;
            }

            foreach ($section['fields'] as $fIndex => $field) {
                if (! is_array($field) || ! is_array($field['conditions'] ?? null)) {
                    continue;
                }

                foreach ($field['conditions'] as $cIndex => $condition) {
                    if (! is_array($condition)) {
                        continue;
                    }

                    $ref = (string) ($condition['field_key'] ?? '');
                    if ($ref !== '' && ! in_array($ref, $allKeys, true)) {
                        $errors["sections.{$sIndex}.fields.{$fIndex}.conditions.{$cIndex}.field_key"][] =
                            "Condition references unknown field key [{$ref}].";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Compare two schemas structurally, without touching either one.
     *
     * Called as diff($historical, $current), so "added" reads as "added since
     * that version". Neither argument goes through normalize(): a stored
     * snapshot has to be reported exactly as it was written, and repairing it
     * on the way past would describe a document nobody saved.
     *
     * Entries are matched on their id where one is present, falling back to the
     * field key or the section title. That fallback is what makes a legacy
     * snapshot comparable at all, since ids were not always written. A pair
     * matched by key cannot report a key change, because a renamed key with no
     * id is indistinguishable from a removal plus an addition.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>
     */
    public function diff(array $from, array $to): array
    {
        $sections = $this->diffSections(
            $this->comparableList($from['sections'] ?? null),
            $this->comparableList($to['sections'] ?? null),
        );

        $summary = [
            'sections_added' => 0,
            'sections_removed' => 0,
            'sections_changed' => 0,
            'fields_added' => 0,
            'fields_removed' => 0,
            'fields_changed' => 0,
        ];

        foreach ($sections as $section) {
            if ($section['status'] !== 'unchanged') {
                $summary['sections_'.$section['status']]++;
            }

            foreach ($section['fields'] as $field) {
                if ($field['status'] !== 'unchanged') {
                    $summary['fields_'.$field['status']]++;
                }
            }
        }

        $title = $this->diffValue($from['title'] ?? null, $to['title'] ?? null, 'string');
        $description = $this->diffValue($from['description'] ?? null, $to['description'] ?? null, 'string');

        $summary['has_changes'] = $title['changed']
            || $description['changed']
            || array_sum($summary) > 0;

        return [
            'title' => $title,
            'description' => $description,
            'sections' => $sections,
            'summary' => $summary,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     * @return list<array<string, mixed>>
     */
    private function diffSections(array $from, array $to): array
    {
        $fromById = $this->keyByIdentity($from, 'title');
        $toById = $this->keyByIdentity($to, 'title');

        $result = [];

        // Present order first, so the reader sees the schema as it is now.
        foreach ($toById as $identity => $section) {
            $previous = $fromById[$identity] ?? null;

            if ($previous === null) {
                $result[] = $this->wholeSection($section, 'added');

                continue;
            }

            $changes = array_filter([
                'title' => $this->changeOrNull($previous['title'] ?? null, $section['title'] ?? null, 'string'),
                'description' => $this->changeOrNull($previous['description'] ?? null, $section['description'] ?? null, 'string'),
            ]);

            $fields = $this->diffFields(
                $this->comparableList($previous['fields'] ?? null),
                $this->comparableList($section['fields'] ?? null),
            );

            $touched = $changes !== [] || $this->anyChanged($fields);

            $result[] = [
                'status' => $touched ? 'changed' : 'unchanged',
                'title' => $this->displayString($section['title'] ?? null, 'Untitled Section'),
                'changes' => $changes,
                'fields' => $fields,
            ];
        }

        foreach ($fromById as $identity => $section) {
            if (! isset($toById[$identity])) {
                $result[] = $this->wholeSection($section, 'removed');
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     * @return list<array<string, mixed>>
     */
    private function diffFields(array $from, array $to): array
    {
        $fromById = $this->keyByIdentity($from, 'key');
        $toById = $this->keyByIdentity($to, 'key');

        $result = [];

        foreach ($toById as $identity => $field) {
            $previous = $fromById[$identity] ?? null;

            if ($previous === null) {
                $result[] = $this->wholeField($field, 'added');

                continue;
            }

            $changes = [];

            foreach (self::COMPARED_FIELD_PROPERTIES as $property => $shape) {
                $change = $this->changeOrNull($previous[$property] ?? null, $field[$property] ?? null, $shape);

                if ($change !== null) {
                    $changes[$property] = $change;
                }
            }

            $result[] = [
                'status' => $changes === [] ? 'unchanged' : 'changed',
                'label' => $this->displayString($field['label'] ?? null, 'Untitled Field'),
                'key' => $this->displayString($field['key'] ?? null, ''),
                'changes' => $changes,
            ];
        }

        foreach ($fromById as $identity => $field) {
            if (! isset($toById[$identity])) {
                $result[] = $this->wholeField($field, 'removed');
            }
        }

        return $result;
    }

    /**
     * The properties a field change is reported against.
     */
    private const COMPARED_FIELD_PROPERTIES = [
        'label' => 'string',
        'key' => 'string',
        'type' => 'string',
        'placeholder' => 'string',
        'help_text' => 'string',
        'default' => 'raw',
        'required' => 'bool',
        'options' => 'list',
        'validation' => 'list',
        'conditions' => 'list',
    ];

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function wholeSection(array $section, string $status): array
    {
        return [
            'status' => $status,
            'title' => $this->displayString($section['title'] ?? null, 'Untitled Section'),
            'changes' => [],
            'fields' => array_map(
                fn (array $field): array => $this->wholeField($field, $status),
                $this->comparableList($section['fields'] ?? null),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function wholeField(array $field, string $status): array
    {
        return [
            'status' => $status,
            'label' => $this->displayString($field['label'] ?? null, 'Untitled Field'),
            'key' => $this->displayString($field['key'] ?? null, ''),
            'changes' => [],
        ];
    }

    /**
     * Drop anything that is not an array, so a malformed entry cannot be
     * dereferenced further down.
     *
     * @return list<array<string, mixed>>
     */
    private function comparableList(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        return array_values(array_filter($entries, 'is_array'));
    }

    /**
     * Index entries by their matching identity, preserving document order.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, array<string, mixed>>
     */
    private function keyByIdentity(array $entries, string $fallbackProperty): array
    {
        $keyed = [];

        foreach ($entries as $index => $entry) {
            $id = $entry['id'] ?? null;

            if (is_string($id) && trim($id) !== '') {
                $identity = 'id:'.Str::upper(trim($id));
            } else {
                $fallback = $entry[$fallbackProperty] ?? null;
                $identity = is_string($fallback) && trim($fallback) !== ''
                    ? $fallbackProperty.':'.Str::lower(trim($fallback))
                    : 'index:'.$index;
            }

            // A repeated identity would silently swallow an entry, so later
            // duplicates fall back to their position.
            if (isset($keyed[$identity])) {
                $identity .= '#'.$index;
            }

            $keyed[$identity] = $entry;
        }

        return $keyed;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function anyChanged(array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($entry['status'] !== 'unchanged') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{from: mixed, to: mixed}|null
     */
    private function changeOrNull(mixed $from, mixed $to, string $shape): ?array
    {
        $change = $this->diffValue($from, $to, $shape);

        return $change['changed'] ? ['from' => $change['from'], 'to' => $change['to']] : null;
    }

    /**
     * Compare one property, coercing only for the comparison itself.
     *
     * A legacy snapshot may hold null where the current schema holds an empty
     * string, or 1 where it holds true. Those are the same answer written two
     * ways, and reporting them as edits would bury the real changes.
     *
     * @return array{from: mixed, to: mixed, changed: bool}
     */
    private function diffValue(mixed $from, mixed $to, string $shape): array
    {
        $left = $this->comparable($from, $shape);
        $right = $this->comparable($to, $shape);

        return ['from' => $left, 'to' => $right, 'changed' => $left !== $right];
    }

    private function comparable(mixed $value, string $shape): mixed
    {
        return match ($shape) {
            'string' => is_scalar($value) ? (string) $value : '',
            'bool' => (bool) $value,
            'list' => is_array($value) ? $value : [],
            default => $value,
        };
    }

    private function displayString(mixed $value, string $fallback): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? $fallback : $text;
    }

    public function save(Form $form, array $schema, ?User $actor = null, ?string $note = null): Form
    {
        $schema = $this->normalize($schema);
        $this->assertValid($schema);

        return DB::transaction(function () use ($form, $schema, $actor, $note) {
            $nextVersion = ((int) $form->schema_version) + 1;

            $form->title = $schema['title'];
            $form->description = $schema['description'] !== '' ? $schema['description'] : null;
            $form->schema = $schema;
            $form->schema_version = $nextVersion;
            $form->save();

            FormVersion::query()->create([
                'form_id' => $form->id,
                'version' => $nextVersion,
                'schema' => $schema,
                'note' => $note,
                'created_by' => $actor?->id,
                'created_at' => now(),
            ]);

            return $form->refresh();
        });
    }

    public function addSection(array $schema, ?string $title = null): array
    {
        $schema = $this->normalize($schema);
        $schema['sections'][] = [
            'id' => (string) Str::ulid(),
            'title' => $title !== null && trim($title) !== '' ? trim($title) : 'Untitled Section',
            'description' => null,
            'fields' => [],
        ];

        return $this->normalize($schema);
    }

    public function removeSection(array $schema, string $sectionId): array
    {
        $schema = $this->normalize($schema);
        $schema['sections'] = array_values(array_filter(
            $schema['sections'],
            fn (array $section) => $section['id'] !== $sectionId
        ));

        return $schema;
    }

    public function addField(array $schema, string $sectionId, FieldType $type, array $attributes = []): array
    {
        $schema = $this->normalize($schema);
        $usedKeys = $this->collectKeys($schema);

        foreach ($schema['sections'] as &$section) {
            if ($section['id'] !== $sectionId) {
                continue;
            }

            $attributes['type'] = $type->value;
            $section['fields'][] = $this->normalizeField($attributes, $usedKeys);
            break;
        }
        unset($section);

        return $this->normalize($schema);
    }

    public function updateField(array $schema, string $fieldId, array $attributes): array
    {
        $schema = $this->normalize($schema);

        foreach ($schema['sections'] as &$section) {
            foreach ($section['fields'] as &$field) {
                if ($field['id'] !== $fieldId) {
                    continue;
                }

                $merged = array_merge($field, $attributes);
                $merged['id'] = $field['id'];
                $otherKeys = array_values(array_filter(
                    $this->collectKeys($schema),
                    fn (string $key) => $key !== $field['key']
                ));
                $field = $this->normalizeField($merged, $otherKeys);
                break 2;
            }
        }
        unset($section, $field);

        return $this->normalize($schema);
    }

    public function removeField(array $schema, string $fieldId): array
    {
        $schema = $this->normalize($schema);

        foreach ($schema['sections'] as &$section) {
            $section['fields'] = array_values(array_filter(
                $section['fields'],
                fn (array $field) => $field['id'] !== $fieldId
            ));
        }
        unset($section);

        return $schema;
    }

    public function moveField(array $schema, string $fieldId, string $toSectionId, int $position): array
    {
        $schema = $this->normalize($schema);
        $moving = null;

        foreach ($schema['sections'] as &$section) {
            foreach ($section['fields'] as $index => $field) {
                if ($field['id'] !== $fieldId) {
                    continue;
                }

                $moving = $field;
                unset($section['fields'][$index]);
                $section['fields'] = array_values($section['fields']);
                break 2;
            }
        }
        unset($section);

        if ($moving === null) {
            return $schema;
        }

        foreach ($schema['sections'] as &$section) {
            if ($section['id'] !== $toSectionId) {
                continue;
            }

            $position = max(0, min($position, count($section['fields'])));
            array_splice($section['fields'], $position, 0, [$moving]);
            break;
        }
        unset($section);

        return $this->normalize($schema);
    }

    public function duplicateField(array $schema, string $fieldId): array
    {
        $schema = $this->normalize($schema);
        $usedKeys = $this->collectKeys($schema);

        foreach ($schema['sections'] as &$section) {
            foreach ($section['fields'] as $index => $field) {
                if ($field['id'] !== $fieldId) {
                    continue;
                }

                $copy = $field;
                unset($copy['id']);
                $copy['key'] = $this->uniqueKey($field['key'].'_copy', $usedKeys);
                $copy['label'] = ($field['label'] ?? 'Field').' (Copy)';
                $normalized = $this->normalizeField($copy, $usedKeys);
                array_splice($section['fields'], $index + 1, 0, [$normalized]);
                break 2;
            }
        }
        unset($section);

        return $this->normalize($schema);
    }

    /**
     * @param  list<string>  $usedKeys
     * @return array<string, mixed>
     */
    private function normalizeField(array $field, array &$usedKeys): array
    {
        $type = FieldType::tryFrom((string) ($field['type'] ?? 'text')) ?? FieldType::Text;
        $label = $this->trimString($field['label'] ?? Str::headline($type->value));
        if ($label === '') {
            $label = Str::headline($type->value);
        }

        $baseKey = $this->slugifyKey((string) ($field['key'] ?? $label));
        if ($baseKey === '') {
            $baseKey = $type->value;
        }
        $key = $this->uniqueKey($baseKey, $usedKeys);
        $usedKeys[] = $key;

        $validationIn = is_array($field['validation'] ?? null) ? $field['validation'] : [];
        $fileTypes = $validationIn['file_types'] ?? [];
        if (! is_array($fileTypes)) {
            $fileTypes = [];
        }
        $fileTypes = array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? strtolower(trim($item)) : null,
            $fileTypes
        )));

        return [
            'id' => $this->normalizeId($field['id'] ?? null),
            'type' => $type->value,
            'key' => $key,
            'label' => $label,
            'placeholder' => $this->trimString($field['placeholder'] ?? ''),
            'help_text' => $this->trimString($field['help_text'] ?? ''),
            'default' => $field['default'] ?? null,
            'required' => (bool) ($field['required'] ?? false),
            'options' => $this->normalizeOptions($field['options'] ?? []),
            'validation' => [
                'min' => $this->nullableNumber($validationIn['min'] ?? null),
                'max' => $this->nullableNumber($validationIn['max'] ?? null),
                'min_length' => $this->nullableInt($validationIn['min_length'] ?? null),
                'max_length' => $this->nullableInt($validationIn['max_length'] ?? null),
                'regex' => $this->nullableTrimmedString($validationIn['regex'] ?? null),
                'file_types' => $fileTypes,
                'max_file_size_kb' => $this->nullableInt($validationIn['max_file_size_kb'] ?? null),
            ],
            'conditions' => is_array($field['conditions'] ?? null) ? array_values($field['conditions']) : [],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function normalizeOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $normalized = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            if (! array_key_exists('value', $option) || ! array_key_exists('label', $option)) {
                continue;
            }

            if ($option['value'] === null || $option['label'] === null) {
                continue;
            }

            $value = trim((string) $option['value']);
            $label = trim((string) $option['label']);

            if ($value === '' || $label === '') {
                continue;
            }

            $normalized[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function collectKeys(array $schema): array
    {
        $keys = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (isset($field['key'])) {
                    $keys[] = (string) $field['key'];
                }
            }
        }

        return $keys;
    }

    /**
     * @param  list<string>  $usedKeys
     */
    private function uniqueKey(string $base, array $usedKeys): string
    {
        $base = $this->slugifyKey($base) ?: 'field';
        $candidate = $base;
        $i = 2;

        while (in_array($candidate, $usedKeys, true)) {
            $candidate = $base.'_'.$i;
            $i++;
        }

        return $candidate;
    }

    private function slugifyKey(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value;
    }

    private function normalizeId(mixed $id): string
    {
        if (is_string($id) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id)) {
            return Str::upper($id);
        }

        return (string) Str::ulid();
    }

    private function trimString(mixed $value): string
    {
        return is_string($value) ? trim($value) : trim((string) ($value ?? ''));
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = $this->trimString($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? $value + 0 : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
