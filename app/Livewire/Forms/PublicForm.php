<?php

namespace App\Livewire\Forms;

use App\Enums\FieldType;
use App\Models\Form;
use App\Services\SchemaService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only renderer for a published form.
 *
 * The component performs one database read per request, the token lookup, and
 * no writes of any kind. SchemaService::load() normalizes on read, which
 * repairs legacy rows; that repair is held in memory and never written back.
 *
 * The token is the only public property, so the Livewire snapshot embedded in
 * the page stays small and carries no schema internals.
 */
class PublicForm extends Component
{
    #[Locked]
    public string $token = '';

    /**
     * Per-request memo so mount and render share a single query.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $resolved = null;

    public function mount(string $token): void
    {
        $this->token = $token;

        // Resolving here turns a missing or unpublished form into a 404 before
        // the layout renders.
        $this->resolve();
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
     * @return array<string, mixed>
     */
    protected function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

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

            return $this->resolved = [
                'unavailable' => true,
                'title' => '',
                'description' => '',
                'submitLabel' => 'Submit',
                'sections' => [],
                'hasFields' => false,
            ];
        }

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

        // Keyed on the field key, which normalize guarantees is unique, so no
        // internal identifier is exposed in the markup.
        $inputId = 'field-'.$field['key'];

        $describedBy = [];
        if (($field['help_text'] ?? '') !== '') {
            $describedBy[] = $inputId.'-help';
        }

        return [
            'id' => $inputId,
            'key' => $field['key'],
            'type' => $type->value,
            'label' => $field['label'],
            'placeholder' => $field['placeholder'] ?? '',
            'helpText' => $field['help_text'] ?? '',
            'default' => $this->defaultValue($field),
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
     * @param  array<string, mixed>  $field
     */
    protected function defaultValue(array $field): string
    {
        $default = $field['default'] ?? null;

        return is_scalar($default) ? (string) $default : '';
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
