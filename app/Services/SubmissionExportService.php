<?php

namespace App\Services;

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a form's submissions as CSV.
 *
 * Columns are derived from the schema, never hardcoded, and are the union of
 * the current schema, the historical versions the submissions were answered
 * against, and any payload key those two miss. That union exists so an answer
 * to a since-deleted field still reaches the owner instead of disappearing.
 *
 * This class is a read path. It performs no insert, update, or delete, holds
 * the normalized schema in a local variable only, and never writes a repaired
 * schema back over a stored one.
 */
class SubmissionExportService
{
    /** Excel only detects UTF-8 when the file opens with a byte order mark. */
    private const BOM = "\xEF\xBB\xBF";

    private const METADATA_HEADERS = ['Submitted At', 'Status', 'Form Version'];

    /**
     * Leading characters a spreadsheet would treat as the start of a formula.
     */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(private readonly SchemaService $schema) {}

    /**
     * The ordered column plan for a form.
     *
     * @return list<array{key: string, label: string, type: FieldType|null, options: array<string, string>}>
     */
    public function columnsFor(Form $form): array
    {
        $columns = [];

        // 1. The current schema, normalized, because that is exactly the view
        //    SubmissionService used when it wrote the payload keys.
        foreach ($this->currentFields($form) as $field) {
            $columns[$field['key']] = $field;
        }

        // 2. Snapshots of the versions these submissions were answered against.
        //    Read raw: normalizing a historical snapshot can rewrite its keys,
        //    collapse an unknown type, and drop options, which would silently
        //    detach a column from the payload it is meant to describe.
        foreach ($this->historicalSnapshots($form) as $snapshot) {
            foreach ($this->readSnapshotFields($snapshot) as $field) {
                if (isset($columns[$field['key']])) {
                    continue;
                }

                $field['label'] = $field['label'].' (removed)';
                $columns[$field['key']] = $field;
            }
        }

        // 3. Anything still unaccounted for, recovered from the payloads. A form
        //    that never left version 1 has no snapshot to consult, so without
        //    this a field deleted back then would be unrecoverable.
        foreach ($this->orphanPayloadKeys($form, array_keys($columns)) as $key) {
            $columns[$key] = [
                'key' => $key,
                'label' => $key.' (removed)',
                'type' => null,
                'options' => [],
            ];
        }

        return array_values($columns);
    }

    public function download(Form $form): StreamedResponse
    {
        $columns = $this->columnsFor($form);

        return response()->streamDownload(function () use ($form, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, self::BOM);

            $this->writeRow($handle, array_merge(
                self::METADATA_HEADERS,
                // Labels are owner authored, so headers are guarded too.
                array_map(fn (array $column): string => $this->guard($column['label']), $columns),
            ));

            $this->submissions($form)->each(function (FormSubmission $submission) use ($handle, $columns) {
                $this->writeRow($handle, $this->rowFor($submission, $columns));
            });

            fclose($handle);
        }, $this->filenameFor($form), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Submissions in chronological order, one chunk in memory at a time.
     *
     * ULIDs are time ordered, so the primary key doubles as the cursor. Only
     * the columns the CSV needs are selected; search_text can run to tens of
     * kilobytes per row and plays no part in the export.
     *
     * @return LazyCollection<int, FormSubmission>
     */
    private function submissions(Form $form): LazyCollection
    {
        return FormSubmission::query()
            ->where('form_id', $form->id)
            ->select(['id', 'created_at', 'status', 'form_version', 'payload'])
            ->orderBy('id')
            ->lazyById($this->chunkSize());
    }

    /**
     * @return list<array{key: string, label: string, type: FieldType|null, options: array<string, string>}>
     */
    private function currentFields(Form $form): array
    {
        // Held locally and never assigned back to the model: the repaired copy
        // must not become the stored schema.
        $schema = $this->schema->load($form);

        $fields = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $type = FieldType::tryFrom((string) ($field['type'] ?? ''));
                $key = (string) ($field['key'] ?? '');

                if ($type === null || ! $type->isSubmittable() || $key === '') {
                    continue;
                }

                $fields[] = [
                    'key' => $key,
                    'label' => (string) ($field['label'] ?? $key),
                    'type' => $type,
                    'options' => $this->readOptions($field['options'] ?? []),
                ];
            }
        }

        return $fields;
    }

    /**
     * Stored schemas for the versions this form's submissions reference.
     *
     * @return Collection<int, mixed>
     */
    private function historicalSnapshots(Form $form): Collection
    {
        $versions = FormSubmission::query()
            ->where('form_id', $form->id)
            ->distinct()
            ->orderBy('form_version')
            ->pluck('form_version');

        if ($versions->isEmpty()) {
            return collect();
        }

        return FormVersion::query()
            ->where('form_id', $form->id)
            ->whereIn('version', $versions)
            ->orderBy('version')
            ->pluck('schema');
    }

    /**
     * Read a historical snapshot without repairing it.
     *
     * Only the minimum needed for a column is derived, and nothing is invented.
     * A field with no usable key is skipped rather than given one, because a
     * fabricated key can never match a payload and the orphan pass will recover
     * the answer anyway.
     *
     * @return list<array{key: string, label: string, type: FieldType|null, options: array<string, string>}>
     */
    private function readSnapshotFields(mixed $snapshot): array
    {
        if (! is_array($snapshot)) {
            return [];
        }

        $fields = [];

        foreach ($snapshot['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }

            foreach ($section['fields'] ?? [] as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $key = $field['key'] ?? null;

                if (! is_string($key) || trim($key) === '') {
                    continue;
                }

                // An unrecognized legacy type stays unrecognized and formats as
                // plain text; it is not rewritten to text.
                $type = FieldType::tryFrom((string) ($field['type'] ?? ''));

                if ($type === FieldType::Heading) {
                    continue;
                }

                $label = $field['label'] ?? null;

                $fields[] = [
                    'key' => $key,
                    'label' => is_string($label) && trim($label) !== '' ? trim($label) : $key,
                    'type' => $type,
                    'options' => $this->readOptions($field['options'] ?? []),
                ];
            }
        }

        return $fields;
    }

    /**
     * Value to label map, read tolerantly so no historical option is lost.
     *
     * @return array<string, string>
     */
    private function readOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $map = [];

        foreach ($options as $option) {
            if (is_array($option)) {
                if (! isset($option['value']) || ! is_scalar($option['value'])) {
                    continue;
                }

                $value = (string) $option['value'];
                $label = $option['label'] ?? null;

                $map[$value] = is_scalar($label) && (string) $label !== '' ? (string) $label : $value;

                continue;
            }

            if (is_scalar($option) && (string) $option !== '') {
                $map[(string) $option] = (string) $option;
            }
        }

        return $map;
    }

    /**
     * Payload keys no column covers yet, in first seen order.
     *
     * @param  list<string>  $known
     * @return list<string>
     */
    private function orphanPayloadKeys(Form $form, array $known): array
    {
        $known = array_flip($known);
        $orphans = [];

        FormSubmission::query()
            ->where('form_id', $form->id)
            ->select(['id', 'payload'])
            ->orderBy('id')
            ->lazyById($this->chunkSize())
            ->each(function (FormSubmission $submission) use ($known, &$orphans) {
                foreach (array_keys((array) $submission->payload) as $key) {
                    $key = (string) $key;

                    if (! isset($known[$key])) {
                        $orphans[$key] = true;
                    }
                }
            });

        return array_keys($orphans);
    }

    /**
     * @param  list<array{key: string, label: string, type: FieldType|null, options: array<string, string>}>  $columns
     * @return list<string>
     */
    private function rowFor(FormSubmission $submission, array $columns): array
    {
        $payload = (array) $submission->payload;

        $row = [
            $submission->created_at?->utc()->format('Y-m-d H:i:s') ?? '',
            $submission->status->value,
            (string) $submission->form_version,
        ];

        foreach ($columns as $column) {
            $row[] = $this->cellFor($column, $payload[$column['key']] ?? null);
        }

        return $row;
    }

    /**
     * @param  array{key: string, label: string, type: FieldType|null, options: array<string, string>}  $column
     */
    private function cellFor(array $column, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        if (is_array($value)) {
            return $this->guard($this->choiceList($column['options'], $value));
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            // Numbers skip the formula guard, so a negative number stays a
            // number instead of turning into text.
            return (string) $value;
        }

        if (! is_scalar($value)) {
            return $this->guard((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $value = (string) $value;

        // Choice fields store the value; the owner wants to read the label.
        if ($this->isChoice($column['type'])) {
            $value = $column['options'][$value] ?? $value;
        }

        return $this->guard($value);
    }

    private function isChoice(?FieldType $type): bool
    {
        return $type !== null && $type->requiresOptions();
    }

    /**
     * Selected labels in schema option order, with unrecognized stored values
     * appended, so the same data always produces the same cell.
     *
     * @param  array<string, string>  $options
     * @param  array<int|string, mixed>  $values
     */
    private function choiceList(array $options, array $values): string
    {
        $selected = [];

        foreach ($values as $value) {
            if (is_scalar($value)) {
                $selected[] = (string) $value;
            }
        }

        $ordered = [];

        foreach ($options as $value => $label) {
            // Numeric option values come back as integer array keys.
            if (in_array((string) $value, $selected, true)) {
                $ordered[] = $label;
            }
        }

        foreach ($selected as $value) {
            if (! array_key_exists($value, $options)) {
                $ordered[] = $value;
            }
        }

        return implode(', ', $ordered);
    }

    /**
     * Neutralize spreadsheet formula injection.
     *
     * A leading apostrophe makes Excel and Sheets treat the cell as text. This
     * deliberately alters the exported value; the alternative is a CSV that
     * executes whatever a visitor typed into a public form.
     */
    private function guard(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return in_array($value[0], self::FORMULA_PREFIXES, true) ? "'".$value : $value;
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $cells
     */
    private function writeRow($handle, array $cells): void
    {
        // An empty escape string turns off PHP's non-standard backslash
        // handling, which otherwise mangles any value ending in a backslash.
        // CRLF keeps embedded newlines readable in Excel.
        fputcsv($handle, $cells, ',', '"', '', "\r\n");
    }

    private function filenameFor(Form $form): string
    {
        $slug = Str::slug((string) ($form->slug ?: $form->title)) ?: 'form';

        return $slug.'-submissions-'.now()->format('Y-m-d').'.csv';
    }

    private function chunkSize(): int
    {
        return max(1, (int) config('formforge.export.csv_chunk_size', 200));
    }
}
