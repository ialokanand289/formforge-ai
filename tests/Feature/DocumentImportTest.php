<?php

use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\ImportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'formforge.ai.api_key' => 'test-key-do-not-leak',
        'formforge.ai.base_url' => 'https://api.openai.test/v1',
        'formforge.queue.import' => 'imports',
    ]);

    Http::preventStrayRequests();

    // Keep uploads out of the developer's real storage directory.
    Storage::fake('local');

    $this->uploadDir = sys_get_temp_dir().'/formforge-upload-'.Str::random(8);
    mkdir($this->uploadDir);
});

afterEach(function () {
    foreach (glob($this->uploadDir.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->uploadDir);
});

function importOwner(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

function importForm(User $owner): Form
{
    return Form::factory()->for($owner)->create(['title' => 'Client Intake']);
}

function importBuilder(User $actor, Form $form)
{
    return Livewire::actingAs($actor)->test(FormBuilder::class, ['form' => $form]);
}

/**
 * Real document bytes, delivered the way a browser would deliver them.
 *
 * Livewire's test harness needs Illuminate\Http\Testing\File rather than a
 * plain UploadedFile, so the bytes are generated on disk and handed over.
 */
function importUpload(string $name, string $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function importDocxBytes(): string
{
    $word = new PhpWord;
    $section = $word->addSection();
    $section->addText('Full Name');
    $section->addText('Work Email');

    $path = test()->uploadDir.'/'.Str::random(8).'.docx';
    WordIOFactory::createWriter($word, 'Word2007')->save($path);

    return (string) file_get_contents($path);
}

function importXlsxBytes(): string
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([
        ['Full Name', 'Work Email'],
        ['Ada', 'ada@example.com'],
    ], null, 'A1');

    $path = test()->uploadDir.'/'.Str::random(8).'.xlsx';
    (new XlsxWriter($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return (string) file_get_contents($path);
}

function importDocxUpload(string $name = 'employee-registration.docx'): UploadedFile
{
    return importUpload($name, importDocxBytes());
}

function importXlsxUpload(string $name = 'headcount.xlsx'): UploadedFile
{
    return importUpload($name, importXlsxBytes());
}

/**
 * Import files actually sitting on the private disk, ignoring the tracked
 * .gitkeep the directory ships with.
 *
 * @return list<string>
 */
function importStoredFiles(): array
{
    return array_values(array_filter(
        Storage::disk('local')->allFiles('imports'),
        fn (string $path): bool => ! str_ends_with($path, '.gitkeep'),
    ));
}

/**
 * Drive a real upload so importJobId is set the way the application sets it.
 *
 * Assigning a #[Locked] property on the instance would not survive Livewire's
 * next hydration, so every test that needs a tracked job earns one.
 *
 * @return array{0: Testable, 1: ImportJob}
 */
function importStarted(User $owner, Form $form): array
{
    Bus::fake();

    $component = importBuilder($owner, $form)
        ->call('toggleImport')
        ->set('importFile', importDocxUpload())
        ->call('startImport')
        ->assertHasNoErrors();

    return [$component, ImportJob::query()->sole()];
}

/*
|--------------------------------------------------------------------------
| Accepted uploads
|--------------------------------------------------------------------------
*/

it('accepts a docx and queues it for processing', function () {
    Bus::fake();

    $owner = importOwner();
    $form = importForm($owner);

    importBuilder($owner, $form)
        ->set('importFile', importDocxUpload())
        ->call('startImport')
        ->assertHasNoErrors()
        ->assertSet('importStatus', 'queued');

    $job = ImportJob::query()->sole();

    expect($job->user_id)->toBe($owner->id)
        ->and($job->form_id)->toBe($form->id)
        ->and($job->source)->toBe(ImportSource::Docx)
        ->and($job->original_filename)->toBe('employee-registration.docx')
        ->and($job->status)->toBe(ImportStatus::Queued);

    Bus::assertDispatched(ProcessImportJob::class, fn (ProcessImportJob $dispatched): bool => $dispatched->importJobId === $job->id
        && $dispatched->queue === 'imports');
});

it('accepts an xlsx', function () {
    Bus::fake();

    $owner = importOwner();

    importBuilder($owner, importForm($owner))
        ->set('importFile', importXlsxUpload())
        ->call('startImport')
        ->assertHasNoErrors();

    expect(ImportJob::query()->sole()->source)->toBe(ImportSource::Xlsx);
});

it('stores the upload on the private disk under the form, never under a client filename', function () {
    Bus::fake();

    $owner = importOwner();
    $form = importForm($owner);

    importBuilder($owner, $form)
        ->set('importFile', importDocxUpload('quarterly-headcount.docx'))
        ->call('startImport')
        ->assertHasNoErrors();

    $job = ImportJob::query()->sole();

    expect($job->disk_path)->toStartWith("imports/{$form->id}/")
        ->and($job->disk_path)->not->toContain('quarterly-headcount')
        ->and($job->disk_path)->not->toContain('..')
        ->and(Storage::disk('local')->exists($job->disk_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($job->disk_path))->toBeFalse();
});

it('resolves an upload disk that is not web served', function () {
    // Asserted against the real configuration rather than the faked disk above,
    // because faking replaces the root that makes this true.
    $disk = config('formforge.uploads.disk');

    expect($disk)->not->toBe('public')
        ->and(config("filesystems.disks.{$disk}.visibility"))->not->toBe('public')
        ->and(config("filesystems.disks.{$disk}.root"))->toContain('private')
        ->and(config("filesystems.disks.{$disk}.serve"))->not->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Rejected uploads
|--------------------------------------------------------------------------
*/

it('rejects a file type it does not import', function (string $name, string $bytes) {
    Bus::fake();

    $owner = importOwner();

    importBuilder($owner, importForm($owner))
        ->set('importFile', importUpload($name, $bytes))
        ->call('startImport')
        ->assertHasErrors('importFile');

    expect(ImportJob::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
})->with([
    'pdf' => ['resume.pdf', "%PDF-1.7\ntrailer\n%%EOF"],
    'csv' => ['rows.csv', "name,email\nada,ada@example.com"],
    'txt' => ['notes.txt', 'Full name, email, phone'],
    'zip' => ['bundle.zip', 'PK'],
]);

it('rejects a renamed file whose contents are not an ooxml package', function () {
    Bus::fake();

    $owner = importOwner();

    $component = importBuilder($owner, importForm($owner))
        ->set('importFile', importUpload('resume.docx', "%PDF-1.7\ntrailer\n%%EOF"))
        ->call('startImport');

    // Rejected by mimes or by the archive guard, whichever notices first; what
    // matters is that no row and no stored file survive it.
    expect(ImportJob::query()->count())->toBe(0)
        ->and(importStoredFiles())->toBe([])
        ->and($component->get('importError') !== null || $component->errors()->has('importFile'))->toBeTrue();

    Bus::assertNothingDispatched();
});

it('rejects an xlsx renamed as a docx', function () {
    Bus::fake();

    $owner = importOwner();

    importBuilder($owner, importForm($owner))
        ->set('importFile', importUpload('disguised.docx', importXlsxBytes()))
        ->call('startImport')
        ->assertSet('importError', "That file's contents do not match its .docx extension.");

    expect(ImportJob::query()->count())->toBe(0)
        ->and(importStoredFiles())->toBe([]);

    Bus::assertNothingDispatched();
});

it('rejects an archive bomb before it is ever stored', function () {
    Bus::fake();
    config(['formforge.import.archive.max_uncompressed_bytes' => 1024]);

    $path = test()->uploadDir.'/bomb.docx';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('word/document.xml', '<document/>');
    $zip->addFromString('word/theme/theme1.xml', str_repeat('A', 500000));
    $zip->close();

    $owner = importOwner();

    importBuilder($owner, importForm($owner))
        ->set('importFile', importUpload('bomb.docx', (string) file_get_contents($path)))
        ->call('startImport')
        ->assertSet('importError', 'That document is too large to import safely.');

    expect(ImportJob::query()->count())->toBe(0)
        ->and(importStoredFiles())->toBe([]);

    Bus::assertNothingDispatched();
});

it('rejects a file larger than the configured maximum', function () {
    Bus::fake();
    config(['formforge.import.max_file_size_kb' => 1]);

    $owner = importOwner();

    importBuilder($owner, importForm($owner))
        ->set('importFile', importXlsxUpload())
        ->call('startImport')
        ->assertHasErrors('importFile');

    expect(ImportJob::query()->count())->toBe(0);
});

it('refuses to store an import when the resolved disk is world readable', function () {
    Bus::fake();
    config(['formforge.uploads.disk' => 'public']);

    $owner = importOwner();

    importBuilder($owner, importForm($owner))
        ->set('importFile', importDocxUpload())
        ->call('startImport')
        ->assertSet('importError', 'Import files cannot be stored on the public disk [public].');

    expect(ImportJob::query()->count())->toBe(0);
});

it('refuses while the builder has unsaved changes', function () {
    Bus::fake();

    $owner = importOwner();

    importBuilder($owner, importForm($owner))
        ->call('addField', 'text')
        ->set('importFile', importDocxUpload())
        ->call('startImport')
        ->assertSet('importError', 'Save or discard your unsaved changes before importing a document.');

    expect(ImportJob::query()->count())->toBe(0);
});

it('refuses a second import while one is in flight', function () {
    Bus::fake();

    $owner = importOwner();
    $form = importForm($owner);

    ImportJob::factory()->for($owner)->for($form)->create(['status' => ImportStatus::Processing]);

    importBuilder($owner, $form)
        ->set('importFile', importDocxUpload())
        ->call('startImport')
        ->assertSet('importError', 'An import is already running for this form.');

    expect(ImportJob::query()->count())->toBe(1);
});

it('refuses when no api key is configured', function () {
    Bus::fake();
    config(['formforge.ai.api_key' => '']);

    $owner = importOwner();

    importBuilder($owner, importForm($owner))
        ->set('importFile', importDocxUpload())
        ->call('startImport')
        ->assertSet('importError', 'AI is not configured on this server, so documents cannot be imported.');

    expect(ImportJob::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('forbids a non owner from opening the builder at all', function () {
    $owner = importOwner();
    $form = importForm($owner);

    Livewire::actingAs(importOwner())
        ->test(FormBuilder::class, ['form' => $form])
        ->assertForbidden();
});

it('keeps the tracked import id server controlled', function () {
    $owner = importOwner();

    $component = importBuilder($owner, importForm($owner));

    expect(fn () => $component->set('importJobId', 'forged-id'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('records the signed in user rather than anything the browser sends', function () {
    Bus::fake();

    $owner = importOwner();
    $other = importOwner();

    importBuilder($owner, importForm($owner))
        ->set('importFile', importDocxUpload())
        ->call('startImport');

    expect(ImportJob::query()->sole()->user_id)
        ->toBe($owner->id)
        ->not->toBe($other->id);
});

it('will not apply an import whose row has moved to another user', function () {
    $owner = importOwner();
    $form = importForm($owner);

    [$component, $job] = importStarted($owner, $form);

    // The component still holds the id, but the row is no longer the viewer's,
    // which is what the user_id scope on every read exists to catch.
    $job->forceFill([
        'status' => ImportStatus::Preview,
        'final_schema' => importSchema(),
        'user_id' => importOwner()->id,
    ])->save();

    $component->call('acceptImport')
        ->assertSet('importError', 'That import is no longer available to apply.');

    expect($job->refresh()->status)->toBe(ImportStatus::Preview)
        ->and($form->refresh()->schema_version)->toBe(1);
});

it('will not apply an import that targets another form', function () {
    $owner = importOwner();
    $form = importForm($owner);
    $otherForm = importForm($owner);

    [$component, $job] = importStarted($owner, $form);

    $job->forceFill([
        'status' => ImportStatus::Preview,
        'final_schema' => importSchema(),
        'form_id' => $otherForm->id,
    ])->save();

    $component->call('acceptImport')
        ->assertSet('importError', 'That import is no longer available to apply.');

    expect($job->refresh()->status)->toBe(ImportStatus::Preview)
        ->and($form->refresh()->schema_version)->toBe(1)
        ->and($otherForm->refresh()->schema_version)->toBe(1);
});

/**
 * A schema that would be accepted if the authorization checks did not fire.
 *
 * @return array<string, mixed>
 */
function importSchema(): array
{
    return [
        'schema_version' => 1,
        'title' => 'Imported Form',
        'description' => '',
        'settings' => ['multi_step' => false, 'submit_button_text' => 'Submit', 'success_message' => 'Thanks.'],
        'sections' => [[
            'title' => 'Main',
            'description' => null,
            'fields' => [['type' => 'text', 'key' => 'full_name', 'label' => 'Full Name', 'required' => true]],
        ]],
    ];
}

/*
|--------------------------------------------------------------------------
| Panel behaviour
|--------------------------------------------------------------------------
*/

it('enables the import toolbar button and opens the panel', function () {
    $owner = importOwner();

    importBuilder($owner, importForm($owner))
        ->assertSet('showImport', false)
        ->assertSeeHtml('wire:click="toggleImport"')
        ->assertDontSee('Import arrives in a later phase')
        ->call('toggleImport')
        ->assertSet('showImport', true)
        ->assertSee('Import a document');
});

it('labels the toolbar button for each state', function (?string $status, string $label) {
    $owner = importOwner();

    $builder = new FormBuilder;
    $builder->importStatus = $status;

    expect($builder->importLabel())->toBe($label);
})->with([
    'idle' => [null, 'Import'],
    'queued' => ['queued', 'Uploading...'],
    'processing' => ['processing', 'Importing...'],
    'preview' => ['preview', 'Review Import'],
    'committed' => ['committed', 'Imported successfully'],
    'failed' => ['failed', 'Import failed'],
]);

it('polls only while the import is in flight', function () {
    $owner = importOwner();
    $form = importForm($owner);

    [$component, $job] = importStarted($owner, $form);

    $component->assertSeeHtml('wire:poll.2s="pollImport"');

    $job->forceFill(['status' => ImportStatus::Processing])->save();

    $component->call('pollImport')
        ->assertSet('importStatus', 'processing')
        ->assertSeeHtml('wire:poll.2s="pollImport"');

    $job->forceFill([
        'status' => ImportStatus::Preview,
        'preview' => ['title' => 'Intake', 'field_count' => 1, 'sections' => [], 'warnings' => []],
    ])->save();

    $component->call('pollImport')
        ->assertSet('importStatus', 'preview')
        ->assertDontSeeHtml('wire:poll.2s="pollImport"');
});

it('surfaces the recorded failure message and stops polling', function () {
    $owner = importOwner();
    $form = importForm($owner);

    [$component, $job] = importStarted($owner, $form);

    $job->forceFill([
        'status' => ImportStatus::Failed,
        'errors' => ['message' => 'That Word document could not be read.'],
    ])->save();

    $component->call('pollImport')
        ->assertSet('importStatus', 'failed')
        ->assertSet('importError', 'That Word document could not be read.')
        ->assertSet('importJobId', null)
        ->assertDontSeeHtml('wire:poll.2s="pollImport"');
});

it('escapes labels the ai authored in the preview', function () {
    $owner = importOwner();
    $form = importForm($owner);

    [$component, $job] = importStarted($owner, $form);

    $job->forceFill([
        'status' => ImportStatus::Preview,
        'preview' => [
            'title' => 'Intake',
            'filename' => 'x.docx',
            'source' => 'docx',
            'field_count' => 1,
            'warnings' => [],
            'sections' => [[
                'title' => 'Main',
                'fields' => [[
                    'label' => '<script>alert(1)</script>',
                    'key' => 'evil',
                    'type' => 'text',
                    'required' => false,
                    'options' => [],
                    'validation' => '',
                ]],
            ]],
        ],
    ])->save();

    // assertSee escapes the needle, so it passes only when the label was
    // rendered escaped; assertDontSeeHtml proves the raw tag never appeared.
    $component->call('pollImport')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSee('<script>alert(1)</script>');
});

it('never renders the api key', function () {
    $owner = importOwner();

    importBuilder($owner, importForm($owner))
        ->call('toggleImport')
        ->assertDontSee('test-key-do-not-leak');
});
