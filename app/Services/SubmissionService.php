<?php

namespace App\Services;

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\SubmissionFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Owns everything that happens after a public submission validates.
 *
 * The Livewire component binds and validates; this class projects the payload,
 * writes files, hashes the visitor IP, and keeps the database and the disk
 * consistent with each other.
 *
 * Nothing here trusts the browser. Values are matched against the schema loaded
 * from the database, and every column the visitor does not own — form id,
 * version, status, ip hash, user agent — is derived server side.
 */
class SubmissionService
{
    /** Matches the string column widths on form_submissions and submission_files. */
    private const MAX_ORIGINAL_NAME = 255;

    private const MAX_USER_AGENT = 255;

    /**
     * search_text is a MySQL TEXT column, so 65,535 bytes. Truncation is done in
     * bytes rather than characters because one character can cost four of them.
     */
    private const MAX_SEARCH_TEXT_BYTES = 60000;

    /**
     * Persist a validated submission along with its uploaded files.
     *
     * @param  array<string, mixed>  $schema  normalized schema read from the database
     * @param  array<string, mixed>  $values  sanitized non-file input keyed by field key
     * @param  array<string, UploadedFile|null>  $files  sanitized uploads keyed by field key
     */
    public function create(
        Form $form,
        array $schema,
        array $values,
        array $files,
        ?string $ip = null,
        ?string $userAgent = null,
    ): FormSubmission {
        $fields = $this->submittableFields($schema);

        // Generated up front so files can be written before the transaction and
        // still land in their final home.
        $submissionId = (string) Str::ulid();
        $stored = $this->storeFiles($form, $submissionId, $fields, $files);

        try {
            return DB::transaction(function () use ($form, $fields, $submissionId, $values, $files, $stored, $ip, $userAgent) {
                $payload = $this->payloadFor($fields, $values, $files);

                $submission = new FormSubmission;

                // HasUlids only generates a key when one is missing, and `id` is
                // not fillable, so the pre-generated id is assigned directly.
                $submission->id = $submissionId;
                $submission->fill([
                    'form_id' => $form->id,
                    'form_version' => (int) $form->schema_version,
                    'payload' => $payload,
                    'search_text' => $this->searchTextFor($fields, $payload),
                    'status' => SubmissionStatus::Completed,
                    'ip_hash' => $this->hashIp($ip),
                    'user_agent' => $this->truncate($userAgent, self::MAX_USER_AGENT),
                ]);
                $submission->save();

                foreach ($stored as $file) {
                    SubmissionFile::query()->create([
                        'form_submission_id' => $submissionId,
                        'field_key' => $file['field_key'],
                        'original_name' => $file['original_name'],
                        'disk' => $file['disk'],
                        'path' => $file['path'],
                        'mime_type' => $file['mime_type'],
                        'size' => $file['size'],
                        'created_at' => now(),
                    ]);
                }

                return $submission;
            });
        } catch (Throwable $exception) {
            // The filesystem cannot roll back with the transaction, so anything
            // already written is removed by hand before the failure surfaces.
            $this->discardFiles($stored);

            throw $exception;
        }
    }

    /**
     * Submittable fields keyed by schema field key, in document order.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    public function submittableFields(array $schema): array
    {
        $fields = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $type = FieldType::tryFrom((string) ($field['type'] ?? ''));
                $key = (string) ($field['key'] ?? '');

                if ($type === null || ! $type->isSubmittable() || $key === '') {
                    continue;
                }

                $fields[$key] = $field;
            }
        }

        return $fields;
    }

    /**
     * Seed the non-file inputs from the schema defaults.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function defaultValues(array $schema): array
    {
        $values = [];

        foreach ($this->submittableFields($schema) as $key => $field) {
            $type = $this->typeOf($field);

            if ($type === FieldType::File) {
                continue;
            }

            $default = $field['default'] ?? null;
            $default = is_scalar($default) ? (string) $default : '';

            $values[$key] = $type === FieldType::Checkbox
                ? ($default !== '' ? [$default] : [])
                : $default;
        }

        return $values;
    }

    /**
     * Seed the file slots as null.
     *
     * An array here would be treated as a multi-upload target by Livewire, which
     * appends rather than replaces, so every slot must start empty.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, null>
     */
    public function defaultFiles(array $schema): array
    {
        $files = [];

        foreach ($this->submittableFields($schema) as $key => $field) {
            if ($this->typeOf($field) === FieldType::File) {
                $files[$key] = null;
            }
        }

        return $files;
    }

    /**
     * Reduce client supplied input to what the schema actually declares.
     *
     * The bound properties are writable by the visitor, so unknown keys are
     * dropped and anything that is not a plain value is discarded rather than
     * coerced. That also neutralizes an upload injected into a text property.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function sanitizeValues(array $schema, array $values): array
    {
        $clean = [];

        foreach ($this->submittableFields($schema) as $key => $field) {
            $type = $this->typeOf($field);

            if ($type === FieldType::File) {
                continue;
            }

            $value = $values[$key] ?? null;

            if ($type === FieldType::Checkbox) {
                $clean[$key] = is_array($value)
                    ? array_values(array_map(
                        static fn ($item): string => (string) $item,
                        array_filter($value, static fn ($item): bool => is_scalar($item)),
                    ))
                    : [];

                continue;
            }

            $clean[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $clean;
    }

    /**
     * Keep only real uploads sitting on declared file fields.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $files
     * @return array<string, UploadedFile|null>
     */
    public function sanitizeFiles(array $schema, array $files): array
    {
        $clean = [];

        foreach ($this->submittableFields($schema) as $key => $field) {
            if ($this->typeOf($field) !== FieldType::File) {
                continue;
            }

            $file = $files[$key] ?? null;
            $clean[$key] = $file instanceof UploadedFile ? $file : null;
        }

        return $clean;
    }

    /**
     * Rules, messages, and attributes from ValidationService, remapped onto the
     * bound property paths.
     *
     * The service keys everything on the bare field key; the component binds
     * through `values.*` and `files.*`. This is key namespacing, not a second
     * schema-to-rules mapping.
     *
     * @param  array<string, mixed>  $schema
     * @return array{rules: array<string, list<string>>, messages: array<string, string>, attributes: array<string, string>}
     */
    public function validationSetFor(array $schema): array
    {
        $validation = app(ValidationService::class);
        $fields = $this->submittableFields($schema);

        $rules = [];
        foreach ($validation->rulesFromSchema($schema) as $key => $rule) {
            $prefixed = $this->prefixKey($key, $fields);

            if ($prefixed !== null) {
                $rules[$prefixed] = $rule;
            }
        }

        $messages = [];
        foreach ($validation->messagesFromSchema($schema) as $key => $message) {
            $prefixed = $this->prefixKey($key, $fields);

            if ($prefixed !== null) {
                $messages[$prefixed] = $message;
            }
        }

        $attributes = [];
        foreach ($validation->attributesFromSchema($schema) as $key => $label) {
            $prefixed = $this->prefixKey($key, $fields);

            if ($prefixed !== null) {
                $attributes[$prefixed] = $label;
            }
        }

        return [
            'rules' => $rules,
            'messages' => $messages,
            'attributes' => $attributes,
        ];
    }

    /**
     * Move `email`, `topics.*`, or `resume.mimes` onto the matching bound path.
     *
     * Field keys are `[a-z0-9_]+` by schema guarantee, so the first dot always
     * separates the key from whatever the validator appended. Keys with no
     * submittable field behind them, such as headings, are dropped.
     *
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function prefixKey(string $key, array $fields): ?string
    {
        [$field, $rest] = array_pad(explode('.', $key, 2), 2, null);

        if (! isset($fields[$field])) {
            return null;
        }

        $prefix = $this->typeOf($fields[$field]) === FieldType::File ? 'files.' : 'values.';

        return $prefix.$field.($rest !== null ? '.'.$rest : '');
    }

    /**
     * Project submitted input onto the schema field keys.
     *
     * Labels, field ids, and internal metadata never become keys, and keys the
     * client invented never survive, because the loop is driven by the schema.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @param  array<string, UploadedFile|null>  $files
     * @return array<string, mixed>
     */
    private function payloadFor(array $fields, array $values, array $files): array
    {
        $payload = [];

        foreach ($fields as $key => $field) {
            $type = $this->typeOf($field);

            if ($type === FieldType::File) {
                $file = $files[$key] ?? null;

                // The readable name only; the disk, path, and id stay in
                // submission_files where they are not part of the answer.
                $payload[$key] = $file instanceof UploadedFile
                    ? $this->truncate($file->getClientOriginalName(), self::MAX_ORIGINAL_NAME)
                    : null;

                continue;
            }

            if ($type === FieldType::Checkbox) {
                $payload[$key] = is_array($values[$key] ?? null)
                    ? array_values(array_map(static fn ($item): string => (string) $item, $values[$key]))
                    : [];

                continue;
            }

            $value = $values[$key] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value === '') {
                $payload[$key] = null;

                continue;
            }

            $payload[$key] = match ($type) {
                FieldType::Rating => (int) $value,
                FieldType::Number => $this->numeric($value),
                default => $value,
            };
        }

        return $payload;
    }

    /**
     * Numeric strings become numbers so exports and reports do not have to
     * guess. Anything that slipped past validation is stored verbatim.
     */
    private function numeric(string $value): int|float|string
    {
        return is_numeric($value) ? $value + 0 : $value;
    }

    /**
     * Flatten the answers a human would search for into one normalized string.
     *
     * Choice fields contribute their label as well as their stored value, so
     * searching for what the visitor saw on screen finds the submission.
     * Ratings are excluded as bare digits match everything, files contribute
     * nothing because a path or a byte stream is not an answer, and headings
     * were never submitted in the first place.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $payload
     */
    private function searchTextFor(array $fields, array $payload): ?string
    {
        $parts = [];

        foreach ($fields as $key => $field) {
            $type = $this->typeOf($field);

            if (! $this->isSearchable($type)) {
                continue;
            }

            $value = $payload[$key] ?? null;

            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item === null || $item === '') {
                    continue;
                }

                $parts[] = (string) $item;

                if ($type->requiresOptions()) {
                    $label = $this->optionLabel($field, (string) $item);

                    if ($label !== null) {
                        $parts[] = $label;
                    }
                }
            }
        }

        $text = Str::squish(implode(' ', $parts));

        return $text === '' ? null : mb_strcut($text, 0, self::MAX_SEARCH_TEXT_BYTES);
    }

    private function isSearchable(FieldType $type): bool
    {
        return in_array($type, [
            FieldType::Text,
            FieldType::Textarea,
            FieldType::Email,
            FieldType::Phone,
            FieldType::Number,
            FieldType::Date,
            FieldType::Dropdown,
            FieldType::Radio,
            FieldType::Checkbox,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function optionLabel(array $field, string $value): ?string
    {
        foreach ($field['options'] ?? [] as $option) {
            if (is_array($option) && (string) ($option['value'] ?? '') === $value) {
                $label = (string) ($option['label'] ?? '');

                return $label !== '' && $label !== $value ? $label : null;
            }
        }

        return null;
    }

    /**
     * Write every upload to the private disk before any row exists.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, UploadedFile|null>  $files
     * @return list<array<string, mixed>>
     */
    private function storeFiles(Form $form, string $submissionId, array $fields, array $files): array
    {
        $disk = $this->disk();
        $directory = trim((string) config('formforge.uploads.submission_dir', 'submissions'), '/')
            .'/'.$form->id.'/'.$submissionId;

        $stored = [];

        try {
            foreach ($fields as $key => $field) {
                if ($this->typeOf($field) !== FieldType::File) {
                    continue;
                }

                $file = $files[$key] ?? null;

                if (! $file instanceof UploadedFile) {
                    continue;
                }

                // The visitor's filename never becomes a path component.
                $name = (string) Str::ulid().$this->extensionFor($file);

                $stored[] = [
                    'field_key' => $key,
                    'original_name' => $this->truncate($file->getClientOriginalName(), self::MAX_ORIGINAL_NAME) ?? 'upload',
                    'disk' => $disk,
                    'path' => $file->storeAs($directory, $name, ['disk' => $disk]),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ];
            }
        } catch (Throwable $exception) {
            $this->discardFiles($stored);

            throw $exception;
        }

        return $stored;
    }

    private function extensionFor(UploadedFile $file): string
    {
        $extension = '';

        try {
            $extension = (string) $file->extension();
        } catch (Throwable) {
            // Fall through to the client supplied extension.
        }

        if ($extension === '') {
            $extension = $file->getClientOriginalExtension();
        }

        $extension = preg_replace('/[^a-z0-9]/', '', Str::lower($extension)) ?? '';

        return $extension === '' ? '' : '.'.$extension;
    }

    /**
     * @param  list<array<string, mixed>>  $stored
     */
    private function discardFiles(array $stored): void
    {
        foreach ($stored as $file) {
            try {
                Storage::disk($file['disk'])->delete($file['path']);
            } catch (Throwable) {
                // Cleanup is best effort; the original failure is what matters.
            }
        }
    }

    /**
     * The disk submissions are written to, refusing anything world readable.
     */
    private function disk(): string
    {
        $disk = (string) config('formforge.uploads.disk', 'local');

        $isPublic = $disk === 'public'
            || config("filesystems.disks.{$disk}.visibility") === 'public';

        if ($isPublic) {
            throw new RuntimeException("Submission uploads cannot be stored on the public disk [{$disk}].");
        }

        return $disk;
    }

    /**
     * Deterministic, non-reversible visitor fingerprint.
     *
     * A bare sha256 of an IPv4 address is brute forceable in seconds, so the
     * application key is used as the HMAC secret. Rotating APP_KEY starts a new
     * hash epoch, which only affects grouping. The raw address is never stored.
     */
    private function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    private function truncate(?string $value, int $bytes): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_strcut($value, 0, $bytes);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function typeOf(array $field): FieldType
    {
        return FieldType::tryFrom((string) ($field['type'] ?? '')) ?? FieldType::Text;
    }
}
