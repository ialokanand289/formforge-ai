<?php

namespace App\Livewire\Forms;

use App\Enums\FieldType;
use App\Models\Form;
use App\Services\SchemaService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class FormBuilder extends Component
{
    use AuthorizesRequests;

    public Form $form;

    public string $title = '';

    public string $status = '';

    public string $schemaJson = '';

    /**
     * Palette metadata, prepared here so Blade stays presentation only.
     *
     * @var list<array{type: string, label: string, description: string, icon: string}>
     */
    public array $paletteFields = [];

    public function mount(Form $form, SchemaService $schemaService): void
    {
        $this->authorize('view', $form);

        $this->form = $form;
        $this->title = $form->title;
        $this->status = $form->status->value;

        $this->schemaJson = json_encode(
            $schemaService->blank($form->title),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '{}';

        $this->paletteFields = $this->buildPalette();
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
