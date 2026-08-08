<?php

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function editor(string $type = 'text'): Testable
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $form = Form::factory()->for($user)->create(['title' => 'Client Intake']);

    return Livewire::actingAs($user)
        ->test(FormBuilder::class, ['form' => $form])
        ->call('addField', $type);
}

function selectedField(Testable $component): array
{
    $id = $component->get('selectedFieldId');

    foreach ($component->get('schema')['sections'] as $section) {
        foreach ($section['fields'] as $field) {
            if ($field['id'] === $id) {
                return $field;
            }
        }
    }

    throw new RuntimeException('No field selected.');
}

it('shows no editor until a field is selected', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $form = Form::factory()->for($user)->create();

    $component = Livewire::actingAs($user)->test(FormBuilder::class, ['form' => $form]);

    expect($component->get('fieldEditor'))->toBeNull();
    expect($component->get('fieldForm'))->toBeEmpty();
    $component->assertSee('No field selected');
});

it('mirrors the selected field into the editor form', function () {
    $component = editor('email');

    expect($component->get('fieldForm')['label'])->toBe('Email Field');
    expect($component->get('fieldForm')['key'])->toBe('email_field');
    expect($component->get('fieldEditor')['typeLabel'])->toBe('Email');
    expect($component->get('fieldEditor')['showOptions'])->toBeFalse();
});

it('updates the label', function () {
    $component = editor()->set('fieldForm.label', 'Your Full Name');

    expect(selectedField($component)['label'])->toBe('Your Full Name');
});

it('updates the key', function () {
    $component = editor()->set('fieldForm.key', 'full_name');

    expect(selectedField($component)['key'])->toBe('full_name');
    $component->assertHasNoErrors();
});

it('toggles required', function () {
    $component = editor();

    expect(selectedField($component)['required'])->toBeFalse();

    $component->set('fieldForm.required', true);

    expect(selectedField($component)['required'])->toBeTrue();
});

it('updates the placeholder', function () {
    $component = editor()->set('fieldForm.placeholder', 'Jane Doe');

    expect(selectedField($component)['placeholder'])->toBe('Jane Doe');
});

it('updates the help text', function () {
    $component = editor()->set('fieldForm.help_text', 'As it appears on your ID.');

    expect(selectedField($component)['help_text'])->toBe('As it appears on your ID.');
});

it('updates length validation rules', function () {
    $component = editor()
        ->set('fieldForm.min_length', '3')
        ->set('fieldForm.max_length', '64');

    $validation = selectedField($component)['validation'];

    expect($validation['min_length'])->toBe(3);
    expect($validation['max_length'])->toBe(64);
});

it('updates number range rules', function () {
    $component = editor('number')
        ->set('fieldForm.min', '1')
        ->set('fieldForm.max', '10');

    $validation = selectedField($component)['validation'];

    expect($validation['min'])->toBe(1);
    expect($validation['max'])->toBe(10);
});

it('updates file rules', function () {
    $component = editor('file')
        ->set('fieldForm.file_types', 'PDF, .docx , png')
        ->set('fieldForm.max_file_size_kb', '2048');

    $validation = selectedField($component)['validation'];

    expect($validation['file_types'])->toBe(['pdf', 'docx', 'png']);
    expect($validation['max_file_size_kb'])->toBe(2048);
});

it('adds an option', function () {
    $component = editor('dropdown');

    expect($component->get('fieldEditor')['optionCount'])->toBe(2);

    $component->call('addOption');

    expect(selectedField($component)['options'])->toHaveCount(3);
    expect($component->get('fieldEditor')['optionCount'])->toBe(3);
});

it('edits an option label', function () {
    $component = editor('radio')->set('fieldForm.options.0.label', 'Yes, please');

    expect(selectedField($component)['options'][0]['label'])->toBe('Yes, please');
});

it('removes an option', function () {
    $component = editor('checkbox')->call('removeOption', 1);

    expect(selectedField($component)['options'])->toHaveCount(1);
});

it('moves an option up and down', function () {
    $component = editor('dropdown');
    $second = selectedField($component)['options'][1];

    $component->call('moveOptionUp', 1);
    expect(selectedField($component)['options'][0])->toBe($second);

    $component->call('moveOptionDown', 0);
    expect(selectedField($component)['options'][1])->toBe($second);
});

it('rejects removing the last option', function () {
    $component = editor('dropdown')
        ->call('removeOption', 1)
        ->call('removeOption', 0);

    $component->assertHasErrors('fieldForm.options');
    expect(selectedField($component)['options'])->toHaveCount(1);
});

it('rejects a duplicate field key', function () {
    $component = editor('text')->call('addField', 'email');
    $emailField = selectedField($component);

    $component->set('fieldForm.key', 'text_field');

    $component->assertHasErrors(['fieldForm.key']);
    expect(selectedField($component)['key'])->toBe($emailField['key']);
});

it('rejects an invalid regex', function () {
    $component = editor()->set('fieldForm.regex', '/[unclosed/');

    expect(selectedField($component)['validation']['regex'])->toBeNull();
    expect($component->get('schemaError'))->not->toBeNull();
});

it('accepts a valid regex', function () {
    $component = editor()->set('fieldForm.regex', '/^[A-Z]{2}\d+$/');

    expect(selectedField($component)['validation']['regex'])->toBe('/^[A-Z]{2}\d+$/');
    expect($component->get('schemaError'))->toBeNull();
});

it('rejects a negative limit', function () {
    $component = editor()->set('fieldForm.min_length', '-5');

    $component->assertHasErrors(['fieldForm.min_length']);
    expect(selectedField($component)['validation']['min_length'])->toBeNull();
});

it('rejects an empty label', function () {
    $component = editor()->set('fieldForm.label', '');

    $component->assertHasErrors(['fieldForm.label']);
    expect(selectedField($component)['label'])->toBe('Text Field');
});

it('rejects a malformed key', function () {
    $component = editor()->set('fieldForm.key', 'Not A Key');

    $component->assertHasErrors(['fieldForm.key']);
    expect(selectedField($component)['key'])->toBe('text_field');
});

it('edits only the heading text for heading fields', function () {
    $component = editor('heading');

    expect($component->get('fieldEditor')['isHeading'])->toBeTrue();
    expect($component->get('fieldEditor')['showKey'])->toBeFalse();
    expect($component->get('fieldEditor')['showValidation'])->toBeFalse();

    $component->set('fieldForm.label', 'Personal Details');

    expect(selectedField($component)['label'])->toBe('Personal Details');
});

it('refreshes the json preview after a property change', function () {
    $component = editor();

    expect($component->get('schemaJson'))->not->toContain('Your Full Name');

    $component->set('fieldForm.label', 'Your Full Name');

    expect($component->get('schemaJson'))->toContain('Your Full Name');
});

it('keeps the schema valid across a sequence of property edits', function () {
    $component = editor('dropdown')
        ->set('fieldForm.label', 'Preferred Contact')
        ->set('fieldForm.key', 'preferred_contact')
        ->set('fieldForm.required', true)
        ->call('addOption')
        ->set('fieldForm.options.2.label', 'SMS')
        ->set('fieldForm.options.2.value', 'sms')
        ->call('moveOptionUp', 2);

    expect(app(SchemaService::class)->isValid($component->get('schema')))->toBeTrue();
    expect($component->get('schemaError'))->toBeNull();
    $component->assertHasNoErrors();
});
