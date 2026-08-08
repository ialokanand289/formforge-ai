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
     * Reserved for Phase 4D undo support. Not used in this phase.
     *
     * @var list<array<string, mixed>>
     */
    protected array $history = [];

    public function mount(Form $form, SchemaService $schemaService): void
    {
        $this->authorize('view', $form);

        $this->form = $form;
        $this->title = $form->title;
        $this->status = $form->status->value;
        $this->schema = $schemaService->blank($form->title);
        $this->paletteFields = $this->buildPalette();
        $this->selectedSectionId = $this->schema['sections'][0]['id'] ?? null;
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
    }

    public function removeSection(string $sectionId): void
    {
        $this->commit($this->schemaService()->removeSection($this->schema, $sectionId));
        $this->clearSelection();
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
    }

    public function removeField(string $fieldId): void
    {
        $this->commit($this->schemaService()->removeField($this->schema, $fieldId));
        $this->clearSelection();
    }

    public function duplicateField(string $fieldId): void
    {
        $before = $this->fieldIds();

        $this->commit($this->schemaService()->duplicateField($this->schema, $fieldId));

        $added = array_values(array_diff($this->fieldIds(), $before));

        if ($added !== []) {
            $this->selectedFieldId = $added[0];
        }
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
    }

    public function selectField(string $fieldId): void
    {
        $located = $this->locateField($fieldId);

        if ($located === null) {
            return;
        }

        $this->selectedSectionId = $located['sectionId'];
        $this->selectedFieldId = $fieldId;
    }

    public function dismissSchemaError(): void
    {
        $this->schemaError = null;
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
        } catch (ValidationException $exception) {
            $this->schemaError = 'That change was rejected: '
                .(collect($exception->errors())->flatten()->first() ?? 'the schema would become invalid.');
        }

        unset($this->schemaJson, $this->sections);
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
