<?php

namespace App\Livewire\Forms;

use App\Enums\FieldType;
use App\Models\Form;
use App\Services\SchemaService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

class FormBuilder extends Component
{
    use AuthorizesRequests;

    public Form $form;

    public string $title = '';

    public string $status = '';

    /**
     * In-memory working schema. Never persisted in this phase.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $schema = [];

    public ?string $selectedSectionId = null;

    public ?string $selectedFieldId = null;

    public ?string $schemaError = null;

    /**
     * Palette metadata, prepared here so Blade stays presentation only.
     *
     * @var list<array{type: string, label: string, description: string, icon: string}>
     */
    public array $paletteFields = [];

    /**
     * Editable mirror of the selected field, bound to the property editor.
     *
     * @var array<string, mixed>
     */
    public array $fieldForm = [];

    /**
     * Reserved for Phase 4D undo support. Not used in this phase.
     *
     * @var list<array<string, mixed>>
     */
    protected array $history = [];

    /**
     * Reserved for future autosave support. Not acted on in this phase.
     */
    protected bool $dirty = false;

    public function mount(Form $form, SchemaService $schemaService): void
    {
        $this->authorize('view', $form);

        $this->form = $form;
        $this->title = $form->title;
        $this->status = $form->status->value;
        $this->schema = $schemaService->blank($form->title);
        $this->paletteFields = $this->buildPalette();
        $this->selectedSectionId = $this->schema['sections'][0]['id'] ?? null;
        $this->loadFieldForm();
    }

    public function addSection(): void
    {
        $before = $this->sectionIds();

        $this->commit($this->schemaService()->addSection($this->schema));

        $added = array_values(array_diff($this->sectionIds(), $before));

        if ($added !== []) {
            $this->selectedSectionId = $added[0];
            $this->selectedFieldId = null;
        }

        $this->loadFieldForm();
    }

    public function removeSection(string $sectionId): void
    {
        $this->commit($this->schemaService()->removeSection($this->schema, $sectionId));
        $this->clearSelection();
        $this->loadFieldForm();
    }

    public function addField(string $type, ?string $sectionId = null): void
    {
        $fieldType = FieldType::tryFrom($type);

        if ($fieldType === null) {
            $this->schemaError = 'That field type is not supported.';

            return;
        }

        $targetId = $this->resolveTargetSection($sectionId);

        if ($targetId === null) {
            return;
        }

        $before = $this->fieldIds();

        $this->commit($this->schemaService()->addField(
            $this->schema,
            $targetId,
            $fieldType,
            $this->defaultAttributesFor($fieldType)
        ));

        $added = array_values(array_diff($this->fieldIds(), $before));

        if ($added !== []) {
            $this->selectedSectionId = $targetId;
            $this->selectedFieldId = $added[0];
        }

        $this->loadFieldForm();
    }

    public function removeField(string $fieldId): void
    {
        $this->commit($this->schemaService()->removeField($this->schema, $fieldId));
        $this->clearSelection();
        $this->loadFieldForm();
    }

    public function duplicateField(string $fieldId): void
    {
        $before = $this->fieldIds();

        $this->commit($this->schemaService()->duplicateField($this->schema, $fieldId));

        $added = array_values(array_diff($this->fieldIds(), $before));

        if ($added !== []) {
            $this->selectedFieldId = $added[0];
        }

        $this->loadFieldForm();
    }

    public function moveFieldUp(string $fieldId): void
    {
        $this->moveFieldBy($fieldId, -1);
    }

    public function moveFieldDown(string $fieldId): void
    {
        $this->moveFieldBy($fieldId, 1);
    }

    public function selectSection(string $sectionId): void
    {
        $this->selectedSectionId = $sectionId;
        $this->selectedFieldId = null;
        $this->loadFieldForm();
    }

    public function selectField(string $fieldId): void
    {
        $located = $this->locateField($fieldId);

        if ($located === null) {
            return;
        }

        $this->selectedSectionId = $located['sectionId'];
        $this->selectedFieldId = $fieldId;
        $this->loadFieldForm();
    }

    public function dismissSchemaError(): void
    {
        $this->schemaError = null;
    }

    public function updatedFieldForm(mixed $value, string $name): void
    {
        $this->applyFieldUpdate();
    }

    public function addOption(): void
    {
        if (! $this->selectedFieldSupportsOptions()) {
            return;
        }

        $next = count($this->fieldForm['options'] ?? []) + 1;

        $this->fieldForm['options'][] = [
            'value' => 'option_'.$next,
            'label' => 'Option '.$next,
        ];

        $this->applyFieldUpdate();
    }

    public function removeOption(int $index): void
    {
        if (! array_key_exists($index, $this->fieldForm['options'] ?? [])) {
            return;
        }

        unset($this->fieldForm['options'][$index]);
        $this->fieldForm['options'] = array_values($this->fieldForm['options']);

        $this->applyFieldUpdate();
    }

    public function moveOptionUp(int $index): void
    {
        $this->moveOptionBy($index, -1);
    }

    public function moveOptionDown(int $index): void
    {
        $this->moveOptionBy($index, 1);
    }

    /**
     * Push the edited properties into the schema through SchemaService.
     */
    protected function applyFieldUpdate(): void
    {
        $field = $this->selectedField();

        if ($field === null) {
            return;
        }

        $type = FieldType::tryFrom($field['type']) ?? FieldType::Text;

        try {
            $this->validate($this->rulesForFieldForm($type), [], $this->fieldFormAttributeNames());
        } catch (ValidationException $exception) {
            $this->loadFieldForm();

            throw $exception;
        }

        $this->commit($this->schemaService()->updateField(
            $this->schema,
            $this->selectedFieldId,
            $this->attributesForType($type)
        ));

        $this->loadFieldForm();
    }

    protected function moveOptionBy(int $index, int $offset): void
    {
        $options = $this->fieldForm['options'] ?? [];
        $target = $index + $offset;

        if (! array_key_exists($index, $options) || ! array_key_exists($target, $options)) {
            return;
        }

        [$options[$index], $options[$target]] = [$options[$target], $options[$index]];

        $this->fieldForm['options'] = array_values($options);

        $this->applyFieldUpdate();
    }

    /**
     * Mirror the selected field into the editable form, or empty it.
     */
    protected function loadFieldForm(): void
    {
        unset($this->fieldEditor);

        $field = $this->selectedField();

        if ($field === null) {
            $this->fieldForm = [];
            $this->resetErrorBag();

            return;
        }

        $validation = $field['validation'] ?? [];

        $this->fieldForm = [
            'label' => $field['label'],
            'key' => $field['key'],
            'placeholder' => $field['placeholder'] ?? '',
            'help_text' => $field['help_text'] ?? '',
            'default' => $field['default'] === null ? '' : (string) $field['default'],
            'required' => (bool) ($field['required'] ?? false),
            'min' => $this->formNumber($validation['min'] ?? null),
            'max' => $this->formNumber($validation['max'] ?? null),
            'min_length' => $this->formNumber($validation['min_length'] ?? null),
            'max_length' => $this->formNumber($validation['max_length'] ?? null),
            'regex' => $validation['regex'] ?? '',
            'file_types' => implode(', ', $validation['file_types'] ?? []),
            'max_file_size_kb' => $this->formNumber($validation['max_file_size_kb'] ?? null),
            'options' => $field['options'] ?? [],
        ];
    }

    /**
     * Build the attribute payload handed to SchemaService::updateField.
     *
     * @return array<string, mixed>
     */
    protected function attributesForType(FieldType $type): array
    {
        $attributes = [
            'label' => $this->fieldForm['label'] ?? '',
            'key' => $this->fieldForm['key'] ?? '',
        ];

        if ($type === FieldType::Heading) {
            return $attributes;
        }

        $attributes['help_text'] = $this->fieldForm['help_text'] ?? '';
        $attributes['required'] = (bool) ($this->fieldForm['required'] ?? false);
        $attributes['default'] = ($this->fieldForm['default'] ?? '') === ''
            ? null
            : $this->fieldForm['default'];

        if ($this->typeSupportsPlaceholder($type)) {
            $attributes['placeholder'] = $this->fieldForm['placeholder'] ?? '';
        }

        if ($type->requiresOptions()) {
            $attributes['options'] = array_values($this->fieldForm['options'] ?? []);
        }

        $validation = [];

        if ($this->typeSupportsLengthRules($type)) {
            $validation['min_length'] = $this->schemaNumber($this->fieldForm['min_length'] ?? null);
            $validation['max_length'] = $this->schemaNumber($this->fieldForm['max_length'] ?? null);
        }

        if ($type === FieldType::Number || $type === FieldType::Rating) {
            $validation['min'] = $this->schemaNumber($this->fieldForm['min'] ?? null);
            $validation['max'] = $this->schemaNumber($this->fieldForm['max'] ?? null);
        }

        if ($this->typeSupportsRegex($type)) {
            $regex = trim((string) ($this->fieldForm['regex'] ?? ''));
            $validation['regex'] = $regex === '' ? null : $regex;
        }

        if ($type === FieldType::File) {
            $validation['file_types'] = $this->parseFileTypes($this->fieldForm['file_types'] ?? '');
            $validation['max_file_size_kb'] = $this->schemaNumber($this->fieldForm['max_file_size_kb'] ?? null);
        }

        if ($validation !== []) {
            $attributes['validation'] = $validation;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rulesForFieldForm(FieldType $type): array
    {
        $rules = [
            'fieldForm.label' => ['required', 'string', 'max:255'],
            'fieldForm.key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9_]+$/',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (in_array((string) $value, $this->otherFieldKeys(), true)) {
                        $fail('Another field already uses this key.');
                    }
                },
            ],
        ];

        if ($type === FieldType::Heading) {
            return $rules;
        }

        $rules['fieldForm.placeholder'] = ['nullable', 'string', 'max:255'];
        $rules['fieldForm.help_text'] = ['nullable', 'string', 'max:500'];

        if ($this->typeSupportsLengthRules($type)) {
            $rules['fieldForm.min_length'] = ['nullable', 'numeric', 'min:0'];
            $rules['fieldForm.max_length'] = ['nullable', 'numeric', 'min:0'];
        }

        if ($type === FieldType::Number || $type === FieldType::Rating) {
            $rules['fieldForm.min'] = ['nullable', 'numeric', 'min:0'];
            $rules['fieldForm.max'] = ['nullable', 'numeric', 'min:0'];
        }

        if ($type === FieldType::File) {
            $rules['fieldForm.max_file_size_kb'] = ['nullable', 'numeric', 'min:0'];
        }

        if ($type->requiresOptions()) {
            $rules['fieldForm.options'] = ['array', 'min:1'];
            $rules['fieldForm.options.*.value'] = ['required', 'string', 'max:191'];
            $rules['fieldForm.options.*.label'] = ['required', 'string', 'max:191'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function fieldFormAttributeNames(): array
    {
        return [
            'fieldForm.label' => 'label',
            'fieldForm.key' => 'key',
            'fieldForm.placeholder' => 'placeholder',
            'fieldForm.help_text' => 'help text',
            'fieldForm.default' => 'default value',
            'fieldForm.min' => 'minimum',
            'fieldForm.max' => 'maximum',
            'fieldForm.min_length' => 'minimum length',
            'fieldForm.max_length' => 'maximum length',
            'fieldForm.regex' => 'regex',
            'fieldForm.file_types' => 'allowed file types',
            'fieldForm.max_file_size_kb' => 'maximum file size',
            'fieldForm.options' => 'options',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function selectedField(): ?array
    {
        if ($this->selectedFieldId === null) {
            return null;
        }

        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['id'] === $this->selectedFieldId) {
                    return $field;
                }
            }
        }

        return null;
    }

    protected function selectedFieldSupportsOptions(): bool
    {
        $field = $this->selectedField();

        if ($field === null) {
            return false;
        }

        return (FieldType::tryFrom($field['type']) ?? FieldType::Text)->requiresOptions();
    }

    /**
     * Keys owned by fields other than the selected one.
     *
     * @return list<string>
     */
    protected function otherFieldKeys(): array
    {
        $keys = [];

        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['id'] !== $this->selectedFieldId) {
                    $keys[] = $field['key'];
                }
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    protected function parseFileTypes(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $item): string => strtolower(trim($item, " \t\n\r\0\x0B.")),
            explode(',', $raw)
        ))));
    }

    protected function formNumber(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    protected function schemaNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value + 0;
    }

    protected function typeSupportsPlaceholder(FieldType $type): bool
    {
        return in_array($type, [
            FieldType::Text,
            FieldType::Textarea,
            FieldType::Number,
            FieldType::Email,
            FieldType::Phone,
            FieldType::Dropdown,
        ], true);
    }

    protected function typeSupportsLengthRules(FieldType $type): bool
    {
        return in_array($type, [FieldType::Text, FieldType::Textarea], true);
    }

    protected function typeSupportsRegex(FieldType $type): bool
    {
        return in_array($type, [
            FieldType::Text,
            FieldType::Textarea,
            FieldType::Email,
            FieldType::Phone,
        ], true);
    }

    #[Computed]
    public function schemaJson(): string
    {
        return json_encode(
            $this->schema,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '{}';
    }

    /**
     * Property editor view model. Null when no field is selected.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function fieldEditor(): ?array
    {
        $field = $this->selectedField();

        if ($field === null) {
            return null;
        }

        $type = FieldType::tryFrom($field['type']) ?? FieldType::Text;
        $isHeading = $type === FieldType::Heading;

        return [
            'type' => $type->value,
            'typeLabel' => $this->labelFor($type),
            'icon' => 'builder::icons.'.$type->value,
            'isHeading' => $isHeading,
            'showKey' => ! $isHeading,
            'showPlaceholder' => ! $isHeading && $this->typeSupportsPlaceholder($type),
            'showDefault' => ! $isHeading,
            'showHelpText' => ! $isHeading,
            'showValidation' => ! $isHeading,
            'showLengthRules' => $this->typeSupportsLengthRules($type),
            'showNumberRange' => $type === FieldType::Number,
            'showRatingRange' => $type === FieldType::Rating,
            'showFileSize' => $type === FieldType::File,
            'showFileTypes' => $type === FieldType::File,
            'showRegex' => $this->typeSupportsRegex($type),
            'showOptions' => $type->requiresOptions(),
            'showAdvanced' => ! $isHeading && ($this->typeSupportsRegex($type) || $type === FieldType::File),
            'optionCount' => count($field['options'] ?? []),
        ];
    }

    /**
     * Canvas view model, so Blade never reads raw schema arrays.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function sections(): array
    {
        return array_map(function (array $section): array {
            $fields = $section['fields'] ?? [];
            $lastIndex = count($fields) - 1;

            return [
                'id' => $section['id'],
                'title' => $section['title'],
                'fieldCount' => count($fields),
                'selected' => $section['id'] === $this->selectedSectionId,
                'fields' => array_map(function (array $field, int $index) use ($lastIndex): array {
                    $type = FieldType::tryFrom($field['type']) ?? FieldType::Text;

                    return [
                        'id' => $field['id'],
                        'label' => $field['label'],
                        'type' => $type->value,
                        'typeLabel' => $this->labelFor($type),
                        'icon' => 'builder::icons.'.$type->value,
                        'selected' => $field['id'] === $this->selectedFieldId,
                        'isFirst' => $index === 0,
                        'isLast' => $index === $lastIndex,
                    ];
                }, $fields, array_keys($fields)),
            ];
        }, $this->schema['sections'] ?? []);
    }

    /**
     * Validate before assigning so an invalid schema can never be committed.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function commit(array $schema): void
    {
        try {
            $normalized = $this->schemaService()->normalize($schema);
            $this->schemaService()->assertValid($normalized);

            $this->schema = $normalized;
            $this->schemaError = null;
            $this->dirty = true;
        } catch (ValidationException $exception) {
            $this->schemaError = 'That change was rejected: '
                .(collect($exception->errors())->flatten()->first() ?? 'the schema would become invalid.');
        }

        unset($this->schemaJson, $this->sections, $this->fieldEditor);
    }

    /**
     * Drop selection pointing at sections or fields that no longer exist.
     */
    protected function clearSelection(): void
    {
        if ($this->selectedFieldId !== null && $this->locateField($this->selectedFieldId) === null) {
            $this->selectedFieldId = null;
        }

        if ($this->selectedSectionId !== null && ! in_array($this->selectedSectionId, $this->sectionIds(), true)) {
            $this->selectedSectionId = null;
        }
    }

    protected function moveFieldBy(string $fieldId, int $offset): void
    {
        $located = $this->locateField($fieldId);

        if ($located === null) {
            return;
        }

        $position = $located['index'] + $offset;

        if ($position < 0 || $position > $located['lastIndex']) {
            return;
        }

        $this->commit($this->schemaService()->moveField(
            $this->schema,
            $fieldId,
            $located['sectionId'],
            $position
        ));
    }

    /**
     * Add to the selected section, else the last section, else a fresh one.
     */
    protected function resolveTargetSection(?string $sectionId): ?string
    {
        $sectionIds = $this->sectionIds();

        if ($sectionId !== null && in_array($sectionId, $sectionIds, true)) {
            return $sectionId;
        }

        if ($this->selectedSectionId !== null && in_array($this->selectedSectionId, $sectionIds, true)) {
            return $this->selectedSectionId;
        }

        if ($sectionIds !== []) {
            return end($sectionIds);
        }

        $this->commit($this->schemaService()->addSection($this->schema));

        return $this->sectionIds()[0] ?? null;
    }

    /**
     * @return array{sectionId: string, index: int, lastIndex: int}|null
     */
    protected function locateField(string $fieldId): ?array
    {
        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] as $index => $field) {
                if ($field['id'] === $fieldId) {
                    return [
                        'sectionId' => $section['id'],
                        'index' => $index,
                        'lastIndex' => count($section['fields']) - 1,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function sectionIds(): array
    {
        return array_column($this->schema['sections'] ?? [], 'id');
    }

    /**
     * @return list<string>
     */
    protected function fieldIds(): array
    {
        $ids = [];

        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] as $field) {
                $ids[] = $field['id'];
            }
        }

        return $ids;
    }

    /**
     * Defaults handed to SchemaService::addField. Choice types need starter
     * options because an empty option list fails schema validation.
     *
     * @return array<string, mixed>
     */
    protected function defaultAttributesFor(FieldType $type): array
    {
        $attributes = ['label' => $this->defaultLabelFor($type)];

        if ($type->requiresOptions()) {
            $attributes['options'] = [
                ['value' => 'option_1', 'label' => 'Option 1'],
                ['value' => 'option_2', 'label' => 'Option 2'],
            ];
        }

        return $attributes;
    }

    protected function defaultLabelFor(FieldType $type): string
    {
        return match ($type) {
            FieldType::Heading => 'Section Heading',
            default => $this->labelFor($type).' Field',
        };
    }

    protected function schemaService(): SchemaService
    {
        return app(SchemaService::class);
    }

    /**
     * @return list<array{type: string, label: string, description: string, icon: string}>
     */
    protected function buildPalette(): array
    {
        return array_map(
            fn (FieldType $type): array => [
                'type' => $type->value,
                'label' => $this->labelFor($type),
                'description' => $this->descriptionFor($type),
                'icon' => 'builder::icons.'.$type->value,
            ],
            FieldType::cases()
        );
    }

    protected function labelFor(FieldType $type): string
    {
        return match ($type) {
            FieldType::Text => 'Text',
            FieldType::Textarea => 'Textarea',
            FieldType::Number => 'Number',
            FieldType::Email => 'Email',
            FieldType::Phone => 'Phone',
            FieldType::Date => 'Date',
            FieldType::Dropdown => 'Dropdown',
            FieldType::Radio => 'Radio',
            FieldType::Checkbox => 'Checkbox',
            FieldType::File => 'File Upload',
            FieldType::Heading => 'Heading',
            FieldType::Rating => 'Rating',
        };
    }

    protected function descriptionFor(FieldType $type): string
    {
        return match ($type) {
            FieldType::Text => 'Single line input',
            FieldType::Textarea => 'Multi-line input',
            FieldType::Number => 'Numeric input',
            FieldType::Email => 'Email address',
            FieldType::Phone => 'Phone number',
            FieldType::Date => 'Date picker',
            FieldType::Dropdown => 'Select one option',
            FieldType::Radio => 'Choose one option',
            FieldType::Checkbox => 'Choose multiple options',
            FieldType::File => 'Upload file',
            FieldType::Heading => 'Section heading',
            FieldType::Rating => 'Star rating',
        };
    }

    #[Layout('layouts.builder')]
    #[Title('Form Builder')]
    public function render()
    {
        return view('livewire.forms.form-builder');
    }
}
