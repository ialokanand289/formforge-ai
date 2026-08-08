<?php

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function builder(?Form $form = null): Testable
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $form ??= Form::factory()->for($user)->create(['title' => 'Client Intake']);

    return Livewire::actingAs($user)->test(FormBuilder::class, ['form' => $form]);
}

function firstSectionId(array $schema): string
{
    return $schema['sections'][0]['id'];
}

function fieldsOf(array $schema, int $sectionIndex = 0): array
{
    return $schema['sections'][$sectionIndex]['fields'];
}

it('starts with a blank schema titled after the form', function () {
    $component = builder();

    $schema = $component->get('schema');

    expect($schema['title'])->toBe('Client Intake');
    expect($schema['sections'])->toHaveCount(1);
    expect(fieldsOf($schema))->toBeEmpty();
    expect($component->get('selectedSectionId'))->toBe(firstSectionId($schema));
});

it('adds a section and selects it', function () {
    $component = builder()->call('addSection');

    $schema = $component->get('schema');

    expect($schema['sections'])->toHaveCount(2);
    expect($component->get('selectedSectionId'))->toBe($schema['sections'][1]['id']);
});

it('adds a field from the palette to the selected section', function () {
    $component = builder()->call('addField', 'email');

    $fields = fieldsOf($component->get('schema'));

    expect($fields)->toHaveCount(1);
    expect($fields[0]['type'])->toBe('email');
    expect($fields[0]['label'])->toBe('Email Field');
    expect($component->get('selectedFieldId'))->toBe($fields[0]['id']);
});

it('creates a section automatically when none exist', function () {
    $component = builder();
    $component->call('removeSection', firstSectionId($component->get('schema')));

    expect($component->get('schema')['sections'])->toBeEmpty();
    expect($component->get('selectedSectionId'))->toBeNull();

    $component->call('addField', 'text');

    $schema = $component->get('schema');

    expect($schema['sections'])->toHaveCount(1);
    expect(fieldsOf($schema))->toHaveCount(1);
});

it('adds choice fields with valid starter options', function () {
    $component = builder()->call('addField', 'dropdown');

    $fields = fieldsOf($component->get('schema'));

    expect($fields[0]['options'])->toHaveCount(2);
    expect($component->get('schemaError'))->toBeNull();
    expect(app(SchemaService::class)->isValid($component->get('schema')))->toBeTrue();
});

it('removes a field and clears its selection', function () {
    $component = builder()->call('addField', 'text');
    $fieldId = fieldsOf($component->get('schema'))[0]['id'];

    $component->call('removeField', $fieldId);

    expect(fieldsOf($component->get('schema')))->toBeEmpty();
    expect($component->get('selectedFieldId'))->toBeNull();
    expect($component->get('selectedSectionId'))->not->toBeNull();
});

it('duplicates a field with a copied label and unique key', function () {
    $component = builder()->call('addField', 'text');
    $original = fieldsOf($component->get('schema'))[0];

    $component->call('duplicateField', $original['id']);

    $fields = fieldsOf($component->get('schema'));

    expect($fields)->toHaveCount(2);
    expect($fields[1]['label'])->toBe($original['label'].' (Copy)');
    expect($fields[1]['key'])->not->toBe($original['key']);
    expect($fields[1]['id'])->not->toBe($original['id']);
    expect($component->get('selectedFieldId'))->toBe($fields[1]['id']);
});

it('removes a section and clears selection inside it', function () {
    $component = builder()->call('addField', 'text');
    $sectionId = firstSectionId($component->get('schema'));

    $component->call('removeSection', $sectionId);

    expect($component->get('schema')['sections'])->toBeEmpty();
    expect($component->get('selectedSectionId'))->toBeNull();
    expect($component->get('selectedFieldId'))->toBeNull();
});

it('moves a field up and down within its section', function () {
    $component = builder()
        ->call('addField', 'text')
        ->call('addField', 'email');

    $second = fieldsOf($component->get('schema'))[1];

    $component->call('moveFieldUp', $second['id']);
    expect(fieldsOf($component->get('schema'))[0]['id'])->toBe($second['id']);

    $component->call('moveFieldDown', $second['id']);
    expect(fieldsOf($component->get('schema'))[1]['id'])->toBe($second['id']);
});

it('ignores moves beyond the section boundaries', function () {
    $component = builder()->call('addField', 'text');
    $fieldId = fieldsOf($component->get('schema'))[0]['id'];

    $component->call('moveFieldUp', $fieldId)->call('moveFieldDown', $fieldId);

    expect(fieldsOf($component->get('schema')))->toHaveCount(1);
    expect($component->get('schemaError'))->toBeNull();
});

it('keeps the json preview in sync with every mutation', function () {
    $component = builder();

    expect($component->get('schemaJson'))->not->toContain('Email Field');

    $component->call('addField', 'email');
    expect($component->get('schemaJson'))->toContain('Email Field');

    $fieldId = fieldsOf($component->get('schema'))[0]['id'];
    $component->call('removeField', $fieldId);
    expect($component->get('schemaJson'))->not->toContain('Email Field');
});

it('renders sections and fields on the canvas', function () {
    builder()
        ->call('addField', 'email')
        ->assertSee('Section 1')
        ->assertSee('1 field')
        ->assertSee('Email Field')
        ->assertDontSee('Start building your form');
});

it('rejects an unsupported field type without changing the schema', function () {
    $component = builder();
    $before = $component->get('schema');

    $component->call('addField', 'not-a-type');

    expect($component->get('schema'))->toBe($before);
    expect($component->get('schemaError'))->not->toBeNull();

    $component->call('dismissSchemaError');
    expect($component->get('schemaError'))->toBeNull();
});

it('keeps the schema valid after a sequence of operations', function () {
    $component = builder()
        ->call('addSection')
        ->call('addField', 'text')
        ->call('addField', 'radio')
        ->call('addField', 'file')
        ->call('addSection');

    $schema = $component->get('schema');

    expect(app(SchemaService::class)->isValid($schema))->toBeTrue();
    expect($component->get('schemaError'))->toBeNull();
});
