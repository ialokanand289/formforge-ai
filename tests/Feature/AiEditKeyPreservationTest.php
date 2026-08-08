<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\ProcessAiGenerationJob;
use App\Models\AiGenerationLog;
use App\Models\Form;
use App\Models\FormSubmission;
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
 * A saved form whose fields already carry stable ids and keys, which is the
 * only situation where key preservation means anything.
 *
 * @return array{0: User, 1: Form, 2: array<string, mixed>}
 */
function keyFixture(): array
{
    $owner = User::factory()->create();
    $form = Form::factory()->for($owner)->create(['title' => 'Client Intake']);

    $schemas = app(SchemaService::class);

    $schemas->save($form, [
        'schema_version' => 1,
        'title' => 'Client Intake',
        'description' => '',
        'settings' => [
            'multi_step' => false,
            'submit_button_text' => 'Submit',
            'success_message' => 'Thanks.',
        ],
        'sections' => [[
            'title' => 'About you',
            'description' => null,
            'fields' => [
                ['type' => 'text', 'key' => 'full_name', 'label' => 'Full Name', 'required' => true],
                ['type' => 'email', 'key' => 'work_email', 'label' => 'Work Email', 'required' => false],
                ['type' => 'number', 'key' => 'age', 'label' => 'Age', 'required' => false],
            ],
        ]],
    ], $owner);

    $form->refresh();

    return [$owner, $form, $schemas->load($form)];
}

function keyReply(array $schema): array
{
    return [
        'model' => 'gpt-4o-mini',
        'choices' => [['message' => ['content' => json_encode($schema)]]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
    ];
}

/**
 * Queue every reply a test will need, in call order.
 *
 * Call this once per test: a second fakeSequence() leaves the first, now
 * exhausted, stub in front of the new one.
 */
function keyPushReplies(array $schemas): void
{
    $sequence = Http::fakeSequence();

    foreach ($schemas as $schema) {
        $sequence->push(keyReply($schema));
    }
}

function keyRunEdit(Form $form, User $owner, string $instruction = 'Tidy up the form.'): AiGenerationLog
{
    $log = AiGenerationLog::factory()->for($owner)->for($form)->create([
        'type' => GenerationType::Edit,
        'prompt' => $instruction,
        'status' => GenerationStatus::Queued,
    ]);

    app()->call([new ProcessAiGenerationJob($log->id), 'handle']);

    return $log->refresh();
}

/**
 * @return list<string>
 */
function keyList(array $schema): array
{
    return collect($schema['sections'])
        ->flatMap(fn (array $section): array => $section['fields'])
        ->pluck('key')
        ->all();
}

/*
|--------------------------------------------------------------------------
| The gate rejects a rename
|--------------------------------------------------------------------------
*/

it('rejects a retained field whose key changed and asks the model to restore it', function () {
    [$owner, $form, $stored] = keyFixture();

    $renamed = $stored;
    $renamed['sections'][0]['fields'][1]['key'] = 'email_address';

    keyPushReplies([$renamed, $stored]);

    $log = keyRunEdit($form, $owner, 'Make the email required.');

    expect($log->status)->toBe(GenerationStatus::Completed);
    expect($log->attempts)->toBe(2);

    $repair = Http::recorded()[1][0]['messages'][1]['content'];

    expect($repair)->toContain('expected [work_email]');
    expect($repair)->toContain('received [email_address]');
    expect($repair)->toContain('sections.0.fields.1.key');
});

it('leaves the form untouched when a rename survives the repair budget', function () {
    [$owner, $form, $stored] = keyFixture();

    $before = $form->getAttributes();

    $renamed = $stored;
    $renamed['sections'][0]['fields'][1]['key'] = 'email_address';

    config(['formforge.ai.max_repair_attempts' => 2]);
    keyPushReplies([$renamed, $renamed, $renamed]);

    $log = keyRunEdit($form, $owner, 'Make the email required.');
    $after = $form->refresh()->getAttributes();

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($log->attempts)->toBe(3);
    expect($log->error_message)->toContain('Field key must not change');
    expect($after['schema'])->toBe($before['schema']);
    expect($after['schema_version'])->toBe($before['schema_version']);
});

it('catches a camelCase key before normalize can slugify it through', function () {
    [$owner, $form, $stored] = keyFixture();

    // slugifyKey() would turn this into "fullname" and save it as if nothing
    // happened, which is the exact failure the pre-normalize pass exists for.
    $renamed = $stored;
    $renamed['sections'][0]['fields'][0]['key'] = 'fullName';

    config(['formforge.ai.max_repair_attempts' => 0]);
    keyPushReplies([$renamed]);

    $log = keyRunEdit($form, $owner, 'Capitalise the name label.');

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($log->error_message)->toContain('expected [full_name]');
    expect($log->error_message)->toContain('received [fullName]');
    expect(keyList($form->refresh()->schema))->toBe(['full_name', 'work_email', 'age']);
});

it('rejects a duplicate key rather than letting uniqueKey suffix it', function () {
    [$owner, $form, $stored] = keyFixture();

    // A brand new field claiming an existing key, so the duplicate gate is the
    // only rule broken and the failure cannot be attributed to preservation.
    $duplicated = $stored;
    $duplicated['sections'][0]['fields'][] = [
        'type' => 'text',
        'key' => 'full_name',
        'label' => 'Name Again',
        'required' => false,
    ];

    config(['formforge.ai.max_repair_attempts' => 0]);
    keyPushReplies([$duplicated]);

    $log = keyRunEdit($form, $owner, 'Fix the age field.');

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($log->error_message)->toContain('Duplicate field key [full_name]');
    expect(keyList($form->refresh()->schema))->toBe(['full_name', 'work_email', 'age']);
    expect(json_encode($form->schema))->not->toContain('full_name_2');
});

it('catches a key that only normalize would have stolen', function () {
    [$owner, $form, $stored] = keyFixture();

    // Raw keys here are "Full Name" and "full_name", which are not duplicates,
    // so the pre-normalize pass has nothing to object to. normalize() assigns
    // keys in document order: the new field slugifies to full_name and claims
    // it, and uniqueKey() pushes the real full_name field to full_name_2.
    // Only the pass that runs against normalize() output can see that.
    $stolen = $stored;
    array_unshift($stolen['sections'][0]['fields'], [
        'type' => 'text',
        'key' => 'Full Name',
        'label' => 'Full Name',
        'required' => false,
    ]);

    config(['formforge.ai.max_repair_attempts' => 0]);
    keyPushReplies([$stolen]);

    $log = keyRunEdit($form, $owner, 'Add another name field.');

    expect($log->status)->toBe(GenerationStatus::Failed);
    expect($log->error_message)->toContain('expected [full_name]');
    expect($log->error_message)->toContain('received [full_name_2]');
    expect(keyList($form->refresh()->schema))->toBe(['full_name', 'work_email', 'age']);
});

/*
|--------------------------------------------------------------------------
| The gate allows legitimate change
|--------------------------------------------------------------------------
*/

it('allows a removal', function () {
    [$owner, $form, $stored] = keyFixture();

    $trimmed = $stored;
    array_splice($trimmed['sections'][0]['fields'], 2, 1);

    keyPushReplies([$trimmed]);

    $log = keyRunEdit($form, $owner, 'Remove the age field.');

    expect($log->status)->toBe(GenerationStatus::Completed);
    expect($log->attempts)->toBe(1);
    expect(keyList($form->refresh()->schema))->toBe(['full_name', 'work_email']);
});

it('allows an addition without disturbing existing keys', function () {
    [$owner, $form, $stored] = keyFixture();

    $extended = $stored;
    $extended['sections'][0]['fields'][] = [
        'type' => 'phone',
        'key' => 'phone_number',
        'label' => 'Phone Number',
        'required' => false,
    ];

    keyPushReplies([$extended]);

    $log = keyRunEdit($form, $owner, 'Add a phone number field.');

    expect($log->status)->toBe(GenerationStatus::Completed);
    expect(keyList($form->refresh()->schema))
        ->toBe(['full_name', 'work_email', 'age', 'phone_number']);
});

it('allows a deliberate replacement expressed as an omission plus an id-less addition', function () {
    [$owner, $form, $stored] = keyFixture();

    $replaced = $stored;
    array_splice($replaced['sections'][0]['fields'], 2, 1);
    $replaced['sections'][0]['fields'][] = [
        'type' => 'date',
        'key' => 'date_of_birth',
        'label' => 'Date of Birth',
        'required' => false,
    ];

    keyPushReplies([$replaced]);

    $log = keyRunEdit($form, $owner, 'Replace age with a date of birth.');

    expect($log->status)->toBe(GenerationStatus::Completed);
    expect(keyList($form->refresh()->schema))
        ->toBe(['full_name', 'work_email', 'date_of_birth']);
});

it('lets a label change through without touching the key', function () {
    [$owner, $form, $stored] = keyFixture();

    $relabelled = $stored;
    $relabelled['sections'][0]['fields'][0]['label'] = 'Legal Name';

    keyPushReplies([$relabelled]);

    $log = keyRunEdit($form, $owner, 'Rename the Full Name field to Legal Name.');
    $form->refresh();

    expect($log->status)->toBe(GenerationStatus::Completed);
    expect($form->schema['sections'][0]['fields'][0]['label'])->toBe('Legal Name');
    expect($form->schema['sections'][0]['fields'][0]['key'])->toBe('full_name');
});

it('keeps every retained field id stable across an edit', function () {
    [$owner, $form, $stored] = keyFixture();

    $extended = $stored;
    $extended['sections'][0]['fields'][] = [
        'type' => 'textarea',
        'key' => 'notes',
        'label' => 'Notes',
        'required' => false,
    ];

    keyPushReplies([$extended]);
    keyRunEdit($form, $owner, 'Add a notes field.');

    $after = collect($form->refresh()->schema['sections'][0]['fields'])->keyBy('key');

    foreach (['full_name', 'work_email', 'age'] as $key) {
        expect($after[$key]['id'])
            ->toBe(collect($stored['sections'][0]['fields'])->firstWhere('key', $key)['id']);
    }
});

/*
|--------------------------------------------------------------------------
| Why the gate exists
|--------------------------------------------------------------------------
*/

it('keeps a historical submission exporting under its original column', function () {
    [$owner, $form, $stored] = keyFixture();

    $form->forceFill(['status' => 'published'])->save();

    FormSubmission::factory()
        ->for($form)
        ->version($form->schema_version)
        ->withPayload([
            'full_name' => 'Ada Lovelace',
            'work_email' => 'ada@example.test',
            'age' => 36,
        ])
        ->create();

    // Two edits, the second of which adds a field, so the schema genuinely moves
    // while the answered keys must not.
    $relabelled = $stored;
    $relabelled['sections'][0]['fields'][0]['label'] = 'Legal Name';

    $extended = $relabelled;
    $extended['sections'][0]['fields'][] = [
        'type' => 'phone',
        'key' => 'phone_number',
        'label' => 'Phone Number',
        'required' => false,
    ];

    keyPushReplies([$relabelled, $extended]);

    expect(keyRunEdit($form, $owner, 'Rename Full Name to Legal Name.')->status)
        ->toBe(GenerationStatus::Completed);
    expect(keyRunEdit($form, $owner, 'Add a phone number.')->status)
        ->toBe(GenerationStatus::Completed);

    $response = test()->actingAs($owner)->get(route('forms.submissions.export', $form->refresh()));
    $response->assertOk();

    $content = $response->streamedContent();

    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $rows = [];
    while (($parsed = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rows[] = $parsed;
    }
    fclose($handle);

    [$header, $row] = [$rows[0], $rows[1]];

    // The label moved and a field was added, but the answer still resolves.
    $column = array_search('Legal Name', $header, true);

    expect($column)->not->toBeFalse();
    expect($row[$column])->toBe('Ada Lovelace');
    expect($header)->not->toContain('Full Name (removed)');
    expect($row[array_search('Work Email', $header, true)])->toBe('ada@example.test');
});

it('creates one version per successful edit and none per rejected one', function () {
    [$owner, $form, $stored] = keyFixture();

    $versionsBefore = FormVersion::query()->where('form_id', $form->id)->count();

    $renamed = $stored;
    $renamed['sections'][0]['fields'][1]['key'] = 'email_address';

    $relabelled = $stored;
    $relabelled['sections'][0]['fields'][1]['label'] = 'Email Address';

    config(['formforge.ai.max_repair_attempts' => 0]);
    keyPushReplies([$renamed, $relabelled]);

    expect(keyRunEdit($form, $owner)->status)->toBe(GenerationStatus::Failed);
    expect(FormVersion::query()->where('form_id', $form->id)->count())->toBe($versionsBefore);

    expect(keyRunEdit($form, $owner)->status)->toBe(GenerationStatus::Completed);

    expect(FormVersion::query()->where('form_id', $form->id)->count())->toBe($versionsBefore + 1);
});
