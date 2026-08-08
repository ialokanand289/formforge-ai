<?php

namespace App\Livewire\Forms;

use App\Enums\FieldType;
use App\Models\Form;
use App\Services\SchemaService;
use App\Services\SubmissionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Public renderer and submission endpoint for a published form.
 *
 * The component binds input, validates it against rules derived from the schema
 * in the database, and hands persistence to SubmissionService. It deliberately
 * owns no persistence logic of its own.
 *
 * The token is the only locked round tripping property, so the snapshot carries
 * no schema internals. The form, its schema, and its version are re-read from
 * the database on every request, which means nothing the browser sends can
 * influence which form is written to or which version is recorded.
 */
class PublicForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $token = '';

    /**
     * Visitor input. Unlocked by necessity, so never trusted: every key is
     * matched back against the schema before validation and persistence.
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    /**
     * Uploads keyed by field key. Slots stay null until a file arrives, because
     * Livewire appends to an array target instead of replacing it.
     *
     * @var array<string, mixed>
     */
    public array $files = [];

    #[Locked]
    public bool $submitted = false;

    #[Locked]
    public string $successMessage = '';

    #[Locked]
    public ?string $submitError = null;

    protected ?Form $form = null;

    /**
     * Normalized schema for the current request, or null when it is unusable.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $schema = null;

    protected bool $loaded = false;

    /**
     * Per-request memo of the presentation model.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $resolved = null;

    public function mount(string $token, SubmissionService $submissions): void
    {
        $this->token = $token;

        // Loading here turns a missing or unpublished form into a 404 before
        // the layout renders.
        $this->load();

        if ($this->schema !== null) {
            $this->values = $submissions->defaultValues($this->schema);
            $this->files = $submissions->defaultFiles($this->schema);
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function document(): array
    {
        return $this->resolve();
    }

    /**
     * Check an upload the moment it lands.
     *
     * Livewire's temporary upload endpoint has a far higher ceiling than the
     * per-field limits, so without this a visitor would wait for the whole file
     * to upload and only learn it was too large when they pressed submit. Text
     * inputs are left alone; validating them on every keystroke is noise.
     */
    public function updated(string $property, SubmissionService $submissions): void
    {
        if (! str_starts_with($property, 'files.')) {
            return;
        }

        $this->load();

        if ($this->schema === null) {
            return;
        }

        $set = $submissions->validationSetFor($this->schema);

        if (! isset($set['rules'][$property])) {
            return;
        }

        // Presence is settled at submit time, when the whole form is known.
        $rules = [$property => array_values(array_diff($set['rules'][$property], ['required']))];

        $this->validateOnly($property, $rules, $set['messages'], $set['attributes']);
    }

    public function submit(SubmissionService $submissions): void
    {
        $this->load();

        // An unusable schema has nothing to validate against, so there is
        // nothing safe to write.
        if ($this->form === null || $this->schema === null) {
            return;
        }

        $this->submitError = null;

        $ip = request()->ip();

        if (! $this->withinRateLimit($ip)) {
            return;
        }

        $this->values = $submissions->sanitizeValues($this->schema, $this->values);
        $this->files = $submissions->sanitizeFiles($this->schema, $this->files);

        // The presentation model is rebuilt from the sanitized input below.
        $this->resolved = null;

        $set = $submissions->validationSetFor($this->schema);

        // Throws on failure: the visitor stays on the form, sees field errors,
        // keeps their input, and nothing is written.
        $this->validate($set['rules'], $set['messages'], $set['attributes']);

        try {
            $submissions->create(
                $this->form,
                $this->schema,
                $this->values,
                $this->files,
                $ip,
                request()->userAgent(),
            );
        } catch (Throwable $exception) {
            Log::error('Public form submission failed.', [
                'form_id' => $this->form->id,
                'exception' => $exception,
            ]);

            $this->submitError = 'Something went wrong while saving your response. Please try again.';

            return;
        }

        $this->submitted = true;
        $this->successMessage = (string) ($this->schema['settings']['success_message'] ?? 'Thanks for your submission.');

        // Clear the answers so a refresh or a back button cannot resubmit them.
        $this->values = $submissions->defaultValues($this->schema);
        $this->files = $submissions->defaultFiles($this->schema);
        $this->resolved = null;
        $this->resetValidation();
    }

    /**
     * Throttle submissions per token and visitor.
     *
     * Every attempt counts, not just successful ones, because the work being
     * protected happens whether or not validation passes. The limiter is only
     * reachable from an already rendered form, so it cannot be used to probe
     * whether a token is real, and the message never varies with the token.
     */
    protected function withinRateLimit(?string $ip): bool
    {
        $max = (int) config('formforge.public.submit_rate_limit_per_minute', 10);

        if ($max <= 0) {
            return true;
        }

        // Hashed so no raw address reaches the cache.
        $key = 'form-submit:'.hash('sha256', $this->token.'|'.($ip ?? 'unknown'));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);

            $this->submitError = "Too many submissions. Please try again in {$seconds} seconds.";

            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    /**
     * Read the published form and its schema once per request.
     */
    protected function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        $form = Form::query()
            ->published()
            ->where('public_token', $this->token)
            ->first();

        // Unpublished forms 404 rather than 403, so a real token cannot be
        // told apart from a fake one.
        abort_if($form === null, 404);

        $schemaService = app(SchemaService::class);
        $schema = $schemaService->load($form);

        if (! $schemaService->isValid($schema)) {
            Log::error('Refused to render a published form with an invalid schema.', [
                'form_id' => $form->id,
            ]);

            $this->form = $form;

            return;
        }

        $this->form = $form;
        $this->schema = $schema;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $this->load();

        if ($this->schema === null) {
            return $this->resolved = [
                'unavailable' => true,
                'title' => '',
                'description' => '',
                'submitLabel' => 'Submit',
                'sections' => [],
                'hasFields' => false,
            ];
        }

        $schema = $this->schema;

        $sections = array_map(fn (array $section): array => [
            'title' => $section['title'],
            'description' => $section['description'],
            'fields' => array_map(
                fn (array $field): array => $this->presentField($field),
                $section['fields'] ?? []
            ),
        ], $schema['sections'] ?? []);

        return $this->resolved = [
            'unavailable' => false,
            'title' => $schema['title'],
            'description' => $schema['description'],
            'submitLabel' => $schema['settings']['submit_button_text'] ?? 'Submit',
            'sections' => $sections,
            'hasFields' => $this->hasSubmittableField($schema),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function hasSubmittableField(array $schema): bool
    {
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $type = FieldType::tryFrom((string) $field['type']);

                if ($type !== null && $type->isSubmittable()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    protected function presentField(array $field): array
    {
        $type = FieldType::tryFrom((string) $field['type']) ?? FieldType::Text;
        $validation = $field['validation'] ?? [];
        $key = (string) $field['key'];

        // Keyed on the field key, which normalize guarantees is unique, so no
        // internal identifier is exposed in the markup.
        $inputId = 'field-'.$key;

        $describedBy = [];
        if (($field['help_text'] ?? '') !== '') {
            $describedBy[] = $inputId.'-help';
        }

        $name = ($type === FieldType::File ? 'files.' : 'values.').$key;
        $invalid = $this->errorBagHas($name);

        if ($invalid) {
            $describedBy[] = $inputId.'-error';
        }

        return [
            'id' => $inputId,
            'key' => $key,
            'name' => $name,
            'invalid' => $invalid,
            'type' => $type->value,
            'label' => $field['label'],
            'placeholder' => $field['placeholder'] ?? '',
            'helpText' => $field['help_text'] ?? '',
            // Rendered from live state so a re-render after a validation error
            // gives the visitor their own input back, not the schema default.
            'value' => $this->currentValue($key, $type, $field),
            'required' => (bool) ($field['required'] ?? false),
            'options' => $field['options'] ?? [],
            'describedBy' => $describedBy === [] ? null : implode(' ', $describedBy),
            'min' => $validation['min'] ?? null,
            'max' => $validation['max'] ?? null,
            'minLength' => $validation['min_length'] ?? null,
            'maxLength' => $validation['max_length'] ?? null,
            'pattern' => $this->htmlPattern($validation['regex'] ?? null),
            'accept' => $this->acceptAttribute($validation['file_types'] ?? []),
            'maxFileSizeKb' => $this->maxFileSizeKb($validation),
            'ratingScale' => $this->ratingScale($validation),
        ];
    }

    /**
     * Checkbox members fail on an indexed key such as `values.topics.0`, so the
     * wildcard is checked alongside the field itself.
     */
    protected function errorBagHas(string $name): bool
    {
        $errors = $this->getErrorBag();

        return $errors->has($name) || $errors->has($name.'.*');
    }

    /**
     * @param  array<string, mixed>  $field
     * @return string|list<string>|null
     */
    protected function currentValue(string $key, FieldType $type, array $field): string|array|null
    {
        if ($type === FieldType::File) {
            return null;
        }

        $value = $this->values[$key] ?? $field['default'] ?? null;

        if ($type === FieldType::Checkbox) {
            if (! is_array($value)) {
                $value = is_scalar($value) && (string) $value !== '' ? [$value] : [];
            }

            return array_values(array_map(static fn ($item): string => (string) $item, array_filter(
                $value,
                static fn ($item): bool => is_scalar($item),
            )));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Translate a PCRE pattern into an HTML one, or drop it.
     *
     * HTML patterns are delimiter free and support no flags, so a pattern
     * carrying flags is skipped rather than silently misapplied. The
     * authoritative check happens server side at submission time.
     */
    protected function htmlPattern(mixed $regex): ?string
    {
        if (! is_string($regex) || $regex === '') {
            return null;
        }

        if (! str_starts_with($regex, '/')) {
            return $regex;
        }

        $closing = strrpos($regex, '/');

        if ($closing === false || $closing === 0) {
            return null;
        }

        // Anything after the closing delimiter is a flag HTML cannot honour.
        if ($closing !== strlen($regex) - 1) {
            return null;
        }

        $inner = substr($regex, 1, $closing - 1);

        return $inner === '' ? null : $inner;
    }

    protected function acceptAttribute(mixed $fileTypes): ?string
    {
        if (! is_array($fileTypes) || $fileTypes === []) {
            $fileTypes = config('formforge.uploads.allowed_mimes', []);
        }

        $extensions = [];

        foreach ($fileTypes as $extension) {
            if (! is_string($extension)) {
                continue;
            }

            $clean = preg_replace('/[^a-z0-9]/', '', Str::lower($extension)) ?? '';

            if ($clean !== '') {
                $extensions[] = '.'.$clean;
            }
        }

        return $extensions === [] ? null : implode(',', array_unique($extensions));
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    protected function maxFileSizeKb(array $validation): int
    {
        $configured = $validation['max_file_size_kb'] ?? null;

        return (int) ($configured ?: config('formforge.uploads.max_file_size_kb', 5120));
    }

    /**
     * @param  array<string, mixed>  $validation
     * @return list<int>
     */
    protected function ratingScale(array $validation): array
    {
        $min = (int) ($validation['min'] ?? 1);
        $max = (int) ($validation['max'] ?? 5);

        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        // Keep the rendered scale sane no matter what the schema asks for.
        $max = min($max, $min + 19);

        return range($min, $max);
    }

    #[Layout('layouts.public')]
    public function render(): View
    {
        return view('livewire.forms.public-form');
    }
}
