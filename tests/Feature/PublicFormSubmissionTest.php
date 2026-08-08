<?php

use App\Enums\FormStatus;
use App\Enums\SubmissionStatus;
use App\Livewire\Forms\PublicForm;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Services\SchemaService;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Helper names are deliberately distinct from PublicFormRendererTest, because
 * Pest loads every test file into the same process.
 */
function submissionField(string $type, array $attributes = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(),
        'type' => $type,
        'key' => $type.'_field',
        'label' => Str::headline($type).' Field',
    ], $attributes);
}

function submissionForm(array $fields = [], array $overrides = [], array $settings = []): Form
{
    $schema = app(SchemaService::class)->normalize([
        'schema_version' => 1,
        'title' => 'Client Intake',
        'description' => '',
        'settings' => array_merge(['submit_button_text' => 'Send it'], $settings),
        'sections' => [
            [
                'id' => (string) Str::ulid(),
                'title' => 'About you',
                'description' => null,
                'fields' => $fields,
            ],
        ],
    ]);

    return Form::factory()
        ->published()
        ->for(User::factory()->create())
        ->create(array_merge([
            'title' => $schema['title'],
            'schema' => $schema,
        ], $overrides));
}

function submitting(Form $form): Testable
{
    return Livewire::test(PublicForm::class, ['token' => $form->public_token]);
}

function ipHashOf(string $ip): string
{
    return hash_hmac('sha256', $ip, (string) config('app.key'));
}

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('accepts a valid text submission', function () {
    $form = submissionForm([
        submissionField('text', ['key' => 'full_name', 'required' => true]),
    ]);

    submitting($form)
        ->set('values.full_name', 'Ada Lovelace')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    expect(FormSubmission::query()->count())->toBe(1);
    expect(FormSubmission::query()->first()->payload)->toBe(['full_name' => 'Ada Lovelace']);
});

it('rejects an empty required field', function () {
    $form = submissionForm([
        submissionField('text', ['key' => 'full_name', 'required' => true]),
    ]);

    submitting($form)
        ->call('submit')
        ->assertHasErrors(['values.full_name' => 'required'])
        ->assertSet('submitted', false);

    expect(FormSubmission::query()->count())->toBe(0);
});

it('rejects a malformed email address', function () {
    $form = submissionForm([
        submissionField('email', ['key' => 'work_email']),
    ]);

    submitting($form)
        ->set('values.work_email', 'not-an-address')
        ->call('submit')
        ->assertHasErrors(['values.work_email' => 'email']);

    expect(FormSubmission::query()->count())->toBe(0);
});

it('enforces number bounds from the schema', function () {
    $form = submissionForm([
        submissionField('number', ['key' => 'team_size', 'validation' => ['min' => 1, 'max' => 10]]),
    ]);

    submitting($form)
        ->set('values.team_size', '50')
        ->call('submit')
        ->assertHasErrors(['values.team_size' => 'max']);

    submitting($form)
        ->set('values.team_size', 'twelve')
        ->call('submit')
        ->assertHasErrors(['values.team_size' => 'numeric']);

    expect(FormSubmission::query()->count())->toBe(0);
});

it('rejects a dropdown value that is not an option', function () {
    $form = submissionForm([
        submissionField('dropdown', [
            'key' => 'plan',
            'options' => [
                ['value' => 'basic', 'label' => 'Basic plan'],
                ['value' => 'pro', 'label' => 'Pro plan'],
            ],
        ]),
    ]);

    // The browser select only offers two values; the server does not care.
    submitting($form)
        ->set('values.plan', 'enterprise')
        ->call('submit')
        ->assertHasErrors(['values.plan' => 'in']);

    expect(FormSubmission::query()->count())->toBe(0);
});

it('validates checkbox members against the option list', function () {
    $form = submissionForm([
        submissionField('checkbox', [
            'key' => 'topics',
            'required' => true,
            'options' => [
                ['value' => 'news', 'label' => 'Newsletter'],
                ['value' => 'offers', 'label' => 'Offers'],
            ],
        ]),
    ]);

    submitting($form)
        ->set('values.topics', ['news', 'injected'])
        ->call('submit')
        ->assertHasErrors(['values.topics.1' => 'in']);

    // A scalar cannot satisfy an array field, so a required checkbox fails.
    submitting($form)
        ->set('values.topics', 'news')
        ->call('submit')
        ->assertHasErrors(['values.topics' => 'required']);

    expect(FormSubmission::query()->count())->toBe(0);
});

it('enforces the configured rating range', function () {
    $form = submissionForm([
        submissionField('rating', ['key' => 'score', 'validation' => ['min' => 1, 'max' => 5]]),
    ]);

    submitting($form)
        ->set('values.score', '9')
        ->call('submit')
        ->assertHasErrors(['values.score' => 'max']);

    submitting($form)
        ->set('values.score', '0')
        ->call('submit')
        ->assertHasErrors(['values.score' => 'min']);

    expect(FormSubmission::query()->count())->toBe(0);
});

it('accepts a file that satisfies the schema rules', function () {
    Storage::fake('local');

    $form = submissionForm([
        submissionField('file', [
            'key' => 'resume',
            'required' => true,
            'validation' => ['file_types' => ['pdf'], 'max_file_size_kb' => 1024],
        ]),
    ]);

    submitting($form)
        ->set('files.resume', UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    expect(SubmissionFile::query()->count())->toBe(1);
});

it('rejects a file larger than the field allows', function () {
    Storage::fake('local');

    $form = submissionForm([
        submissionField('file', [
            'key' => 'resume',
            'validation' => ['file_types' => ['pdf'], 'max_file_size_kb' => 100],
        ]),
    ]);

    submitting($form)
        ->set('files.resume', UploadedFile::fake()->create('resume.pdf', 500, 'application/pdf'))
        ->call('submit')
        ->assertHasErrors(['files.resume' => 'max']);

    expect(FormSubmission::query()->count())->toBe(0);
    expect(SubmissionFile::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('submissions'))->toBe([]);
});

it('rejects an oversized upload as soon as it lands', function () {
    Storage::fake('local');

    $form = submissionForm([
        submissionField('file', [
            'key' => 'resume',
            'required' => true,
            'validation' => ['file_types' => ['pdf'], 'max_file_size_kb' => 100],
        ]),
    ]);

    // No submit call: the visitor should not have to press the button to find
    // out the file was too large.
    submitting($form)
        ->set('files.resume', UploadedFile::fake()->create('resume.pdf', 500, 'application/pdf'))
        ->assertHasErrors(['files.resume' => 'max']);
});

it('accepts a good upload without complaining before submit', function () {
    Storage::fake('local');

    $form = submissionForm([
        submissionField('file', [
            'key' => 'resume',
            'required' => true,
            'validation' => ['file_types' => ['pdf'], 'max_file_size_kb' => 1024],
        ]),
    ]);

    submitting($form)
        ->set('files.resume', UploadedFile::fake()->create('resume.pdf', 20, 'application/pdf'))
        ->assertHasNoErrors()
        ->assertSet('submitted', false);
});

it('rejects a file whose type is not allowed', function () {
    Storage::fake('local');

    $form = submissionForm([
        submissionField('file', [
            'key' => 'resume',
            'validation' => ['file_types' => ['pdf'], 'max_file_size_kb' => 1024],
        ]),
    ]);

    submitting($form)
        ->set('files.resume', UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))
        ->call('submit')
        ->assertHasErrors(['files.resume' => 'mimes']);

    expect(FormSubmission::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('submissions'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Persistence
|--------------------------------------------------------------------------
*/

it('records the form version in force at submit time', function () {
    $form = submissionForm([submissionField('text', ['key' => 'full_name'])]);
    $form->forceFill(['schema_version' => 7])->save();

    submitting($form)->set('values.full_name', 'Ada')->call('submit');

    expect(FormSubmission::query()->first()->form_version)->toBe(7);
});

it('keys the payload on schema field keys and skips headings', function () {
    $form = submissionForm([
        submissionField('heading', ['key' => 'about_you', 'label' => 'About you']),
        submissionField('text', ['key' => 'full_name']),
        submissionField('checkbox', [
            'key' => 'topics',
            'options' => [['value' => 'news', 'label' => 'Newsletter']],
        ]),
    ]);

    submitting($form)
        ->set('values.full_name', 'Ada Lovelace')
        ->set('values.topics', ['news'])
        ->call('submit')
        ->assertHasNoErrors();

    $payload = FormSubmission::query()->first()->payload;

    expect(array_keys($payload))->toBe(['full_name', 'topics']);
    expect($payload['topics'])->toBe(['news']);
    expect($payload)->not->toHaveKey('about_you');
    expect($payload)->not->toHaveKey('Full Name');
});

it('drops keys the visitor invented', function () {
    $form = submissionForm([submissionField('text', ['key' => 'full_name'])]);

    submitting($form)
        ->set('values.full_name', 'Ada')
        ->set('values.is_admin', '1')
        ->call('submit')
        ->assertHasNoErrors();

    expect(FormSubmission::query()->first()->payload)->toBe(['full_name' => 'Ada']);
});

it('discards an upload injected into a text field', function () {
    Storage::fake('local');

    $form = submissionForm([
        submissionField('text', ['key' => 'full_name', 'required' => true]),
    ]);

    // Livewire's uploader can be pointed at any bound property, so the server
    // has to refuse anything that is not a plain value.
    submitting($form)
        ->set('values.full_name', UploadedFile::fake()->create('payload.pdf', 10, 'application/pdf'))
        ->call('submit')
        ->assertHasErrors(['values.full_name' => 'required']);

    expect(FormSubmission::query()->count())->toBe(0);
});

it('builds search text from answers and choice labels', function () {
    $form = submissionForm([
        submissionField('text', ['key' => 'full_name']),
        submissionField('dropdown', [
            'key' => 'plan',
            'options' => [['value' => 'pro', 'label' => 'Pro plan']],
        ]),
        submissionField('rating', ['key' => 'score']),
    ]);

    submitting($form)
        ->set('values.full_name', 'Ada   Lovelace')
        ->set('values.plan', 'pro')
        ->set('values.score', '4')
        ->call('submit')
        ->assertHasNoErrors();

    $searchText = FormSubmission::query()->first()->search_text;

    expect($searchText)->toBe('Ada Lovelace pro Pro plan');
});

it('leaves search text null when nothing searchable was answered', function () {
    $form = submissionForm([submissionField('rating', ['key' => 'score'])]);

    submitting($form)->set('values.score', '3')->call('submit')->assertHasNoErrors();

    expect(FormSubmission::query()->first()->search_text)->toBeNull();
});

it('stores a hashed ip and never the raw address', function () {
    $form = submissionForm([submissionField('text', ['key' => 'full_name'])]);

    submitting($form)->set('values.full_name', 'Ada')->call('submit');

    $submission = FormSubmission::query()->first();

    expect($submission->ip_hash)->toBe(ipHashOf('127.0.0.1'));
    expect($submission->ip_hash)->toHaveLength(64);
    expect($submission->getAttributes())->not->toContain('127.0.0.1');
});

it('stores the user agent and status', function () {
    $form = submissionForm([submissionField('text', ['key' => 'full_name'])]);

    submitting($form)->set('values.full_name', 'Ada')->call('submit');

    $submission = FormSubmission::query()->first();

    expect($submission->user_agent)->not->toBeNull();
    expect($submission->status)->toBe(SubmissionStatus::Completed);
});

it('truncates a user agent to the column width and hashes any ip', function () {
    $form = submissionForm([submissionField('text', ['key' => 'full_name'])]);
    $schema = app(SchemaService::class)->load($form);

    $submission = app(SubmissionService::class)->create(
        $form,
        $schema,
        ['full_name' => 'Ada'],
        [],
        '203.0.113.9',
        str_repeat('a', 400),
    );

    expect(strlen($submission->user_agent))->toBe(255);
    expect($submission->ip_hash)->toBe(ipHashOf('203.0.113.9'));
});

it('stores uploads privately and records them', function () {
    Storage::fake('local');
    Storage::fake('public');

    $form = submissionForm([
        submissionField('file', [
            'key' => 'resume',
            'validation' => ['file_types' => ['pdf'], 'max_file_size_kb' => 1024],
        ]),
    ]);

    submitting($form)
        ->set('files.resume', UploadedFile::fake()->create('My Résumé.pdf', 42, 'application/pdf'))
        ->call('submit')
        ->assertHasNoErrors();

    $submission = FormSubmission::query()->first();
    $file = SubmissionFile::query()->first();

    expect($file->form_submission_id)->toBe($submission->id);
    expect($file->field_key)->toBe('resume');
    expect($file->original_name)->toBe('My Résumé.pdf');
    expect($file->disk)->toBe('local');
    expect($file->mime_type)->toBe('application/pdf');
    expect($file->size)->toBeGreaterThan(0);
    expect($file->path)->toStartWith("submissions/{$form->id}/{$submission->id}/");
    expect($file->path)->toEndWith('.pdf');
    // The visitor's filename never becomes a path component.
    expect($file->path)->not->toContain('Résumé');

    Storage::disk('local')->assertExists($file->path);
    expect(Storage::disk('public')->allFiles())->toBe([]);

    // The payload keeps the readable name, never the storage location.
    expect($submission->payload)->toBe(['resume' => 'My Résumé.pdf']);
});

it('keeps submissions off any world readable disk', function () {
    $disk = config('formforge.uploads.disk');

    expect($disk)->not->toBe('public');
    expect(config("filesystems.disks.{$disk}.visibility"))->not->toBe('public');
    expect(config("filesystems.disks.{$disk}.url"))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Boundaries
|--------------------------------------------------------------------------
*/

it('cannot be submitted through an unknown token', function () {
    // The submit action is unreachable because the page itself never renders.
    test()->get(route('forms.public', ['token' => (string) Str::uuid()]))->assertNotFound();

    expect(FormSubmission::query()->count())->toBe(0);
});

it('cannot be submitted while the form is a draft', function () {
    $form = submissionForm([submissionField('text')], ['status' => FormStatus::Draft]);

    test()->get(route('forms.public', ['token' => $form->public_token]))->assertNotFound();

    expect(FormSubmission::query()->count())->toBe(0);
});

it('refuses a submission for a form unpublished mid session', function () {
    $form = submissionForm([submissionField('text', ['key' => 'full_name'])]);

    $component = submitting($form)->set('values.full_name', 'Ada');

    $form->forceFill(['status' => FormStatus::Archived])->save();

    // The form is re-read on every request, so the archived status wins.
    $component->call('submit');

    expect(FormSubmission::query()->count())->toBe(0);
    test()->get(route('forms.public', ['token' => $form->public_token]))->assertNotFound();
});

it('keeps a historical form version when the schema changes later', function () {
    $form = submissionForm([submissionField('text', ['key' => 'full_name'])]);

    submitting($form)->set('values.full_name', 'Ada')->call('submit');

    $submission = FormSubmission::query()->first();
    expect($submission->form_version)->toBe(1);

    $schemaService = app(SchemaService::class);
    $schema = $schemaService->load($form->refresh());
    $schemaService->save($form, $schemaService->addSection($schema, 'Later section'));

    expect($form->refresh()->schema_version)->toBe(2);
    expect($submission->refresh()->form_version)->toBe(1);
});

it('leaves nothing behind when the transaction fails', function () {
    Storage::fake('local');

    $form = submissionForm([
        submissionField('file', [
            'key' => 'resume',
            'validation' => ['file_types' => ['pdf'], 'max_file_size_kb' => 1024],
        ]),
    ]);

    SubmissionFile::creating(function () {
        throw new RuntimeException('forced database failure');
    });

    $component = submitting($form)
        ->set('files.resume', UploadedFile::fake()->create('resume.pdf', 20, 'application/pdf'))
        ->call('submit');

    expect(FormSubmission::query()->count())->toBe(0);
    expect(SubmissionFile::query()->count())->toBe(0);
    // The file was written before the transaction, so it has to be cleaned up.
    expect(Storage::disk('local')->allFiles('submissions'))->toBe([]);
    expect($component->get('submitError'))->toContain('Something went wrong');
});

it('preserves submitted values after a validation error', function () {
    $form = submissionForm([
        submissionField('text', ['key' => 'full_name', 'required' => true]),
        submissionField('email', ['key' => 'work_email', 'required' => true]),
    ]);

    submitting($form)
        ->set('values.full_name', 'Ada Lovelace')
        ->call('submit')
        ->assertHasErrors(['values.work_email' => 'required'])
        ->assertSet('values.full_name', 'Ada Lovelace')
        ->assertSee('Ada Lovelace');
});

it('shows the success message defined in the schema', function () {
    $form = submissionForm(
        [submissionField('text', ['key' => 'full_name'])],
        [],
        ['success_message' => 'We will be in touch shortly.'],
    );

    submitting($form)
        ->set('values.full_name', 'Ada')
        ->call('submit')
        ->assertSet('successMessage', 'We will be in touch shortly.')
        ->assertSee('We will be in touch shortly.');
});

it('clears the answers after a successful submission', function () {
    $form = submissionForm([submissionField('text', ['key' => 'full_name'])]);

    submitting($form)
        ->set('values.full_name', 'Ada')
        ->call('submit')
        ->assertSet('values.full_name', '');
});

it('never exposes internal identifiers in the success response', function () {
    $form = submissionForm([submissionField('text', ['key' => 'full_name'])]);

    $html = submitting($form)
        ->set('values.full_name', 'Ada')
        ->call('submit')
        ->html();

    $submission = FormSubmission::query()->first();

    expect($html)->not->toContain($submission->id);
    expect($html)->not->toContain($form->id);
    expect($html)->not->toContain($form->slug);
    expect($html)->not->toContain($form->user->email);
});

/*
|--------------------------------------------------------------------------
| Rate limiting
|--------------------------------------------------------------------------
*/

it('refuses more submissions than the configured limit', function () {
    $form = submissionForm([submissionField('text', ['key' => 'full_name'])]);
    $limit = (int) config('formforge.public.submit_rate_limit_per_minute');

    $component = submitting($form);

    for ($attempt = 1; $attempt <= $limit; $attempt++) {
        $component->set('values.full_name', 'Ada '.$attempt)->call('submit');
    }

    expect(FormSubmission::query()->count())->toBe($limit);

    $component->set('values.full_name', 'One too many')->call('submit');

    expect(FormSubmission::query()->count())->toBe($limit);
    expect($component->get('submitError'))->toContain('Too many submissions');
});

it('counts failed attempts against the limit', function () {
    $form = submissionForm([
        submissionField('text', ['key' => 'full_name', 'required' => true]),
    ]);
    $limit = (int) config('formforge.public.submit_rate_limit_per_minute');

    $component = submitting($form);

    for ($attempt = 1; $attempt <= $limit; $attempt++) {
        $component->call('submit')->assertHasErrors(['values.full_name' => 'required']);
    }

    $component->set('values.full_name', 'Ada')->call('submit');

    expect(FormSubmission::query()->count())->toBe(0);
    expect($component->get('submitError'))->toContain('Too many submissions');
});
