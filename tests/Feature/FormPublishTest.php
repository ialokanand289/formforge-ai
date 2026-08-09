<?php

use App\Enums\FormStatus;
use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function publishUser(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

/**
 * A draft form carrying one real question, so the public renderer has
 * something to draw once it is reachable.
 */
function publishForm(User $owner, array $attributes = []): Form
{
    $schema = app(SchemaService::class)->normalize([
        'title' => 'Client Intake',
        'sections' => [[
            'title' => 'Details',
            'fields' => [['type' => 'text', 'key' => 'full_name', 'label' => 'Full Name']],
        ]],
    ]);

    return Form::factory()->for($owner)->create(array_merge([
        'title' => 'Client Intake',
        'schema' => $schema,
        'schema_version' => 1,
    ], $attributes));
}

function publishBuilder(User $actor, Form $form): Testable
{
    return Livewire::actingAs($actor)->test(FormBuilder::class, ['form' => $form]);
}

test('publishing marks the form published and stamps the time', function () {
    $owner = publishUser();
    $form = publishForm($owner);

    publishBuilder($owner, $form)
        ->call('publish')
        ->assertSet('status', FormStatus::Published->value)
        ->assertSee('Form published.');

    $form->refresh();

    expect($form->status)->toBe(FormStatus::Published)
        ->and($form->published_at)->not->toBeNull();
});

test('a published form is reachable at its public link by anyone', function () {
    $owner = publishUser();
    $form = publishForm($owner);

    $this->get(route('forms.public', $form->public_token))->assertNotFound();

    publishBuilder($owner, $form)->call('publish');

    $this->get(route('forms.public', $form->refresh()->public_token))
        ->assertOk()
        ->assertSee('Full Name');
});

test('the builder shows the public link once the form is published', function () {
    $owner = publishUser();
    $form = publishForm($owner);

    $builder = publishBuilder($owner, $form);

    expect($builder->instance()->publicUrl())->toBeNull();

    $builder->call('publish');

    $url = route('forms.public', $form->refresh()->public_token);

    expect($builder->instance()->publicUrl())->toBe($url);

    $builder->assertSee($url);
});

test('unpublishing returns the form to draft and takes the link offline', function () {
    $owner = publishUser();
    $form = publishForm($owner, ['status' => FormStatus::Published, 'published_at' => now()]);

    publishBuilder($owner, $form)
        ->call('unpublish')
        ->assertSet('status', FormStatus::Draft->value)
        ->assertSee('public link no longer works');

    $form->refresh();

    expect($form->status)->toBe(FormStatus::Draft)
        ->and($form->published_at)->toBeNull();

    $this->get(route('forms.public', $form->public_token))->assertNotFound();
});

test('publishing changes visibility only and never the schema or its history', function () {
    $owner = publishUser();
    $form = publishForm($owner);

    $schema = $form->schema;
    $version = $form->schema_version;

    publishBuilder($owner, $form)->call('publish');
    publishBuilder($owner, $form->refresh())->call('unpublish');

    $form->refresh();

    expect($form->schema)->toBe($schema)
        ->and($form->schema_version)->toBe($version)
        // Publishing is a decision about who can see the form, not about what
        // the form says, so it has nothing to record in the history.
        ->and($form->versions()->count())->toBe(0);
});

test('publishing leaves unsaved builder changes in place', function () {
    $owner = publishUser();
    $form = publishForm($owner);

    $builder = publishBuilder($owner, $form)
        ->call('addSection')
        ->assertSet('dirty', true)
        ->call('publish');

    // Publishing is not a save, so the pending edit must survive it rather
    // than be silently committed or thrown away.
    $builder->assertSet('dirty', true);

    expect(count($builder->get('schema')['sections']))->toBe(2)
        ->and(count($form->refresh()->schema['sections']))->toBe(1)
        ->and($form->status)->toBe(FormStatus::Published);
});

test('a stranger cannot publish or unpublish someone elses form', function () {
    $owner = publishUser();
    $stranger = publishUser();
    $form = publishForm($owner);

    Livewire::actingAs($stranger)->test(FormBuilder::class, ['form' => $form])
        ->assertForbidden();

    expect($form->refresh()->status)->toBe(FormStatus::Draft);
});

test('the forms index shows the public link only for published forms', function () {
    $owner = publishUser();
    $draft = publishForm($owner);
    $published = publishForm($owner, ['status' => FormStatus::Published, 'published_at' => now()]);

    $this->actingAs($owner)->get(route('forms.index'))
        ->assertOk()
        ->assertSee(route('forms.public', $published->public_token))
        ->assertDontSee(route('forms.public', $draft->public_token))
        ->assertSee('published')
        ->assertSee(route('forms.versions', $published))
        ->assertSee(route('forms.submissions.index', $published));
});

test('an archived form is not reachable publicly', function () {
    $owner = publishUser();
    $form = publishForm($owner, ['status' => FormStatus::Archived, 'published_at' => now()]);

    $this->get(route('forms.public', $form->public_token))->assertNotFound();
});

test('an unknown public token is not found rather than revealing anything', function () {
    $this->get(route('forms.public', (string) Str::uuid()))->assertNotFound();
});
