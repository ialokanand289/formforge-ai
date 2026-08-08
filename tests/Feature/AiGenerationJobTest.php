<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\ProcessAiGenerationJob;
use App\Models\AiGenerationLog;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'formforge.ai.api_key' => 'test-key',
        'formforge.ai.base_url' => 'https://api.openai.test/v1',
        'formforge.ai.max_repair_attempts' => 3,
    ]);

    Http::preventStrayRequests();
});

/**
 * A schema the pipeline should accept unchanged.
 */
function jobValidSchema(array $overrides = []): array
{
    return array_merge([
        'schema_version' => 1,
        'title' => 'Employee Registration',
        'description' => 'Join the team.',
        'settings' => [
            'multi_step' => false,
            'submit_button_text' => 'Register',
            'success_message' => 'Thanks.',
        ],
        'sections' => [[
            'title' => 'About you',
            'description' => null,
            'fields' => [
                ['type' => 'text', 'key' => 'full_name', 'label' => 'Full Name', 'required' => true],
                ['type' => 'email', 'key' => 'work_email', 'label' => 'Work Email', 'required' => true],
                ['type' => 'dropdown', 'key' => 'department', 'label' => 'Department', 'required' => false, 'options' => [
                    ['value' => 'hr', 'label' => 'HR'],
                    ['value' => 'engineering', 'label' => 'Engineering'],
                ]],
            ],
        ]],
    ], $overrides);
}

/**
 * Wrap raw model content in an OpenAI response envelope.
 */
function jobReply(string $content, int $promptTokens = 100, int $completionTokens = 250): array
{
    return [
        'model' => 'gpt-4o-mini-2024-07-18',
        'choices' => [['message' => ['content' => $content]]],
        'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens],
    ];
}

/**
 * Queue a sequence of fake responses, one per provider call.
 *
 * fakeSequence() registers itself, so wrapping it in another Http::fake() call
 * would leave two stubs draining the same sequence.
 */
function jobFakeSequence(array $contents): void
{
    $sequence = Http::fakeSequence();

    foreach ($contents as $content) {
        $sequence->push(jobReply($content));
    }
}

function jobLogFor(Form $form, User $owner, GenerationType $type = GenerationType::Generate, string $prompt = 'An employee registration form.'): AiGenerationLog
{
    return AiGenerationLog::factory()->for($owner)->for($form)->create([
        'type' => $type,
        'prompt' => $prompt,
        'status' => GenerationStatus::Queued,
    ]);
}

function jobRun(AiGenerationLog $log): AiGenerationLog
{
    app()->call([new ProcessAiGenerationJob($log->id), 'handle']);

    return $log->refresh();
}

/**
 * @return array{0: User, 1: Form, 2: array<string, mixed>} owner, form, and the
 *                                                          form's schema as it stood before the job ran
 */
function jobSetup(): array
{
    $owner = User::factory()->create();
    $form = Form::factory()->for($owner)->create(['title' => 'Untitled']);

    return [$owner, $form, $form->schema];
}

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

it('moves a queued log through processing to completed', function () {
    [$owner, $form] = jobSetup();

    $seen = null;
    Http::fake(function () use (&$seen) {
        $seen = AiGenerationLog::query()->sole()->status;

        return Http::response(jobReply(json_encode(jobValidSchema())));
    });

    $log = jobRun(jobLogFor($form, $owner));

    expect($seen)->toBe(GenerationStatus::Processing);
    expect($log->status)->toBe(GenerationStatus::Completed);
    expect($log->error_message)->toBeNull();
});

it('persists a valid schema through SchemaService and versions it once', function () {
    [$owner, $form] = jobSetup();

    $versionsBefore = FormVersion::query()->count();
    $schemaVersionBefore = $form->schema_version;

    jobFakeSequence([json_encode(jobValidSchema())]);

    $log = jobRun(jobLogFor($form, $owner));
    $form->refresh();

    expect($log->status)->toBe(GenerationStatus::Completed);
    expect($form->title)->toBe('Employee Registration');
    expect($form->schema_version)->toBe($schemaVersionBefore + 1);
    expect(FormVersion::query()->count())->toBe($versionsBefore + 1);

    $version = FormVersion::query()->where('form_id', $form->id)->latest('version')->sole();
    expect($version->schema['title'])->toBe('Employee Registration');
    expect($log->schema_result['title'])->toBe('Employee Registration');
});

it('accepts markdown wrapped json', function () {
    [$owner, $form] = jobSetup();

    jobFakeSequence(["```json\n".json_encode(jobValidSchema())."\n```"]);

    expect(jobRun(jobLogFor($form, $owner))->status)->toBe(GenerationStatus::Completed);
    expect($form->refresh()->title)->toBe('Employee Registration');
});

it('records the model tokens and latency across every call', function () {
    [$owner, $form] = jobSetup();

    Http::fakeSequence()
        ->push(jobReply('not json at all', 100, 200))
        ->push(jobReply(json_encode(jobValidSchema()), 300, 400));

    $log = jobRun(jobLogFor($form, $owner));

    expect($log->status)->toBe(GenerationStatus::Completed);
    expect($log->model)->toBe('gpt-4o-mini-2024-07-18');
    expect($log->prompt_tokens)->toBe(400);
    expect($log->completion_tokens)->toBe(600);
    expect($log->latency_ms)->toBeInt()->toBeGreaterThanOrEqual(0);
});

/*
|--------------------------------------------------------------------------
| Gates
|--------------------------------------------------------------------------
*/

it('fails on json it cannot parse rather than inventing a form', function () {
    [$owner, $form, $untouched] = jobSetup();
    config(['formforge.ai.max_repair_attempts' => 1]);

    jobFakeSequence(['I cannot help with that.', 'Still cannot help.']);

    $log = jobRun(jobLogFor($form, $owner));

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($log->error_message)->toContain('not a JSON object');
    expect($form->refresh()->schema)->toBe($untouched);
});

it('rejects a payload with no title or sections instead of normalizing one into existence', function () {
    [$owner, $form] = jobSetup();
    config(['formforge.ai.max_repair_attempts' => 0]);

    jobFakeSequence([json_encode(['message' => 'Here is your form!'])]);

    $log = jobRun(jobLogFor($form, $owner));

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($log->error_message)->toContain('non-empty title');
    expect(FormVersion::query()->count())->toBe(0);
});

it('rejects an unsupported field type rather than coercing it to text', function () {
    [$owner, $form, $untouched] = jobSetup();
    config(['formforge.ai.max_repair_attempts' => 0]);

    $schema = jobValidSchema();
    $schema['sections'][0]['fields'][0]['type'] = 'signature';

    jobFakeSequence([json_encode($schema)]);

    $log = jobRun(jobLogFor($form, $owner));

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($log->error_message)->toContain('Unsupported field type [signature]');
    expect($form->refresh()->schema)->toBe($untouched);
});

it('rejects a choice field with no options', function () {
    [$owner, $form, $untouched] = jobSetup();
    config(['formforge.ai.max_repair_attempts' => 0]);

    $schema = jobValidSchema();
    $schema['sections'][0]['fields'][2]['options'] = [];

    jobFakeSequence([json_encode($schema)]);

    $log = jobRun(jobLogFor($form, $owner));

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($form->refresh()->schema)->toBe($untouched);
});

it('rejects duplicate field keys rather than suffixing one', function () {
    [$owner, $form, $untouched] = jobSetup();
    config(['formforge.ai.max_repair_attempts' => 0]);

    $schema = jobValidSchema();
    $schema['sections'][0]['fields'][1]['key'] = 'full_name';

    jobFakeSequence([json_encode($schema)]);

    $log = jobRun(jobLogFor($form, $owner));

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($log->error_message)->toContain('Duplicate field key [full_name]');
    expect($form->refresh()->schema)->toBe($untouched);
});

/*
|--------------------------------------------------------------------------
| Repair budget
|--------------------------------------------------------------------------
*/

it('spends exactly one initial call plus the configured repair budget', function (int $repairs, int $expectedCalls) {
    [$owner, $form] = jobSetup();
    config(['formforge.ai.max_repair_attempts' => $repairs]);

    Http::fake(['*' => Http::response(jobReply('never valid json'))]);

    $log = jobRun(jobLogFor($form, $owner));

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($log->attempts)->toBe($expectedCalls);
    Http::assertSentCount($expectedCalls);
})->with([
    'three repairs allowed' => [3, 4],
    'no repairs allowed' => [0, 1],
    'one repair allowed' => [1, 2],
]);

it('completes on a repair and counts both calls', function () {
    [$owner, $form] = jobSetup();

    jobFakeSequence(['definitely not json', json_encode(jobValidSchema())]);

    $log = jobRun(jobLogFor($form, $owner));

    expect($log->status)->toBe(GenerationStatus::Completed);
    expect($log->attempts)->toBe(2);
    Http::assertSentCount(2);
});

it('hands the validation errors to the repair call', function () {
    [$owner, $form] = jobSetup();

    $broken = jobValidSchema();
    $broken['sections'][0]['fields'][0]['type'] = 'slider';

    jobFakeSequence([json_encode($broken), json_encode(jobValidSchema())]);

    jobRun(jobLogFor($form, $owner));

    $repair = Http::recorded()[1][0];

    expect($repair['messages'][1]['content'])->toContain('Unsupported field type [slider]');
    expect($repair['messages'][1]['content'])->toContain('sections.0.fields.0.type');
});

it('leaves the form untouched when the budget runs out', function () {
    [$owner, $form] = jobSetup();
    $schemas = app(SchemaService::class);

    $original = $schemas->save($form, jobValidSchema(['title' => 'Original']), $owner);
    $originalSchema = $original->schema;
    $originalVersion = $original->schema_version;
    $versionCount = FormVersion::query()->count();

    config(['formforge.ai.max_repair_attempts' => 1]);
    Http::fake(['*' => Http::response(jobReply('never valid'))]);

    $log = jobRun(jobLogFor($form, $owner, GenerationType::Edit, 'Add a phone field.'));
    $form->refresh();

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($form->schema)->toBe($originalSchema);
    expect($form->schema_version)->toBe($originalVersion);
    expect(FormVersion::query()->count())->toBe($versionCount);
});

/*
|--------------------------------------------------------------------------
| Provider and worker failures
|--------------------------------------------------------------------------
*/

it('marks the log failed with a safe message when the provider errors', function () {
    [$owner, $form, $untouched] = jobSetup();

    Http::fake(['*' => Http::response(['error' => ['message' => 'sk-secret in body']], 500)]);

    $log = jobRun(jobLogFor($form, $owner));

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($log->error_message)->toBe('The AI provider is unavailable. Try again shortly.');
    expect($log->error_message)->not->toContain('sk-secret');
    expect($form->refresh()->schema)->toBe($untouched);
});

it('marks the log failed when the worker dies', function () {
    [$owner, $form] = jobSetup();

    $log = jobLogFor($form, $owner);
    (new ProcessAiGenerationJob($log->id))->failed(new RuntimeException('worker exploded'));

    expect($log->refresh()->status)->toBe(GenerationStatus::Failed);
    expect($log->error_message)->toBe('The AI request did not finish. Please try again.');
    expect($log->error_message)->not->toContain('exploded');
});

it('does not overwrite a completed log from the failed hook', function () {
    [$owner, $form] = jobSetup();

    jobFakeSequence([json_encode(jobValidSchema())]);
    $log = jobRun(jobLogFor($form, $owner));

    (new ProcessAiGenerationJob($log->id))->failed(new RuntimeException('late failure'));

    expect($log->refresh()->status)->toBe(GenerationStatus::Completed);
});

it('ignores a log that is not queued', function () {
    [$owner, $form] = jobSetup();

    $log = jobLogFor($form, $owner);
    $log->forceFill(['status' => GenerationStatus::Completed])->save();

    jobRun($log);

    Http::assertNothingSent();
});

it('refuses to run when the log and the form have different owners', function () {
    [$owner, $form] = jobSetup();
    $intruder = User::factory()->create();

    $log = AiGenerationLog::factory()->for($intruder)->for($form)->create([
        'status' => GenerationStatus::Queued,
    ]);

    $log = jobRun($log);

    expect($log->status)->toBe(GenerationStatus::Failed);
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

it('sends the stored schema to the provider rather than anything from a browser', function () {
    [$owner, $form] = jobSetup();
    $schemas = app(SchemaService::class);

    $schemas->save($form, jobValidSchema(['title' => 'Stored Title']), $owner);
    $stored = $schemas->load($form->refresh());

    jobFakeSequence([json_encode($stored)]);

    jobRun(jobLogFor($form, $owner, GenerationType::Edit, 'Make the email optional.'));

    $sent = Http::recorded()[0][0];
    $userMessage = $sent['messages'][1]['content'];

    expect($userMessage)->toContain('Stored Title');
    expect($userMessage)->toContain($stored['sections'][0]['fields'][0]['id']);
    expect($userMessage)->toContain('Make the email optional.');
});

it('saves a valid edit and increments the version exactly once', function () {
    [$owner, $form] = jobSetup();
    $schemas = app(SchemaService::class);

    $schemas->save($form, jobValidSchema(), $owner);
    $form->refresh();

    $versionBefore = $form->schema_version;
    $versionCount = FormVersion::query()->count();

    $edited = $schemas->load($form);
    $edited['sections'][0]['fields'][] = [
        'type' => 'phone',
        'key' => 'phone_number',
        'label' => 'Phone Number',
        'required' => false,
    ];

    jobFakeSequence([json_encode($edited)]);

    $log = jobRun(jobLogFor($form, $owner, GenerationType::Edit, 'Add a phone number field.'));
    $form->refresh();

    expect($log->status)->toBe(GenerationStatus::Completed);
    expect($form->schema_version)->toBe($versionBefore + 1);
    expect(FormVersion::query()->count())->toBe($versionCount + 1);
    expect(collect($form->schema['sections'][0]['fields'])->pluck('key')->all())
        ->toBe(['full_name', 'work_email', 'department', 'phone_number']);
});

it('leaves the original schema byte identical when an edit fails', function () {
    [$owner, $form] = jobSetup();
    $schemas = app(SchemaService::class);

    $schemas->save($form, jobValidSchema(), $owner);
    $before = $form->refresh()->getAttributes();

    config(['formforge.ai.max_repair_attempts' => 0]);
    jobFakeSequence([json_encode(['title' => '', 'sections' => 'not an array'])]);

    $log = jobRun(jobLogFor($form, $owner, GenerationType::Edit, 'Break everything.'));
    $after = $form->refresh()->getAttributes();

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($after['schema'])->toBe($before['schema']);
    expect($after['schema_version'])->toBe($before['schema_version']);
    expect($after['updated_at'])->toBe($before['updated_at']);
});
