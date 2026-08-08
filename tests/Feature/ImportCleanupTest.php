<?php

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
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
use Livewire\Livewire;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'formforge.ai.api_key' => 'test-key',
        'formforge.ai.base_url' => 'https://api.openai.test/v1',
        'formforge.import.stale_after_minutes' => 60,
    ]);

    Http::preventStrayRequests();
    Storage::fake('local');
});

function cleanupSchema(): array
{
    return [
        'schema_version' => 1,
        'title' => 'Imported Form',
        'description' => null,
        'settings' => [
            'multi_step' => false,
            'submit_button_text' => 'Submit',
            'success_message' => 'Thanks.',
        ],
        'sections' => [[
            'title' => 'Details',
            'description' => null,
            'fields' => [
                ['type' => 'text', 'key' => 'full_name', 'label' => 'Full Name', 'required' => true],
            ],
        ]],
    ];
}

function cleanupDocxBytes(): string
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
 * A queued import with its document already on the private disk.
 *
 * @return array{owner: User, form: Form, job: ImportJob, path: string}
 */
function cleanupQueued(): array
{
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $form = Form::factory()->for($owner)->create();

    $path = 'imports/'.$form->id.'/'.Str::ulid().'.docx';
    Storage::disk('local')->put($path, cleanupDocxBytes());

    $job = ImportJob::factory()->for($owner)->for($form)->create(['disk_path' => $path]);

    return ['owner' => $owner, 'form' => $form, 'job' => $job, 'path' => $path];
}

function cleanupRunWorker(ImportJob $job, ?string $reply = null): void
{
    Http::fakeSequence()->push([
        'model' => 'gpt-4o-mini',
        'choices' => [['message' => ['content' => $reply ?? json_encode(cleanupSchema())]]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
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
| The worker owns the file's lifetime
|--------------------------------------------------------------------------
*/

it('deletes the document as soon as the preview is written', function () {
    $setup = cleanupQueued();

    expect(Storage::disk('local')->exists($setup['path']))->toBeTrue();

    cleanupRunWorker($setup['job']);

    // The extraction is on the row now; the source document has no further use
    // and holding it would only widen the window for a leak.
    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Preview)
        ->and(Storage::disk('local')->exists($setup['path']))->toBeFalse()
        ->and($setup['job']->refresh()->disk_path)->toBe($setup['path']);
});

it('deletes the document when the import fails', function () {
    $setup = cleanupQueued();

    cleanupRunWorker($setup['job'], 'this is not a schema');

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Failed)
        ->and(Storage::disk('local')->exists($setup['path']))->toBeFalse();
});

it('deletes the document when the file itself is what failed', function () {
    $setup = cleanupQueued();
    Storage::disk('local')->put($setup['path'], 'not an archive');

    (new ProcessImportJob($setup['job']->id))->handle(
        app(AiService::class),
        app(SchemaService::class),
        app(SchemaCandidateGate::class),
        app(ImportArchiveGuard::class),
    );

    expect(Storage::disk('local')->exists($setup['path']))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Cancel
|--------------------------------------------------------------------------
*/

it('clears the panel without touching a running import or its file', function () {
    Bus::fake();

    $owner = User::factory()->create(['email_verified_at' => now()]);
    $form = Form::factory()->for($owner)->create();

    $component = Livewire::actingAs($owner)->test(FormBuilder::class, ['form' => $form])
        ->call('toggleImport')
        ->set('importFile', UploadedFile::fake()->createWithContent('intake.docx', cleanupDocxBytes()))
        ->call('startImport')
        ->assertSet('importStatus', 'queued');

    $job = ImportJob::query()->sole();

    $component->call('cancelImport')
        ->assertSet('importJobId', null)
        ->assertSet('importStatus', null)
        ->assertSet('importPreview', null)
        ->assertSet('importError', null);

    // A worker may still be about to pick this up, so neither the row nor the
    // file it needs is touched.
    expect($job->refresh()->status)->toBe(ImportStatus::Queued)
        ->and(Storage::disk('local')->exists($job->disk_path))->toBeTrue();
});

it('lets the worker finish an import the user walked away from', function () {
    $setup = cleanupQueued();

    // The panel was cleared, but the queued row was left exactly as it was.
    cleanupRunWorker($setup['job']);

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Preview)
        ->and(Storage::disk('local')->exists($setup['path']))->toBeFalse();
});

it('never rewrites the status of an import that already committed', function () {
    $setup = cleanupQueued();
    $setup['job']->forceFill(['status' => ImportStatus::Committed])->save();

    Livewire::actingAs($setup['owner'])->test(FormBuilder::class, ['form' => $setup['form']])
        ->call('cancelImport');

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Committed);
});

/*
|--------------------------------------------------------------------------
| The worker's eligibility re-check
|--------------------------------------------------------------------------
*/

it('stops before spending anything if the row moved on before it started', function () {
    $setup = cleanupQueued();

    // The pruner reached it first.
    $setup['job']->forceFill(['status' => ImportStatus::Failed])->save();

    (new ProcessImportJob($setup['job']->id))->handle(
        app(AiService::class),
        app(SchemaService::class),
        app(SchemaCandidateGate::class),
        app(ImportArchiveGuard::class),
    );

    expect(Http::recorded())->toHaveCount(0);
});

it('discards its work if the row moved on while the provider was answering', function () {
    $setup = cleanupQueued();

    Http::fakeSequence()->pushResponse(
        Http::response([
            'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['content' => json_encode(cleanupSchema())]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ])
    );

    // Stand in for a pruner that fired mid-call.
    Http::globalRequestMiddleware(function ($request) use ($setup) {
        ImportJob::query()->whereKey($setup['job']->id)
            ->update(['status' => ImportStatus::Failed->value]);

        return $request;
    });

    (new ProcessImportJob($setup['job']->id))->handle(
        app(AiService::class),
        app(SchemaService::class),
        app(SchemaCandidateGate::class),
        app(ImportArchiveGuard::class),
    );

    $job = $setup['job']->refresh();

    // The answer arrived after the row stopped being eligible, so it is dropped
    // rather than resurrecting a job something else already finished with.
    expect($job->status)->toBe(ImportStatus::Failed)
        ->and($job->preview)->toBeNull()
        ->and($job->final_schema)->toBeNull();
});

it('ignores an import row that no longer exists', function () {
    (new ProcessImportJob((string) Str::ulid()))->handle(
        app(AiService::class),
        app(SchemaService::class),
        app(SchemaCandidateGate::class),
        app(ImportArchiveGuard::class),
    );

    expect(Http::recorded())->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| The pruner
|--------------------------------------------------------------------------
*/

it('fails a queued import no worker ever collected, and deletes its file', function () {
    $setup = cleanupQueued();
    $setup['job']->forceFill(['created_at' => now()->subHours(3)])->save();

    $this->artisan('formforge:prune-import-files')->assertSuccessful();

    $job = $setup['job']->refresh();

    expect($job->status)->toBe(ImportStatus::Failed)
        ->and($job->errors['message'])->toBe('The import timed out before it could be processed.')
        ->and(Storage::disk('local')->exists($setup['path']))->toBeFalse();
});

it('reclaims an import whose worker died mid processing', function () {
    $setup = cleanupQueued();
    $setup['job']->forceFill([
        'status' => ImportStatus::Processing,
        'created_at' => now()->subHours(3),
    ])->save();

    $this->artisan('formforge:prune-import-files')->assertSuccessful();

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Failed)
        ->and(Storage::disk('local')->exists($setup['path']))->toBeFalse();
});

it('leaves a recent import alone', function () {
    $setup = cleanupQueued();

    $this->artisan('formforge:prune-import-files')->assertSuccessful();

    // Still inside the window, so a worker may yet be about to read it.
    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Queued)
        ->and(Storage::disk('local')->exists($setup['path']))->toBeTrue();
});

it('respects the configured window', function () {
    config(['formforge.import.stale_after_minutes' => 5]);

    $setup = cleanupQueued();
    $setup['job']->forceFill(['created_at' => now()->subMinutes(10)])->save();

    $this->artisan('formforge:prune-import-files')->assertSuccessful();

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Failed);
});

it('sweeps up a file a terminal import left behind', function () {
    $setup = cleanupQueued();

    // A worker that died between deleting the file and recording that it had
    // would leave exactly this: a terminal row still pointing at a real file.
    $setup['job']->forceFill(['status' => ImportStatus::Committed])->save();

    $this->artisan('formforge:prune-import-files')->assertSuccessful();

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Committed)
        ->and(Storage::disk('local')->exists($setup['path']))->toBeFalse();
});

it('survives a row whose file is already gone', function () {
    $setup = cleanupQueued();
    Storage::disk('local')->delete($setup['path']);
    $setup['job']->forceFill(['created_at' => now()->subHours(3)])->save();

    $this->artisan('formforge:prune-import-files')
        ->expectsOutputToContain('deleted 0 file(s)')
        ->assertSuccessful();

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Failed);
});

it('reports what it reclaimed', function () {
    $setup = cleanupQueued();
    $setup['job']->forceFill(['created_at' => now()->subHours(3)])->save();

    $this->artisan('formforge:prune-import-files')
        ->expectsOutputToContain('Failed 1 stale import job(s) and deleted 1 file(s).')
        ->assertSuccessful();
});
