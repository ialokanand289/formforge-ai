<?php

use App\Livewire\Forms\FormBuilder;
use App\Livewire\Forms\FormVersions;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function rollbackUser(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

/**
 * A form with a two-entry history: version 1 holds a "Full Name" field, and
 * version 2 adds "Phone Number". Rolling back to 1 should therefore drop the
 * phone field and produce version 3.
 *
 * @return array{0: User, 1: Form}
 */
function rollbackFixture(): array
{
    $owner = rollbackUser();
    $schemas = app(SchemaService::class);

    $original = $schemas->normalize([
        'title' => 'Client Intake',
        'sections' => [[
            'title' => 'Details',
            'fields' => [['type' => 'text', 'label' => 'Full Name']],
        ]],
    ]);

    $form = Form::factory()->for($owner)->create([
        'title' => 'Client Intake',
        'schema' => $original,
        'schema_version' => 1,
    ]);

    FormVersion::query()->create([
        'form_id' => $form->id,
        'version' => 1,
        'schema' => $original,
        'note' => 'Initial version',
        'created_by' => $owner->id,
        'created_at' => now(),
    ]);

    $updated = $original;
    $updated['sections'][0]['fields'][] = ['type' => 'phone', 'label' => 'Phone Number'];

    $schemas->save($form, $updated, $owner, 'Added phone');

    return [$owner, $form->refresh()];
}

function rollbackPage(User $actor, Form $form): Testable
{
    return Livewire::actingAs($actor)->test(FormVersions::class, ['form' => $form]);
}

function firstVersion(Form $form): FormVersion
{
    return $form->versions()->where('version', 1)->firstOrFail();
}

/**
 * @return list<string>
 */
function labelsOf(array $schema): array
{
    return collect($schema['sections'] ?? [])
        ->flatMap(fn (array $section): array => $section['fields'] ?? [])
        ->pluck('label')
        ->all();
}

test('rolling back restores the older schema onto the form', function () {
    [$owner, $form] = rollbackFixture();

    rollbackPage($owner, $form)->call('rollback', firstVersion($form)->id);

    $form->refresh();

    expect(labelsOf($form->schema))->toBe(['Full Name'])
        ->and($form->schema_version)->toBe(3);
});

test('rollback appends a version rather than reopening the old one', function () {
    [$owner, $form] = rollbackFixture();

    $target = firstVersion($form);

    rollbackPage($owner, $form)->call('rollback', $target->id);

    expect($form->versions()->count())->toBe(3)
        ->and($form->versions()->max('version'))->toBe(3);
});

test('the version being restored is left byte for byte identical', function () {
    [$owner, $form] = rollbackFixture();

    $target = firstVersion($form);
    $before = [
        'id' => $target->id,
        'version' => $target->version,
        'schema' => $target->schema,
        'note' => $target->note,
        'created_by' => $target->created_by,
        'created_at' => $target->created_at,
    ];
    $encoded = json_encode($target->getRawOriginal('schema'));

    rollbackPage($owner, $form)->call('rollback', $target->id);

    $after = $target->fresh();

    expect($after->version)->toBe($before['version'])
        ->and($after->schema)->toBe($before['schema'])
        ->and($after->note)->toBe($before['note'])
        ->and($after->created_by)->toBe($before['created_by'])
        ->and($after->created_at->eq($before['created_at']))->toBeTrue()
        ->and(json_encode($after->getRawOriginal('schema')))->toBe($encoded);
});

test('every earlier version survives the rollback untouched', function () {
    [$owner, $form] = rollbackFixture();

    $before = $form->versions()->orderBy('version')->get()
        ->mapWithKeys(fn (FormVersion $v): array => [$v->version => $v->schema])
        ->all();

    rollbackPage($owner, $form)->call('rollback', firstVersion($form)->id);

    $after = $form->versions()->orderBy('version')->get()
        ->mapWithKeys(fn (FormVersion $v): array => [$v->version => $v->schema])
        ->all();

    expect($after[1])->toBe($before[1])
        ->and($after[2])->toBe($before[2]);
});

test('the new version is numbered one above the current version', function () {
    [$owner, $form] = rollbackFixture();

    expect($form->schema_version)->toBe(2);

    rollbackPage($owner, $form)->call('rollback', firstVersion($form)->id);

    $newest = $form->versions()->orderByDesc('version')->first();

    expect($newest->version)->toBe(3)
        ->and($form->refresh()->schema_version)->toBe(3);
});

test('the new version records the schema it was rolled back to', function () {
    [$owner, $form] = rollbackFixture();

    $target = firstVersion($form);

    rollbackPage($owner, $form)->call('rollback', $target->id);

    $newest = $form->versions()->orderByDesc('version')->first();

    expect($newest->schema)->toBe($target->schema);
});

test('the new version is attributed to whoever performed the rollback', function () {
    [$owner, $form] = rollbackFixture();

    rollbackPage($owner, $form)->call('rollback', firstVersion($form)->id);

    $newest = $form->versions()->orderByDesc('version')->first();

    expect($newest->created_by)->toBe($owner->id)
        ->and($newest->note)->toBe('Rolled back to version 1');
});

test('rollback redirects to the builder and reports what happened', function () {
    [$owner, $form] = rollbackFixture();

    rollbackPage($owner, $form)
        ->call('rollback', firstVersion($form)->id)
        ->assertRedirect(route('forms.builder', $form));

    expect(session('builderMessage'))->toContain('Rolled back to version 1');
});

test('the builder reopens clean on the rolled back schema', function () {
    [$owner, $form] = rollbackFixture();

    rollbackPage($owner, $form)->call('rollback', firstVersion($form)->id);

    $builder = Livewire::actingAs($owner)->test(FormBuilder::class, ['form' => $form->refresh()]);

    $builder->assertSet('dirty', false)
        ->assertSee('Rolled back to version 1');

    expect(labelsOf($builder->get('schema')))->toBe(['Full Name'])
        ->and(labelsOf(json_decode($builder->get('schemaDraft'), true)))->toBe(['Full Name']);
});

test('rollback is refused when the form moved since the page was opened', function () {
    [$owner, $form] = rollbackFixture();

    $page = rollbackPage($owner, $form);

    // Another tab, or a queued job, saves in the meantime.
    $schemas = app(SchemaService::class);
    $elsewhere = $form->schema;
    $elsewhere['sections'][0]['fields'][] = ['type' => 'text', 'label' => 'Company'];
    $schemas->save($form->fresh(), $elsewhere, $owner, 'Saved elsewhere');

    $page->call('rollback', firstVersion($form)->id)
        ->assertNoRedirect()
        ->assertSet('rollbackError', 'This form changed since you opened this page. Reload and try again.');

    $form->refresh();

    expect($form->schema_version)->toBe(3)
        ->and(labelsOf($form->schema))->toContain('Company')
        ->and($form->versions()->count())->toBe(3);
});

test('a snapshot that is no longer valid is refused with a safe message', function () {
    [$owner, $form] = rollbackFixture();

    $broken = FormVersion::query()->create([
        'form_id' => $form->id,
        'version' => 99,
        // No title and a field with no usable type: validationErrors() rejects it.
        'schema' => ['sections' => [['title' => 'S', 'fields' => [['type' => 'not-a-type']]]]],
        'note' => 'Damaged',
        'created_by' => $owner->id,
        'created_at' => now(),
    ]);

    $before = $form->schema;

    rollbackPage($owner, $form)
        ->call('rollback', $broken->id)
        ->assertNoRedirect()
        ->assertSet('rollbackError', 'That version cannot be restored because its schema is no longer valid.')
        // No SQL, no exception text, nothing about the shape of the failure.
        ->assertDontSee('SQLSTATE');

    expect($form->refresh()->schema)->toBe($before)
        ->and($form->schema_version)->toBe(2)
        ->and($form->versions()->count())->toBe(3);
});

test('a version belonging to another form cannot be rolled into this one', function () {
    [$owner, $form] = rollbackFixture();
    [, $other] = rollbackFixture();

    $foreign = firstVersion($other);
    $before = $form->schema;

    rollbackPage($owner, $form)
        ->call('rollback', $foreign->id)
        ->assertSet('rollbackError', 'That version could not be found for this form.');

    expect($form->refresh()->schema)->toBe($before)
        ->and($form->schema_version)->toBe(2)
        ->and($form->versions()->count())->toBe(2);
});

test('a stranger cannot roll back someone elses form', function () {
    [, $form] = rollbackFixture();
    $stranger = rollbackUser();

    Livewire::actingAs($stranger)->test(FormVersions::class, ['form' => $form])
        ->assertForbidden();

    expect($form->refresh()->schema_version)->toBe(2);
});

test('a failure part way through leaves the form and its history untouched', function () {
    [$owner, $form] = rollbackFixture();

    $before = [
        'schema' => $form->schema,
        'version' => $form->schema_version,
        'versions' => $form->versions()->pluck('version')->all(),
    ];

    // Stand in for any unexpected failure inside the save transaction.
    $this->mock(SchemaService::class, function ($mock) {
        $mock->shouldReceive('validationErrors')->andReturn([]);
        $mock->shouldReceive('save')->andThrow(new RuntimeException('database went away'));
    });

    Log::spy();

    rollbackPage($owner, $form)
        ->call('rollback', firstVersion($form)->id)
        ->assertNoRedirect()
        ->assertSet('rollbackError', 'That version could not be restored. Please try again.')
        ->assertDontSee('database went away');

    Log::shouldHaveReceived('error')->once();

    $form->refresh();

    expect($form->schema)->toBe($before['schema'])
        ->and($form->schema_version)->toBe($before['version'])
        ->and($form->versions()->pluck('version')->all())->toBe($before['versions']);
});

test('rolling back twice in a row keeps appending rather than rewriting', function () {
    [$owner, $form] = rollbackFixture();

    rollbackPage($owner, $form)->call('rollback', firstVersion($form)->id);

    $form->refresh();

    rollbackPage($owner, $form)->call('rollback', $form->versions()->where('version', 2)->firstOrFail()->id);

    $form->refresh();

    expect($form->schema_version)->toBe(4)
        ->and($form->versions()->count())->toBe(4)
        ->and($form->versions()->pluck('version')->sort()->values()->all())->toBe([1, 2, 3, 4])
        ->and(labelsOf($form->schema))->toBe(['Full Name', 'Phone Number']);
});
