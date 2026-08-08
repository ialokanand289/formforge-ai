<?php

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function persistenceOwner(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

function persistenceForm(User $owner, array $attributes = []): Form
{
    return Form::factory()->for($owner)->create(array_merge(['title' => 'Client Intake'], $attributes));
}

function persistenceBuilder(?User $owner = null, ?Form $form = null): Testable
{
    $owner ??= persistenceOwner();
    $form ??= persistenceForm($owner);

    return Livewire::actingAs($owner)->test(FormBuilder::class, ['form' => $form]);
}

it('persists the working schema to the form row', function () {
    $owner = persistenceOwner();
    $form = persistenceForm($owner);

    $component = persistenceBuilder($owner, $form)
        ->call('addField', 'email')
        ->call('save');

    $form->refresh();

    expect($form->schema)->toBe($component->get('schema'));
    expect($form->schema['sections'][0]['fields'][0]['type'])->toBe('email');
});

it('projects the schema title onto the form row', function () {
    $owner = persistenceOwner();
    $form = persistenceForm($owner);

    persistenceBuilder($owner, $form)
        ->call('addField', 'text')
        ->call('save');

    $form->refresh();

    expect($form->title)->toBe($form->schema['title']);
    expect($form->title)->toBe('Client Intake');
});

it('projects the schema description onto the form row', function () {
    $owner = persistenceOwner();
    $schema = app(SchemaService::class)->blank('Client Intake');
    $schema['description'] = 'Collected before the first appointment.';
    $form = persistenceForm($owner, ['schema' => $schema, 'description' => null]);

    persistenceBuilder($owner, $form)
        ->call('addField', 'text')
        ->call('save');

    $form->refresh();

    expect($form->description)->toBe('Collected before the first appointment.');
    expect($form->description)->toBe($form->schema['description']);
});

it('increments the schema version on save', function () {
    $owner = persistenceOwner();
    $form = persistenceForm($owner);

    expect($form->schema_version)->toBe(1);

    persistenceBuilder($owner, $form)->call('addField', 'text')->call('save');

    expect($form->refresh()->schema_version)->toBe(2);
});

it('creates a version snapshot matching the saved schema', function () {
    $owner = persistenceOwner();
    $form = persistenceForm($owner);

    $component = persistenceBuilder($owner, $form)->call('addField', 'text')->call('save');

    $form->refresh();
    $version = FormVersion::query()->where('form_id', $form->id)->sole();

    expect($version->version)->toBe($form->schema_version);
    expect($version->version)->toBe(2);
    expect($version->schema)->toBe($component->get('schema'));
    expect($version->created_by)->toBe($owner->id);
});

it('forbids opening another users form', function () {
    $owner = persistenceOwner();
    $intruder = persistenceOwner();
    $form = persistenceForm($owner);

    Livewire::actingAs($intruder)
        ->test(FormBuilder::class, ['form' => $form])
        ->assertForbidden();
});

it('rejects a save once the actor loses update permission', function () {
    $owner = persistenceOwner();
    $intruder = persistenceOwner();
    $form = persistenceForm($owner);

    $component = persistenceBuilder($owner, $form)->call('addField', 'text');

    $this->actingAs($intruder);

    $component->call('save')->assertForbidden();

    expect(FormVersion::query()->count())->toBe(0);
    expect($form->refresh()->schema_version)->toBe(1);
});

it('resets the dirty flag after a successful save', function () {
    persistenceBuilder()
        ->call('addField', 'text')
        ->assertSet('dirty', true)
        ->call('save')
        ->assertSet('dirty', false)
        ->assertSet('saveMessage', 'Form saved successfully.')
        ->assertSee('Form saved successfully.')
        ->assertDontSee('Unsaved changes');
});

it('clears the success message on the next mutation', function () {
    persistenceBuilder()
        ->call('addField', 'text')
        ->call('save')
        ->call('addField', 'email')
        ->assertSet('saveMessage', null)
        ->assertSet('dirty', true);
});

it('does not create a second version when saving without changes', function () {
    $owner = persistenceOwner();
    $form = persistenceForm($owner);

    $component = persistenceBuilder($owner, $form)->call('addField', 'text')->call('save');

    $component->call('save')->call('save');

    expect(FormVersion::query()->where('form_id', $form->id)->count())->toBe(1);
    expect($form->refresh()->schema_version)->toBe(2);
});

it('disables the save button until there are changes', function () {
    $component = persistenceBuilder();

    $component->assertSeeHtml('title="No changes to save"');

    $component->call('addField', 'text')->assertSeeHtml('title="Save changes"');
});

it('scopes the save loading state to the save action', function () {
    $html = persistenceBuilder()->call('addField', 'text')->html();

    expect($html)->toContain('wire:click="save"');
    expect($html)->toContain('wire:target="save"');
});

it('keeps the json preview synchronised with the persisted schema', function () {
    $owner = persistenceOwner();
    $form = persistenceForm($owner);

    $component = persistenceBuilder($owner, $form)->call('addField', 'text')->call('save');

    expect(json_decode($component->get('schemaJson'), true))->toBe($form->refresh()->schema);
});

it('reloads the persisted schema when the builder is reopened', function () {
    $owner = persistenceOwner();
    $form = persistenceForm($owner);

    persistenceBuilder($owner, $form)
        ->call('addField', 'email')
        ->set('fieldForm.label', 'Work Email')
        ->call('save');

    $reopened = persistenceBuilder($owner, $form->fresh());
    $fields = $reopened->get('schema')['sections'][0]['fields'];

    expect($fields)->toHaveCount(1);
    expect($fields[0]['label'])->toBe('Work Email');
    expect($reopened->get('dirty'))->toBeFalse();
});

it('keeps the selected field selected after a save', function () {
    $component = persistenceBuilder()->call('addField', 'text');

    $selected = $component->get('selectedFieldId');
    $section = $component->get('selectedSectionId');

    $component->call('save');

    expect($component->get('selectedFieldId'))->toBe($selected);
    expect($component->get('selectedSectionId'))->toBe($section);
    expect($component->get('fieldForm'))->not->toBeEmpty();
});

it('restores the selected field against its new section after a cross-section move', function () {
    $component = persistenceBuilder()
        ->call('addField', 'text')
        ->call('addSection');

    $schema = $component->get('schema');
    $fieldId = $schema['sections'][0]['fields'][0]['id'];
    $secondSection = $schema['sections'][1]['id'];

    $component->call('selectField', $fieldId)
        ->call('moveField', $fieldId, $secondSection, 0)
        ->call('save');

    expect($component->get('selectedFieldId'))->toBe($fieldId);
    expect($component->get('selectedSectionId'))->toBe($secondSection);
});

it('clears selection after a save when the selected field was deleted', function () {
    $component = persistenceBuilder()->call('addField', 'text');

    $fieldId = $component->get('selectedFieldId');

    $component->call('removeField', $fieldId)->call('save');

    expect($component->get('selectedFieldId'))->toBeNull();
    expect($component->get('fieldForm'))->toBeEmpty();
});

it('rolls back the form update when the version snapshot fails', function () {
    Log::spy();

    $owner = persistenceOwner();
    $form = persistenceForm($owner);

    $component = persistenceBuilder($owner, $form)->call('addField', 'text');

    $working = $component->get('schema');

    Event::listen('eloquent.creating: '.FormVersion::class, function () {
        throw new RuntimeException('SQLSTATE[23000]: snapshot insert failed');
    });

    $component->call('save');

    $form->refresh();

    expect($form->schema_version)->toBe(1);
    expect($form->schema['sections'][0]['fields'])->toBeEmpty();
    expect(FormVersion::query()->count())->toBe(0);

    // The user's work survives, and the database error never reaches them.
    expect($component->get('dirty'))->toBeTrue();
    expect($component->get('schema'))->toBe($working);
    expect($component->get('saveMessage'))->toBeNull();
    expect($component->get('schemaError'))->toBe('Your changes could not be saved. Please try again.');
    expect($component->get('schemaError'))->not->toContain('SQLSTATE');

    Log::shouldHaveReceived('error')->once();
});

it('keeps unsaved work when the schema is rejected at save time', function () {
    $owner = persistenceOwner();
    $form = persistenceForm($owner);

    $component = persistenceBuilder($owner, $form)->call('addField', 'text');

    $working = $component->get('schema');

    Event::listen('eloquent.saving: '.Form::class, function () {
        throw new RuntimeException('write failed');
    });

    $component->call('save');

    expect($component->get('dirty'))->toBeTrue();
    expect($component->get('schema'))->toBe($working);
    expect($form->refresh()->schema_version)->toBe(1);
});

it('still supports field operations after a save', function () {
    $owner = persistenceOwner();
    $form = persistenceForm($owner);

    $component = persistenceBuilder($owner, $form)
        ->call('addField', 'text')
        ->call('save')
        ->call('addField', 'dropdown')
        ->call('save');

    $form->refresh();

    app(SchemaService::class)->assertValid($form->schema);

    expect($form->schema_version)->toBe(3);
    expect(FormVersion::query()->where('form_id', $form->id)->count())->toBe(2);
    expect($component->get('dirty'))->toBeFalse();
});
