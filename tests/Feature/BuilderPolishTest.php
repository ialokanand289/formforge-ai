<?php

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function polishBuilder(): Testable
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $form = Form::factory()->for($user)->create(['title' => 'Client Intake']);

    return Livewire::actingAs($user)->test(FormBuilder::class, ['form' => $form]);
}

function polishFieldIds(Testable $component, int $sectionIndex = 0): array
{
    return array_column($component->get('schema')['sections'][$sectionIndex]['fields'], 'id');
}

it('mounts with a clean dirty flag and no indicator', function () {
    polishBuilder()
        ->assertSet('dirty', false)
        ->assertDontSee('Unsaved changes');
});

it('marks the builder dirty after adding a field and shows the indicator', function () {
    polishBuilder()
        ->call('addField', 'text')
        ->assertSet('dirty', true)
        ->assertSee('Unsaved changes');
});

it('marks the builder dirty after a section mutation', function () {
    polishBuilder()
        ->call('addSection')
        ->assertSet('dirty', true);
});

it('marks the builder dirty after a property update', function () {
    $component = polishBuilder()
        ->call('addField', 'text')
        ->set('fieldForm.label', 'Full Name')
        ->assertSet('dirty', true)
        ->assertSet('schemaError', null);

    expect($component->get('schema')['sections'][0]['fields'][0]['label'])->toBe('Full Name');
});

it('refuses a client attempt to clear the dirty flag', function () {
    polishBuilder()
        ->call('addField', 'text')
        ->set('dirty', false);
})->throws(Exception::class);

it('deletes the selected field through the keyboard action', function () {
    $component = polishBuilder()
        ->call('addField', 'text')
        ->call('addField', 'email');

    $ids = polishFieldIds($component);
    $selected = $component->get('selectedFieldId');

    expect($selected)->toBe($ids[1]);

    $component->call('deleteSelectedField');

    expect(polishFieldIds($component))->toBe([$ids[0]]);
    expect($component->get('selectedFieldId'))->toBeNull();
});

it('ignores the delete action when nothing is selected', function () {
    $component = polishBuilder()->call('addField', 'text');

    $before = polishFieldIds($component);

    $component->call('deselect')->call('deleteSelectedField');

    expect(polishFieldIds($component))->toBe($before);
    expect($component->get('schemaError'))->toBeNull();
});

it('clears selection and empties the editor on deselect', function () {
    polishBuilder()
        ->call('addField', 'text')
        ->call('deselect')
        ->assertSet('selectedFieldId', null)
        ->assertSet('selectedSectionId', null)
        ->assertSet('fieldForm', [])
        ->assertSee('No field selected');
});

it('renders the keyboard handler with an editable guard', function () {
    polishBuilder()
        ->assertSeeHtml('deleteSelectedField()')
        ->assertSeeHtml('isEditable(')
        ->assertSeeHtml('contenteditable');
});

it('renders loading states on mutating controls', function () {
    $html = polishBuilder()->call('addField', 'text')->html();

    expect($html)->toContain('wire:loading.attr="disabled"');
    expect(substr_count($html, 'wire:loading.attr="disabled"'))->toBeGreaterThan(1);
    expect($html)->toContain('wire:loading.class="opacity-60"');
});

it('conveys selection with aria-pressed rather than aria-current', function () {
    // Two fields so both the selected and unselected states render.
    $html = polishBuilder()
        ->call('addField', 'text')
        ->call('addField', 'email')
        ->html();

    expect($html)->toContain('aria-pressed="true"');
    expect($html)->toContain('aria-pressed="false"');
    expect($html)->not->toContain('aria-current');
});

it('exposes live regions for errors and the unsaved indicator', function () {
    $html = polishBuilder()->html();

    expect($html)->toContain('aria-live="assertive"');
    expect($html)->toContain('role="status"');
    expect($html)->toContain('aria-live="polite"');
});

it('associates invalid property inputs with their error text', function () {
    $html = polishBuilder()
        ->call('addField', 'text')
        ->set('fieldForm.label', '')
        ->html();

    expect($html)->toContain('aria-invalid="true"');
    expect($html)->toContain('aria-describedby="prop-fieldform-label-error"');
});

it('labels the palette, canvas, and properties landmarks', function () {
    $html = polishBuilder()->html();

    expect($html)->toContain('aria-label="Field palette"');
    expect($html)->toContain('aria-label="Form canvas"');
    expect($html)->toContain('aria-labelledby="properties-panel-heading"');
});

it('keeps the schema valid and the json preview synchronised after polish actions', function () {
    $component = polishBuilder()
        ->call('addField', 'text')
        ->call('addField', 'email')
        ->call('deselect')
        ->call('addSection');

    $schema = $component->get('schema');

    app(SchemaService::class)->assertValid($schema);

    expect($component->get('schemaError'))->toBeNull();
    expect(json_decode($component->get('schemaJson'), true))->toBe($schema);
});

it('still moves fields by drag after the polish pass', function () {
    $component = polishBuilder()
        ->call('addField', 'text')
        ->call('addField', 'email')
        ->call('addField', 'number');

    [$first, $second, $third] = polishFieldIds($component);
    $sectionId = $component->get('schema')['sections'][0]['id'];

    $component->call('moveField', $first, $sectionId, 3);

    expect(polishFieldIds($component))->toBe([$second, $third, $first]);
    app(SchemaService::class)->assertValid($component->get('schema'));
});
