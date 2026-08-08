<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\AiGenerationLog;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\AiService;
use App\Services\ImportArchiveGuard;
use App\Services\SchemaCandidateGate;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'formforge.ai.api_key' => 'test-key',
        'formforge.ai.base_url' => 'https://api.openai.test/v1',
        'formforge.ai.max_repair_attempts' => 3,
    ]);

    Http::preventStrayRequests();
    Storage::fake('local');
});

/**
 * A schema the pipeline should accept unchanged.
 */
function inferSchema(array $overrides = []): array
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

function inferReply(string $content, int $promptTokens = 120, int $completionTokens = 300): array
{
    return [
        'model' => 'gpt-4o-mini-2024-07-18',
        'choices' => [['message' => ['content' => $content]]],
        'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens],
    ];
}

/**
 * fakeSequence() registers itself, so wrapping it in another Http::fake() call
 * would leave two stubs draining the same sequence.
 */
function inferFakeSequence(array $contents): void
{
    $sequence = Http::fakeSequence();

    foreach ($contents as $content) {
        $sequence->push(inferReply($content));
    }
}

/**
 * A queued import with a real document already sitting on the private disk.
 *
 * @return array{owner: User, form: Form, job: ImportJob, path: string}
 */
function inferSetup(ImportSource $source = ImportSource::Docx): array
{
    $owner = User::factory()->create();
    $form = Form::factory()->for($owner)->create(['title' => 'Client Intake']);

    $path = 'imports/'.$form->id.'/'.Str::ulid().'.'.$source->value;
    Storage::disk('local')->put($path, $source === ImportSource::Docx ? inferDocxBytes() : inferXlsxBytes());

    $job = ImportJob::factory()->for($owner)->for($form)->source($source)->create(['disk_path' => $path]);

    return ['owner' => $owner, 'form' => $form, 'job' => $job, 'path' => $path];
}

function inferDocxBytes(): string
{
    $word = new PhpWord;
    $section = $word->addSection();
    $section->addText('Full Name');
    $section->addText('Work Email');

    $path = tempnam(sys_get_temp_dir(), 'ff').'.docx';
    WordIOFactory::createWriter($word, 'Word2007')->save($path);
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

function inferXlsxBytes(): string
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([
        ['Full Name', 'Work Email', 'Department'],
        ['Ada', 'ada@example.com', 'Engineering'],
    ], null, 'A1');

    $path = tempnam(sys_get_temp_dir(), 'ff').'.xlsx';
    (new XlsxWriter($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

it('moves a queued import through processing to preview', function () {
    inferFakeSequence([json_encode(inferSchema())]);

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    $job = $setup['job']->refresh();

    expect($job->status)->toBe(ImportStatus::Preview)
        ->and($job->final_schema['title'])->toBe('Employee Registration')
        ->and($job->errors)->toBeNull();
});

it('reads an xlsx through the same pipeline', function () {
    inferFakeSequence([json_encode(inferSchema())]);

    $setup = inferSetup(ImportSource::Xlsx);

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Preview);
});

it('sends the extracted content rather than the binary document', function () {
    inferFakeSequence([json_encode(inferSchema())]);

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    $body = Http::recorded()[0][0]->data();
    $user = $body['messages'][1]['content'];

    expect($user)->toContain('Full Name')
        ->and($user)->toContain('"source":"docx"')
        // The ZIP signature would be present if the file itself had been sent.
        ->and($user)->not->toContain('PK');
});

it('builds a preview describing what it found', function () {
    inferFakeSequence([json_encode(inferSchema())]);

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    $preview = $setup['job']->refresh()->preview;

    expect($preview['title'])->toBe('Employee Registration')
        ->and($preview['field_count'])->toBe(3)
        ->and($preview['source'])->toBe('docx')
        ->and($preview['filename'])->toBe($setup['job']->original_filename)
        ->and($preview['sections'][0]['fields'][0])->toMatchArray([
            'label' => 'Full Name',
            'key' => 'full_name',
            'type' => 'text',
            'required' => true,
        ])
        ->and($preview['sections'][0]['fields'][2]['options'])->toBe(['HR', 'Engineering']);
});

it('warns that answers under fields the import drops will be left behind', function () {
    inferFakeSequence([json_encode(inferSchema())]);

    $setup = inferSetup();

    $schema = $setup['form']->schema;
    $schema['sections'][0]['fields'] = [[
        'id' => (string) Str::ulid(),
        'type' => 'text',
        'key' => 'legacy_reference',
        'label' => 'Legacy Reference',
        'required' => false,
        'options' => [],
        'validation' => [],
        'conditions' => [],
        'default' => null,
    ]];
    $setup['form']->forceFill(['schema' => $schema])->save();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect($setup['job']->refresh()->preview['warnings'][0])->toContain('legacy_reference');
});

/*
|--------------------------------------------------------------------------
| Nothing is written to the form
|--------------------------------------------------------------------------
*/

it('leaves the form completely untouched while only a preview exists', function () {
    inferFakeSequence([json_encode(inferSchema())]);

    $setup = inferSetup();
    $form = $setup['form'];

    $before = [
        'schema' => $form->schema,
        'schema_version' => $form->schema_version,
        'title' => $form->title,
        'updated_at' => (string) $form->updated_at,
    ];
    $versionsBefore = FormVersion::query()->count();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    $form->refresh();

    expect($form->schema)->toBe($before['schema'])
        ->and($form->schema_version)->toBe($before['schema_version'])
        ->and($form->title)->toBe($before['title'])
        ->and((string) $form->updated_at)->toBe($before['updated_at'])
        ->and(FormVersion::query()->count())->toBe($versionsBefore);
});

/*
|--------------------------------------------------------------------------
| The gates
|--------------------------------------------------------------------------
*/

it('handles a code fenced reply', function () {
    inferFakeSequence(["Here you go:\n```json\n".json_encode(inferSchema())."\n```"]);

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Preview);
});

it('rejects a reply that is not a schema', function (string $reply, string $fragment) {
    inferFakeSequence(array_fill(0, 4, $reply));

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    $job = $setup['job']->refresh();

    expect($job->status)->toBe(ImportStatus::Failed)
        ->and($job->errors['message'])->toContain($fragment)
        ->and($job->preview)->toBeNull()
        ->and($job->final_schema)->toBeNull();
})->with([
    'unparseable' => ['I could not read that document, sorry.', 'not a JSON object'],
    'no title' => ['{"sections": []}', 'non-empty title'],
    'no sections' => ['{"title": "Intake"}', 'sections array'],
]);

it('rejects an invented field type rather than coercing it to text', function () {
    $bad = inferSchema();
    $bad['sections'][0]['fields'][0]['type'] = 'signature';

    inferFakeSequence(array_fill(0, 4, json_encode($bad)));

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect($setup['job']->refresh()->errors['message'])->toContain('Unsupported field type [signature]');
});

it('rejects duplicate keys rather than letting one be suffixed', function () {
    $bad = inferSchema();
    $bad['sections'][0]['fields'][1]['key'] = 'full_name';

    inferFakeSequence(array_fill(0, 4, json_encode($bad)));

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect($setup['job']->refresh()->errors['message'])->toContain('Duplicate field key [full_name]');
});

it('rejects a choice field with no options', function () {
    $bad = inferSchema();
    $bad['sections'][0]['fields'][2]['options'] = [];

    inferFakeSequence(array_fill(0, 4, json_encode($bad)));

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Failed);
});

/*
|--------------------------------------------------------------------------
| The repair budget
|--------------------------------------------------------------------------
*/

it('spends exactly one initial call plus the configured repair budget', function (int $repairs, int $calls) {
    config(['formforge.ai.max_repair_attempts' => $repairs]);

    inferFakeSequence(array_fill(0, $calls + 2, 'not a schema at all'));

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect(Http::recorded())->toHaveCount($calls)
        ->and(AiGenerationLog::query()->sole()->attempts)->toBe($calls)
        ->and($setup['job']->refresh()->status)->toBe(ImportStatus::Failed);
})->with([
    'no repairs allowed' => [0, 1],
    'one repair allowed' => [1, 2],
    'three repairs allowed' => [3, 4],
]);

it('completes on a repair and counts both calls', function () {
    inferFakeSequence(['not a schema', json_encode(inferSchema())]);

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect(Http::recorded())->toHaveCount(2)
        ->and(AiGenerationLog::query()->sole()->attempts)->toBe(2)
        ->and($setup['job']->refresh()->status)->toBe(ImportStatus::Preview);
});

it('hands the rejection reasons to the repair call', function () {
    $bad = inferSchema();
    $bad['sections'][0]['fields'][0]['type'] = 'signature';

    inferFakeSequence([json_encode($bad), json_encode(inferSchema())]);

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    $repair = Http::recorded()[1][0]->data()['messages'][1]['content'];

    expect($repair)->toContain('Unsupported field type [signature]');
});

/*
|--------------------------------------------------------------------------
| Failures
|--------------------------------------------------------------------------
*/

it('records a safe message and no raw body when the provider errors', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API key sk-secret-123']], 401)]);

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    $job = $setup['job']->refresh();

    expect($job->status)->toBe(ImportStatus::Failed)
        ->and($job->errors['message'])->toBe('The AI provider rejected the credentials for this server.')
        ->and(json_encode($job->errors))->not->toContain('sk-secret-123');

    expect(AiGenerationLog::query()->sole()->status)->toBe(GenerationStatus::Failed);
});

it('fails safely on a corrupt document, before any provider call', function () {
    $setup = inferSetup();

    // A truncated or otherwise damaged docx stops being a valid archive, which
    // is how a corrupt document presents itself in practice.
    Storage::disk('local')->put($setup['path'], substr(inferDocxBytes(), 0, 400));

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    $job = $setup['job']->refresh();

    expect($job->status)->toBe(ImportStatus::Failed)
        ->and($job->errors['message'])->toBe('That file is not a readable Word or Excel document.')
        ->and($job->preview)->toBeNull();

    // Nothing was parsed, so no AI cost was incurred or recorded.
    expect(AiGenerationLog::query()->count())->toBe(0);
    expect(Http::recorded())->toHaveCount(0);
});

it('runs the archive guard again on the stored file, so a swap after upload is caught', function () {
    $setup = inferSetup();

    // Whatever passed at upload time, this is what is on disk at parse time.
    Storage::disk('local')->put($setup['path'], 'plain text pretending to be a document');

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect($setup['job']->refresh()->errors['message'])
        ->toBe('That file is not a readable Word or Excel document.');

    expect(Http::recorded())->toHaveCount(0);
});

it('reads a document it can make no sense of as an empty extraction rather than crashing', function () {
    // The guard only inspects the central directory, so a package with the
    // right entry names but nonsense inside them still reaches the parser.
    inferFakeSequence(array_fill(0, 4, 'I could not identify any fields.'));

    $setup = inferSetup();
    Storage::disk('local')->put($setup['path'], inferEmptyDocxBytes());

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    $job = $setup['job']->refresh();

    expect($job->status)->toBe(ImportStatus::Failed)
        ->and($job->errors['message'])->toContain('not a JSON object');

    expect(AiGenerationLog::query()->sole()->prompt)->toContain('"paragraphs":[]');
});

it('fails when the uploaded file has already gone', function () {
    $setup = inferSetup();
    Storage::disk('local')->delete($setup['path']);

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect($setup['job']->refresh()->errors['message'])
        ->toBe('The uploaded file is no longer available. Please upload it again.');
});

it('ignores an import that is not queued', function () {
    $setup = inferSetup();
    $setup['job']->forceFill(['status' => ImportStatus::Committed])->save();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Committed);
    expect(Http::recorded())->toHaveCount(0);
});

it('refuses to run when the import and the form have different owners', function () {
    $setup = inferSetup();
    $setup['job']->forceFill(['user_id' => User::factory()->create()->id])->save();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    expect($setup['job']->refresh()->errors['message'])->toBe('This form is no longer available.');
    expect(Http::recorded())->toHaveCount(0);
});

it('marks the import failed when the worker dies', function () {
    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->failed(new RuntimeException('worker killed'));

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($setup['job']->refresh()->errors['message'])->toBe('The import did not finish. Please try again.');
});

it('does not overwrite a finished import from the failed hook', function () {
    inferFakeSequence([json_encode(inferSchema())]);

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());
    (new ProcessImportJob($setup['job']->id))->failed(null);

    expect($setup['job']->refresh()->status)->toBe(ImportStatus::Preview);
});

/*
|--------------------------------------------------------------------------
| The AI log
|--------------------------------------------------------------------------
*/

it('records the inference against an import_infer log', function () {
    inferFakeSequence([json_encode(inferSchema())]);

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    $log = AiGenerationLog::query()->sole();

    expect($log->type)->toBe(GenerationType::ImportInfer)
        ->and($log->user_id)->toBe($setup['owner']->id)
        ->and($log->form_id)->toBe($setup['form']->id)
        ->and($log->model)->toBe('gpt-4o-mini-2024-07-18')
        ->and($log->prompt_tokens)->toBe(120)
        ->and($log->completion_tokens)->toBe(300)
        ->and($log->latency_ms)->toBeGreaterThanOrEqual(0)
        ->and($log->attempts)->toBe(1)
        // The extraction is the effective prompt, and the only thing that keeps
        // the log readable once the source file is deleted.
        ->and($log->prompt)->toContain('"source":"docx"');
});

it('accumulates tokens and latency across every call', function () {
    inferFakeSequence(['not a schema', json_encode(inferSchema())]);

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    $log = AiGenerationLog::query()->sole();

    expect($log->prompt_tokens)->toBe(240)
        ->and($log->completion_tokens)->toBe(600);
});

it('completes the log at preview while the import itself is only previewed', function () {
    inferFakeSequence([json_encode(inferSchema())]);

    $setup = inferSetup();

    (new ProcessImportJob($setup['job']->id))->handle(...inferDependencies());

    // The log answers "did the AI produce a valid schema"; the import job
    // answers "was that schema applied". They are not the same question.
    expect(AiGenerationLog::query()->sole()->status)->toBe(GenerationStatus::Completed)
        ->and(AiGenerationLog::query()->sole()->schema_result)->not->toBeNull()
        ->and($setup['job']->refresh()->status)->toBe(ImportStatus::Preview);
});

/**
 * The job's container-resolved dependencies, spelled out once.
 *
 * @return list<object>
 */
function inferDependencies(): array
{
    return [
        app(AiService::class),
        app(SchemaService::class),
        app(SchemaCandidateGate::class),
        app(ImportArchiveGuard::class),
    ];
}

/**
 * An archive that satisfies the guard but describes no content.
 */
function inferEmptyDocxBytes(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ff').'.docx';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('word/document.xml', 'this is not xml at all <<<');
    $zip->close();

    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}
