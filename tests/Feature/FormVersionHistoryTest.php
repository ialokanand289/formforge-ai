<?php

use App\Livewire\Forms\FormVersions;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function historyUser(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

/**
 * A form sitting at version 1 with the matching snapshot on record, which is
 * the state the seeder leaves a new form in.
 */
function historyForm(User $owner, string $title = 'Client Intake'): Form
{
    $form = Form::factory()->for($owner)->create([
        'title' => $title,
        'schema' => app(SchemaService::class)->blank($title),
        'schema_version' => 1,
    ]);

    FormVersion::query()->create([
        'form_id' => $form->id,
        'version' => 1,
        'schema' => $form->schema,
        'note' => 'Initial version',
        'created_by' => $owner->id,
        'created_at' => now(),
    ]);

    return $form->refresh();
}

/**
 * Append a version by saving a schema with one extra named field.
 */
function historySave(Form $form, User $actor, string $label, ?string $note = null): Form
{
    $schemas = app(SchemaService::class);
    $schema = $form->schema;
    $schema['sections'][0]['fields'][] = ['type' => 'text', 'label' => $label];

    $schemas->save($form, $schema, $actor, $note);

    return $form->refresh();
}

function historyPage(User $actor, Form $form): Testable
{
    return Livewire::actingAs($actor)->test(FormVersions::class, ['form' => $form]);
}

/**
 * Every write statement issued while the callback runs.
 *
 * @return list<string>
 */
function historyWrites(callable $callback): array
{
    $writes = [];

    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $callback();

    return $writes;
}

test('the versions page lists every saved version newest first', function () {
    $owner = historyUser();
    $form = historyForm($owner);
    $form = historySave($form, $owner, 'Email', 'Added email');
    $form = historySave($form, $owner, 'Phone', 'Added phone');

    $listed = historyPage($owner, $form)->instance()->versions();

    expect($listed->pluck('version')->all())->toBe([3, 2, 1])
        ->and($listed->total())->toBe(3);

    historyPage($owner, $form)
        ->assertSee('Version 3')
        ->assertSee('Version 1')
        ->assertSee('Added phone')
        ->assertSee($owner->name)
        ->assertSee('Current')
        ->assertSeeInOrder(['Version 3', 'Version 2', 'Version 1']);
});

test('the version list is paginated at fifteen so a long history stays usable', function () {
    $owner = historyUser();
    $form = historyForm($owner);

    // Snapshots written directly: this is about paging, not about saving.
    foreach (range(2, 18) as $version) {
        FormVersion::factory()->for($form)->create([
            'version' => $version,
            'created_by' => $owner->id,
        ]);
    }

    $page = historyPage($owner, $form)->instance()->versions();

    expect($page->perPage())->toBe(15)
        ->and($page->total())->toBe(18)
        ->and($page->count())->toBe(15)
        ->and($page->first()->version)->toBe(18);
});

test('a creator who no longer exists reads as unknown rather than breaking the page', function () {
    $owner = historyUser();
    $form = historyForm($owner);

    $form->versions()->first()->forceFill(['created_by' => null])->save();

    historyPage($owner, $form)->assertOk()->assertSee('Unknown');
});

test('viewing a version renders that snapshot read only', function () {
    $owner = historyUser();
    $form = historyForm($owner);
    $form = historySave($form, $owner, 'Favourite Colour');

    $first = $form->versions()->where('version', 1)->firstOrFail();

    $component = historyPage($owner, $form)->call('viewVersion', $first->id);

    expect($component->get('viewingId'))->toBe($first->id);

    $viewing = $component->instance()->viewing();

    expect($viewing['version'])->toBe(1)
        ->and($viewing['creator'])->toBe($owner->name)
        // The field only exists in version 2, so it must not appear here.
        ->and(collect($viewing['sections'])->flatMap(fn ($s) => $s['fields'])->pluck('label'))
        ->not->toContain('Favourite Colour');
});

test('a stored snapshot is rendered as written rather than repaired', function () {
    $owner = historyUser();
    $form = historyForm($owner);

    // A legacy row: no ids, no settings, keys that normalize() would rewrite.
    $legacy = FormVersion::factory()->for($form)->create([
        'version' => 2,
        'created_by' => $owner->id,
        'schema' => [
            'title' => 'Legacy Form',
            'sections' => [[
                'title' => 'Old Section',
                'fields' => [['type' => 'text', 'key' => 'Some Key', 'label' => 'Legacy Question']],
            ]],
        ],
    ]);

    $viewing = historyPage($owner, $form)
        ->call('viewVersion', $legacy->id)
        ->instance()
        ->viewing();

    expect($viewing['title'])->toBe('Legacy Form')
        ->and($viewing['sections'][0]['fields'][0]['key'])->toBe('Some Key')
        ->and($viewing['sections'][0]['fields'][0]['label'])->toBe('Legacy Question');

    // Untouched on disk, which is the whole point of a snapshot.
    expect($legacy->fresh()->schema)->toBe($legacy->schema);
});

test('a badly damaged snapshot still renders instead of throwing', function () {
    $owner = historyUser();
    $form = historyForm($owner);

    $damaged = FormVersion::factory()->for($form)->create([
        'version' => 2,
        'created_by' => $owner->id,
        'schema' => [
            'title' => ['not', 'a', 'string'],
            'sections' => ['garbage', 7, ['fields' => ['also garbage', ['type' => 'text']]]],
        ],
    ]);

    $viewing = historyPage($owner, $form)
        ->assertOk()
        ->call('viewVersion', $damaged->id)
        ->assertOk()
        ->instance()
        ->viewing();

    expect($viewing['title'])->toBe('Untitled Form')
        ->and($viewing['sections'])->toHaveCount(1)
        ->and($viewing['sections'][0]['fields'][0]['label'])->toBe('Untitled Field');
});

test('comparing a version describes what changed since it was saved', function () {
    $owner = historyUser();
    $form = historyForm($owner);
    $form = historySave($form, $owner, 'Phone Number');

    $first = $form->versions()->where('version', 1)->firstOrFail();

    $comparison = historyPage($owner, $form)
        ->call('compareVersion', $first->id)
        ->instance()
        ->comparison();

    expect($comparison['version'])->toBe(1)
        ->and($comparison['diff']['summary']['has_changes'])->toBeTrue()
        ->and($comparison['diff']['summary']['fields_added'])->toBe(1);

    $added = collect($comparison['diff']['sections'])
        ->flatMap(fn (array $section): array => $section['fields'])
        ->firstWhere('label', 'Phone Number');

    expect($added['status'])->toBe('added');
});

test('viewing and comparing clear one another so only one panel is open', function () {
    $owner = historyUser();
    $form = historyForm($owner);
    $form = historySave($form, $owner, 'Phone');

    $first = $form->versions()->where('version', 1)->firstOrFail();

    $component = historyPage($owner, $form)
        ->call('viewVersion', $first->id)
        ->assertSet('comparingId', null)
        ->call('compareVersion', $first->id)
        ->assertSet('viewingId', null)
        ->call('closePanel');

    $component->assertSet('viewingId', null)->assertSet('comparingId', null);
});

test('viewing a version writes nothing to the database', function () {
    $owner = historyUser();
    $form = historyForm($owner);
    $form = historySave($form, $owner, 'Phone');

    $first = $form->versions()->where('version', 1)->firstOrFail();
    $before = $form->fresh()->only(['schema', 'schema_version', 'updated_at']);

    $writes = historyWrites(function () use ($owner, $form, $first) {
        historyPage($owner, $form)->call('viewVersion', $first->id)->instance()->viewing();
    });

    expect($writes)->toBe([])
        ->and($form->fresh()->only(['schema', 'schema_version', 'updated_at']))->toEqual($before)
        ->and($form->versions()->count())->toBe(2);
});

test('comparing a version writes nothing to the database', function () {
    $owner = historyUser();
    $form = historyForm($owner);
    $form = historySave($form, $owner, 'Phone');

    $first = $form->versions()->where('version', 1)->firstOrFail();
    $snapshot = $first->schema;

    $writes = historyWrites(function () use ($owner, $form, $first) {
        historyPage($owner, $form)->call('compareVersion', $first->id)->instance()->comparison();
    });

    expect($writes)->toBe([])
        ->and($first->fresh()->schema)->toBe($snapshot);
});

test('another users form is not reachable through the versions page', function () {
    $owner = historyUser();
    $stranger = historyUser();
    $form = historyForm($owner);

    Livewire::actingAs($stranger)->test(FormVersions::class, ['form' => $form])
        ->assertForbidden();

    $this->actingAs($stranger)->get(route('forms.versions', $form))->assertForbidden();
});

test('the versions page requires a signed in user', function () {
    $form = historyForm(historyUser());

    $this->get(route('forms.versions', $form))->assertRedirect(route('login'));
});

test('a version belonging to another form can never be opened', function () {
    $owner = historyUser();
    $form = historyForm($owner, 'Client Intake');
    $other = historyForm($owner, 'Event Signup');

    $foreign = $other->versions()->firstOrFail();

    // Locked ids still cannot be trusted alone, so every lookup is scoped to
    // the mounted form's own relation.
    $component = historyPage($owner, $form)->call('viewVersion', $foreign->id);

    expect($component->get('viewingId'))->toBeNull()
        ->and($component->instance()->viewing())->toBeNull();

    $component->call('compareVersion', $foreign->id);

    expect($component->get('comparingId'))->toBeNull()
        ->and($component->instance()->comparison())->toBeNull();
});

test('a form with no versions yet shows an empty history', function () {
    $owner = historyUser();
    $form = Form::factory()->for($owner)->create();

    historyPage($owner, $form)
        ->assertOk()
        ->assertSee('No versions yet');
});
