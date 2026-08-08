<?php

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\AiService;
use App\Services\ImportArchiveGuard;
use App\Services\SchemaCandidateGate;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'formforge.ai.api_key' => 'test-key',
        'formforge.ai.base_url' => 'https://api.openai.test/v1',
    ]);

    Http::preventStrayRequests();
    Storage::fake('local');
});

function commitSchema(array $overrides = []): array
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
            ],
        ]],
    ], $overrides);
}

function commitDocxBytes(): string
{
    $word = new PhpWord;
    $word->addSection()->addText('Full Name');

    $path = tempnam(sys_get_temp_dir(), 'ff').'.docx';
    WordIOFactory::createWriter($word, 'Word2007')->save($path);
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

/**
 * Drive a real import from upload to preview and leave the builder holding it.
 *
 * The whole flow is exercised rather than seeded: importJobId is a locked
 * property, so it only survives hydration if the component set it itself, and
 * the "nothing changed yet" assertions only mean something if the worker
 * genuinely ran.
 *
 * @return array{owner: User, form: Form, job: ImportJob, component: Testable}
 */
function commitPreviewed(?array $schema = null): array
{
    Bus::fake();

    $owner = User::factory()->create(['email_verified_at' => now()]);
    $form = Form::factory()->for($owner)->create(['title' => 'Client Intake']);

    $component = Livewire::actingAs($owner)->test(FormBuilder::class, ['form' => $form])
        ->call('toggleImport')
        ->set('importFile', UploadedFile::fake()->createWithContent('intake.docx', commitDocxBytes()))
        ->call('startImport')
        ->assertHasNoErrors();

    $job = ImportJob::query()->sole();

    commitRunWorker($job, $schema ?? commitSchema());

    $component->call('pollImport')->assertSet('importStatus', 'preview');

    return ['owner' => $owner, 'form' => $form->refresh(), 'job' => $job->refresh(), 'component' => $component];
}

/**
 * Run the queued worker in-process against a scripted AI reply.
 */
function commitRunWorker(ImportJob $job, array $schema): void
{
    Http::fakeSequence()->push([
        'model' => 'gpt-4o-mini-2024-07-18',
        'choices' => [['message' => ['content' => json_encode($schema)]]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200],
    ]);

    (new ProcessImportJob($job->id))->handle(
        app(AiService::class),
        app(SchemaService::class),
        app(SchemaCandidateGate::class),
        app(ImportArchiveGuard::class),
    );
}

/*
|--------------------------------------------------------------------------
| Nothing changes before acceptance
|--------------------------------------------------------------------------
*/

it('leaves the form byte identical while the import is only previewed', function () {
    $owner = User::factory()->create();
    $form = Form::factory()->for($owner)->create(['title' => 'Client Intake']);

    $before = [
        'schema' => $form->schema,
        'schema_version' => $form->schema_version,
        'updated_at' => (string) $form->updated_at,
    ];
    $versions = FormVersion::query()->count();

    $sequence = Http::fakeSequence();
    $sequence->push([
        'model' => 'gpt-4o-mini',
        'choices' => [['message' => ['content' => json_encode(commitSchema())]]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
    ]);

    $path = 'imports/'.$form->id.'/'.Str::ulid().'.docx';
    Storage::disk('local')->put($path, commitDocxBytes());
    $job = ImportJob::factory()->for($owner)->for($form)->create(['disk_path' => $path]);

    (new ProcessImportJob($job->id))->handle(
        app(AiService::class),
        app(SchemaService::class),
        app(SchemaCandidateGate::class),
        app(ImportArchiveGuard::class),
    );

    $form->refresh();

    expect($job->refresh()->status)->toBe(ImportStatus::Preview)
        ->and($form->schema)->toBe($before['schema'])
        ->and($form->schema_version)->toBe($before['schema_version'])
        ->and((string) $form->updated_at)->toBe($before['updated_at'])
        ->and(FormVersion::query()->count())->toBe($versions);
});

/*
|--------------------------------------------------------------------------
| Acceptance
|--------------------------------------------------------------------------
*/

it('saves the imported schema through SchemaService and versions it once', function () {
    ['form' => $form, 'job' => $job, 'component' => $component] = commitPreviewed();

    $versionsBefore = FormVersion::query()->where('form_id', $form->id)->count();

    $component->call('acceptImport')->assertSet('importError', null);

    $form->refresh();

    expect($form->schema_version)->toBe(2)
        ->and($form->title)->toBe('Employee Registration')
        ->and(FormVersion::query()->where('form_id', $form->id)->count())->toBe($versionsBefore + 1)
        ->and($job->refresh()->status)->toBe(ImportStatus::Committed);
});

it('records the source filename on the new version', function () {
    ['form' => $form, 'job' => $job, 'component' => $component] = commitPreviewed();

    $component->call('acceptImport');

    $version = FormVersion::query()->where('form_id', $form->id)->latest('version')->first();

    expect($version->note)->toBe('Imported from '.$job->original_filename);
});

it('repopulates the builder with the imported schema', function () {
    ['component' => $component] = commitPreviewed();

    $component->call('acceptImport')
        ->assertSet('title', 'Employee Registration')
        // Already persisted through SchemaService, so nothing is pending.
        ->assertSet('dirty', false)
        ->assertSet('showImport', false)
        ->assertSet('importStatus', 'committed')
        ->assertSet('importPreview', null)
        ->assertSet('importJobId', null);

    $keys = collect($component->get('schema')['sections'][0]['fields'])->pluck('key')->all();

    expect($keys)->toBe(['full_name', 'work_email']);
});

it('needs no source file, because the preview already deleted it', function () {
    ['job' => $job, 'component' => $component, 'form' => $form] = commitPreviewed();

    expect(Storage::disk('local')->exists($job->disk_path))->toBeFalse();

    $component->call('acceptImport')->assertSet('importError', null);

    expect($form->refresh()->schema_version)->toBe(2);
});

it('refuses to apply the same import twice', function () {
    ['form' => $form, 'component' => $component] = commitPreviewed();

    $component->call('acceptImport');
    $version = $form->refresh()->schema_version;

    // importJobId is cleared on success, so there is nothing left to re-apply.
    $component->call('acceptImport')
        ->assertSet('importError', 'That import is no longer available to apply.');

    expect($form->refresh()->schema_version)->toBe($version);
});

it('refuses to apply an import that is not at preview', function () {
    ['form' => $form, 'job' => $job, 'component' => $component] = commitPreviewed();

    $job->forceFill(['status' => ImportStatus::Failed])->save();

    $component->call('acceptImport')
        ->assertSet('importError', 'That import is no longer available to apply.');

    expect($form->refresh()->schema_version)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Acceptance re-validates
|--------------------------------------------------------------------------
*/

it('re-runs the gates on the stored schema rather than trusting the row', function () {
    ['form' => $form, 'job' => $job, 'component' => $component] = commitPreviewed();

    // Whatever the worker validated, this is what is in the row now.
    $tampered = $job->final_schema;
    $tampered['sections'][0]['fields'][0]['type'] = 'signature';
    $job->forceFill(['final_schema' => $tampered])->save();

    $component->call('acceptImport');

    expect($component->get('importError'))->toContain('Unsupported field type [signature]')
        ->and($form->refresh()->schema_version)->toBe(1)
        ->and($job->refresh()->status)->toBe(ImportStatus::Preview);
});

it('refuses a row whose schema went missing', function () {
    ['form' => $form, 'job' => $job, 'component' => $component] = commitPreviewed();

    $job->forceFill(['final_schema' => null])->save();

    $component->call('acceptImport')
        ->assertSet('importError', 'That import did not produce a schema to apply.');

    expect($form->refresh()->schema_version)->toBe(1);
});

it('leaves the import at preview and the form untouched when the save is rejected', function () {
    ['form' => $form, 'job' => $job, 'component' => $component] = commitPreviewed();

    $before = $form->schema;

    // The gates pass, so only the persistence step can still fail.
    $this->mock(SchemaService::class, function ($mock) use ($job) {
        $mock->shouldReceive('validationErrors')->andReturn([]);
        $mock->shouldReceive('load')->andReturn($job->final_schema);
        $mock->shouldReceive('save')->andThrow(
            ValidationException::withMessages(['sections' => ['The schema is invalid.']])
        );
    });

    $component->call('acceptImport');

    expect($component->get('importError'))->toContain('The schema is invalid.')
        ->and($form->refresh()->schema)->toBe($before)
        ->and($form->refresh()->schema_version)->toBe(1)
        ->and($job->refresh()->status)->toBe(ImportStatus::Preview)
        ->and(FormVersion::query()->where('form_id', $form->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Guards
|--------------------------------------------------------------------------
*/

it('refuses to apply over unsaved builder changes', function () {
    ['form' => $form, 'job' => $job, 'component' => $component] = commitPreviewed();

    // Edits made while the import was running would otherwise be replaced
    // without a word.
    $component->call('addField', 'text')
        ->assertSet('dirty', true)
        ->call('acceptImport')
        ->assertSet('importError', 'Save or discard your unsaved changes before applying this import.');

    expect($form->refresh()->schema_version)->toBe(1)
        ->and($job->refresh()->status)->toBe(ImportStatus::Preview);
});
