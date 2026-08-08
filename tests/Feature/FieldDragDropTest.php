<?php

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A builder holding one section with three text fields.
 */
function dragBuilder(int $fields = 3): Testable
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $form = Form::factory()->for($user)->create(['title' => 'Client Intake']);

    $component = Livewire::actingAs($user)->test(FormBuilder::class, ['form' => $form]);

    for ($i = 0; $i < $fields; $i++) {
        $component->call('addField', 'text');
    }

    return $component;
}

function sectionIdAt(Testable $component, int $index): string
{
    return $component->get('schema')['sections'][$index]['id'];
}

function fieldIdsIn(Testable $component, int $sectionIndex = 0): array
{
    return array_column($component->get('schema')['sections'][$sectionIndex]['fields'], 'id');
}

function labelsIn(Testable $component, int $sectionIndex = 0): array
{
    return array_column($component->get('schema')['sections'][$sectionIndex]['fields'], 'label');
}

it('moves a field within the same section', function () {
    $component = dragBuilder();
    [$first, $second, $third] = fieldIdsIn($component);
    $sectionId = sectionIdAt($component, 0);

    // Drop the first field between the second and third.
    $component->call('moveField', $first, $sectionId, 2);

    expect(fieldIdsIn($component))->toBe([$second, $first, $third]);
});

it('moves a field to the first position', function () {
    $component = dragBuilder();
    [$first, $second, $third] = fieldIdsIn($component);
    $sectionId = sectionIdAt($component, 0);

    $component->call('moveField', $third, $sectionId, 0);

    expect(fieldIdsIn($component))->toBe([$third, $first, $second]);
});

it('moves a field to the last position', function () {
    $component = dragBuilder();
    [$first, $second, $third] = fieldIdsIn($component);
    $sectionId = sectionIdAt($component, 0);

    $component->call('moveField', $first, $sectionId, 3);

    expect(fieldIdsIn($component))->toBe([$second, $third, $first]);
});

it('moves a field from section A to section B', function () {
    $component = dragBuilder(2);
    $sectionA = sectionIdAt($component, 0);

    $component->call('addSection');
    $sectionB = sectionIdAt($component, 1);
    $component->call('addField', 'email', $sectionB);

    [, $second] = fieldIdsIn($component, 0);
    $third = fieldIdsIn($component, 1)[0];

    // Drop the second field of A below the only field of B.
    $component->call('moveField', $second, $sectionB, 1);

    expect(fieldIdsIn($component, 0))->toHaveCount(1);
    expect(fieldIdsIn($component, 1))->toBe([$third, $second]);
});

it('moves a field into an empty section', function () {
    $component = dragBuilder(2);
    $sectionA = sectionIdAt($component, 0);

    $component->call('addSection');
    $sectionB = sectionIdAt($component, 1);

    expect(fieldIdsIn($component, 1))->toBeEmpty();

    $moved = fieldIdsIn($component, 0)[0];
    $component->call('moveField', $moved, $sectionB, 0);

    expect(fieldIdsIn($component, 0))->toHaveCount(1);
    expect(fieldIdsIn($component, 1))->toBe([$moved]);
});

it('keeps the dragged field selected after a move', function () {
    $component = dragBuilder();
    $first = fieldIdsIn($component)[0];
    $sectionId = sectionIdAt($component, 0);

    $component->call('selectField', $first);
    expect($component->get('selectedFieldId'))->toBe($first);

    $component->call('moveField', $first, $sectionId, 3);

    expect($component->get('selectedFieldId'))->toBe($first);
    expect($component->get('fieldEditor'))->not->toBeNull();
    expect($component->get('fieldForm')['key'])->toBe('text_field');
});

it('keeps selection when the field crosses sections', function () {
    $component = dragBuilder(1);
    $moved = fieldIdsIn($component, 0)[0];

    $component->call('addSection');
    $sectionB = sectionIdAt($component, 1);

    $component->call('selectField', $moved);
    $component->call('moveField', $moved, $sectionB, 0);

    expect($component->get('selectedFieldId'))->toBe($moved);
    expect($component->get('selectedSectionId'))->toBe($sectionB);
});

it('reflects the new order in the json preview', function () {
    $component = dragBuilder(0);
    $sectionId = sectionIdAt($component, 0);

    $component->call('addField', 'text')->call('addField', 'email');
    $email = fieldIdsIn($component)[1];

    expect(labelsIn($component))->toBe(['Text Field', 'Email Field']);

    $component->call('moveField', $email, $sectionId, 0);

    expect(labelsIn($component))->toBe(['Email Field', 'Text Field']);

    $json = $component->get('schemaJson');
    expect(strpos($json, 'Email Field'))->toBeLessThan(strpos($json, 'Text Field'));
});

it('reflects cross-section membership in the json preview', function () {
    $component = dragBuilder(1);
    $component->call('addSection');
    $sectionB = sectionIdAt($component, 1);

    $moved = fieldIdsIn($component, 0)[0];
    $component->call('moveField', $moved, $sectionB, 0);

    $decoded = json_decode($component->get('schemaJson'), true);

    expect($decoded['sections'][0]['fields'])->toBeEmpty();
    expect($decoded['sections'][1]['fields'][0]['id'])->toBe($moved);
});

it('rejects a move to an unknown section without touching the schema', function () {
    $component = dragBuilder();
    $before = $component->get('schema');
    $first = fieldIdsIn($component)[0];

    $component->call('selectField', $first);
    $component->call('moveField', $first, 'not-a-section', 0);

    expect($component->get('schema'))->toBe($before);
    expect($component->get('schemaError'))->not->toBeNull();
    expect($component->get('selectedFieldId'))->toBe($first);
});

it('rejects a move of an unknown field without touching the schema', function () {
    $component = dragBuilder();
    $before = $component->get('schema');

    $component->call('moveField', 'not-a-field', sectionIdAt($component, 0), 0);

    expect($component->get('schema'))->toBe($before);
    expect($component->get('schemaError'))->not->toBeNull();
});

it('keeps the schema valid across repeated movements', function () {
    $component = dragBuilder(3);
    $component->call('addSection');

    $sectionA = sectionIdAt($component, 0);
    $sectionB = sectionIdAt($component, 1);
    [$one, $two, $three] = fieldIdsIn($component, 0);

    $component
        ->call('moveField', $one, $sectionB, 0)
        ->call('moveField', $two, $sectionB, 0)
        ->call('moveField', $three, $sectionB, 2)
        ->call('moveField', $one, $sectionA, 0)
        ->call('moveField', $three, $sectionB, 0);

    expect(app(SchemaService::class)->isValid($component->get('schema')))->toBeTrue();
    expect($component->get('schemaError'))->toBeNull();
    expect(fieldIdsIn($component, 0))->toBe([$one]);
    expect(fieldIdsIn($component, 1))->toBe([$three, $two]);
});

it('still reorders with the arrow buttons through the shared helper', function () {
    $component = dragBuilder();
    [$first, $second, $third] = fieldIdsIn($component);

    $component->call('moveFieldDown', $first);
    expect(fieldIdsIn($component))->toBe([$second, $first, $third]);

    $component->call('moveFieldUp', $first);
    expect(fieldIdsIn($component))->toBe([$first, $second, $third]);

    $component->call('moveFieldDown', $third);
    expect(fieldIdsIn($component))->toBe([$first, $second, $third]);

    $component->call('moveFieldUp', $first);
    expect(fieldIdsIn($component))->toBe([$first, $second, $third]);
});
