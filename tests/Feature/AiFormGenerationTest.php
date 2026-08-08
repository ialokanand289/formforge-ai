<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\ProcessAiGenerationJob;
use App\Livewire\Forms\FormBuilder;
use App\Models\AiGenerationLog;
use App\Models\Form;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'formforge.ai.api_key' => 'test-key-do-not-leak',
        'formforge.ai.base_url' => 'https://api.openai.test/v1',
        'formforge.queue.ai' => 'ai',
    ]);

    Http::preventStrayRequests();
});

/**
 * Distinct helper names: Pest loads every test file into one process.
 */
function aiOwner(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

function aiForm(User $owner): Form
{
    return Form::factory()->for($owner)->create(['title' => 'Client Intake']);
}

function aiBuilder(User $actor, Form $form)
{
    return Livewire::actingAs($actor)->test(FormBuilder::class, ['form' => $form]);
}

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('lets an owner queue a generation', function () {
    Bus::fake();

    $owner = aiOwner();
    $form = aiForm($owner);

    aiBuilder($owner, $form)
        ->set('aiPrompt', 'An employee registration form with name and email.')
        ->call('runAi')
        ->assertHasNoErrors();

    $log = AiGenerationLog::query()->sole();

    expect($log->user_id)->toBe($owner->id);
    expect($log->form_id)->toBe($form->id);
    expect($log->type)->toBe(GenerationType::Generate);
    expect($log->status)->toBe(GenerationStatus::Queued);
    expect($log->prompt)->toBe('An employee registration form with name and email.');
});

it('dispatches the job onto the configured ai queue', function () {
    Bus::fake();

    $owner = aiOwner();
    $form = aiForm($owner);

    aiBuilder($owner, $form)->set('aiPrompt', 'A contact form.')->call('runAi');

    Bus::assertDispatched(ProcessAiGenerationJob::class, function (ProcessAiGenerationJob $job): bool {
        expect($job->queue)->toBe('ai');
        expect($job->logId)->toBe(AiGenerationLog::query()->sole()->id);

        return true;
    });
});

it('redirects a guest away from the builder', function () {
    $owner = aiOwner();
    $form = aiForm($owner);

    test()->get(route('forms.builder', $form))->assertRedirect(route('login'));
});

it('forbids a non owner from mounting the builder', function () {
    $owner = aiOwner();
    $intruder = aiOwner();
    $form = aiForm($owner);

    Livewire::actingAs($intruder)
        ->test(FormBuilder::class, ['form' => $form])
        ->assertForbidden();
});

it('records the signed in user rather than anything the browser sends', function () {
    Bus::fake();

    $owner = aiOwner();
    $other = aiOwner();
    $form = aiForm($owner);

    aiBuilder($owner, $form)
        ->set('aiPrompt', 'A contact form.')
        // There is no user_id property to set; prove the log ignores the attempt.
        ->set('aiMode', 'generate')
        ->call('runAi');

    expect(AiGenerationLog::query()->sole()->user_id)
        ->toBe($owner->id)
        ->not->toBe($other->id);
});

it('keeps the tracked log id server controlled', function () {
    $owner = aiOwner();
    $form = aiForm($owner);

    $component = aiBuilder($owner, $form);

    expect(fn () => $component->set('aiLogId', 'forged-id'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('cannot poll another users log', function () {
    Bus::fake();

    $victim = aiOwner();
    $victimForm = aiForm($victim);
    $victimLog = AiGenerationLog::factory()->for($victim)->for($victimForm)->create([
        'status' => GenerationStatus::Completed,
    ]);

    $intruder = aiOwner();
    $intruderForm = aiForm($intruder);

    // The property is #[Locked], so reach past Livewire to prove the query is
    // also scoped rather than relying on the lock alone.
    $component = aiBuilder($intruder, $intruderForm);
    $component->instance()->aiLogId = $victimLog->id;
    $component->call('pollAi');

    expect($component->get('aiStatus'))->toBeNull();
    expect($component->get('aiMessage'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Guards
|--------------------------------------------------------------------------
*/

it('refuses when no api key is configured', function () {
    Bus::fake();
    config(['formforge.ai.api_key' => '']);

    $owner = aiOwner();
    $form = aiForm($owner);

    aiBuilder($owner, $form)
        ->set('aiPrompt', 'A contact form.')
        ->call('runAi')
        ->assertSet('aiError', 'AI is not configured on this server.');

    expect(AiGenerationLog::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('refuses while the builder has unsaved changes', function () {
    Bus::fake();

    $owner = aiOwner();
    $form = aiForm($owner);

    aiBuilder($owner, $form)
        ->call('addField', 'text')
        ->assertSet('dirty', true)
        ->set('aiPrompt', 'A contact form.')
        ->call('runAi')
        ->assertSet('aiError', 'Save or discard your unsaved changes before running AI.');

    expect(AiGenerationLog::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('refuses a second request while one is in flight', function () {
    Bus::fake();

    $owner = aiOwner();
    $form = aiForm($owner);

    AiGenerationLog::factory()->for($owner)->for($form)->create([
        'status' => GenerationStatus::Processing,
    ]);

    aiBuilder($owner, $form)
        ->set('aiPrompt', 'A contact form.')
        ->call('runAi')
        ->assertSet('aiError', 'An AI request is already running for this form.');

    expect(AiGenerationLog::query()->count())->toBe(1);
    Bus::assertNothingDispatched();
});

it('rejects an empty or oversized prompt', function () {
    Bus::fake();
    config(['formforge.ai.max_prompt_chars' => 50]);

    $owner = aiOwner();
    $form = aiForm($owner);

    aiBuilder($owner, $form)
        ->set('aiPrompt', '')
        ->call('runAi')
        ->assertHasErrors(['aiPrompt' => 'required']);

    aiBuilder($owner, $form)
        ->set('aiPrompt', Str::repeat('a', 51))
        ->call('runAi')
        ->assertHasErrors(['aiPrompt' => 'max']);

    expect(AiGenerationLog::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

/*
|--------------------------------------------------------------------------
| Async state
|--------------------------------------------------------------------------
*/

it('polls only while a request is in flight', function () {
    Bus::fake();

    $owner = aiOwner();
    $form = aiForm($owner);

    $component = aiBuilder($owner, $form)
        ->call('toggleAiPanel')
        ->set('aiPrompt', 'A contact form.')
        ->call('runAi');

    $component->assertSet('aiStatus', GenerationStatus::Queued->value)
        ->assertSee('wire:poll.2s', false);

    AiGenerationLog::query()->sole()->forceFill([
        'status' => GenerationStatus::Failed,
        'error_message' => 'The AI provider is unavailable. Try again shortly.',
    ])->save();

    $component->call('pollAi')
        ->assertSet('aiStatus', GenerationStatus::Failed->value)
        ->assertSet('aiError', 'The AI provider is unavailable. Try again shortly.')
        ->assertDontSee('wire:poll.2s', false);
});

it('reloads the schema and names the changes when a generation completes', function () {
    Bus::fake();

    $owner = aiOwner();
    $form = aiForm($owner);

    $component = aiBuilder($owner, $form)
        ->set('aiPrompt', 'A contact form.')
        ->call('runAi');

    // Stand in for the worker: save a schema and mark the log completed.
    app(SchemaService::class)->save($form, [
        'schema_version' => 1,
        'title' => 'Employee Registration',
        'description' => '',
        'settings' => [],
        'sections' => [[
            'title' => 'About you',
            'fields' => [
                ['type' => 'text', 'key' => 'full_name', 'label' => 'Full Name'],
                ['type' => 'email', 'key' => 'work_email', 'label' => 'Work Email'],
            ],
        ]],
    ], $owner);

    AiGenerationLog::query()->sole()->forceFill(['status' => GenerationStatus::Completed])->save();

    $component->call('pollAi')
        ->assertSet('aiStatus', GenerationStatus::Completed->value)
        ->assertSet('dirty', false)
        ->assertSet('title', 'Employee Registration')
        ->assertSet('aiPrompt', '');

    expect($component->get('aiMessage'))
        ->toContain('Fields added: full_name, work_email.');

    $schema = $component->get('schema');
    expect($schema['sections'][0]['fields'][0]['key'])->toBe('full_name');
});

it('names removed fields so a deletion is never silent', function () {
    Bus::fake();

    $owner = aiOwner();
    $form = aiForm($owner);
    $schemas = app(SchemaService::class);

    $schemas->save($form, [
        'schema_version' => 1,
        'title' => 'Client Intake',
        'description' => '',
        'settings' => [],
        'sections' => [[
            'title' => 'About you',
            'fields' => [
                ['type' => 'text', 'key' => 'full_name', 'label' => 'Full Name'],
                ['type' => 'number', 'key' => 'age', 'label' => 'Age'],
            ],
        ]],
    ], $owner);

    $component = aiBuilder($owner, $form->refresh())
        ->set('aiMode', 'edit')
        ->set('aiPrompt', 'Remove the age field.')
        ->call('runAi');

    $current = $schemas->load($form->refresh());
    $current['sections'][0]['fields'] = [$current['sections'][0]['fields'][0]];
    $schemas->save($form, $current, $owner);

    AiGenerationLog::query()->sole()->forceFill(['status' => GenerationStatus::Completed])->save();

    $component->call('pollAi');

    expect($component->get('aiMessage'))->toContain('Field removed: age.');
});

/*
|--------------------------------------------------------------------------
| Security
|--------------------------------------------------------------------------
*/

it('never renders the api key', function () {
    $owner = aiOwner();
    $form = aiForm($owner);

    $html = aiBuilder($owner, $form)->call('toggleAiPanel')->html();

    expect($html)->not->toContain('test-key-do-not-leak');
    expect($html)->not->toContain('api.openai.test');
});

it('escapes ai authored labels', function () {
    Bus::fake();

    $owner = aiOwner();
    $form = aiForm($owner);

    app(SchemaService::class)->save($form, [
        'schema_version' => 1,
        'title' => 'Injected',
        'description' => '',
        'settings' => [],
        'sections' => [[
            'title' => 'About you',
            'fields' => [
                ['type' => 'text', 'key' => 'evil', 'label' => '<script>alert(1)</script>'],
            ],
        ]],
    ], $owner);

    $html = aiBuilder($owner, $form->refresh())->html();

    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('&lt;script&gt;');
});

it('enables the ai toolbar button and opens the panel', function () {
    $owner = aiOwner();
    $form = aiForm($owner);

    $component = aiBuilder($owner, $form);

    expect($component->html())->toContain('wire:click="toggleAiPanel"');

    $component->call('toggleAiPanel')
        ->assertSet('aiPanelOpen', true)
        ->assertSee('Build with AI');
});
