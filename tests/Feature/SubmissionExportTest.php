<?php

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Helper names are deliberately distinct from the other suites, because Pest
 * loads every test file into the same process.
 */
function exportOwner(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

function exportField(string $type, array $attributes = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(),
        'type' => $type,
        'key' => $type.'_field',
        'label' => Str::headline($type).' Field',
    ], $attributes);
}

function exportSchema(array $fields, string $title = 'Client Intake'): array
{
    return app(SchemaService::class)->normalize([
        'schema_version' => 1,
        'title' => $title,
        'description' => '',
        'settings' => [],
        'sections' => [
            [
                'id' => (string) Str::ulid(),
                'title' => 'About you',
                'description' => null,
                'fields' => $fields,
            ],
        ],
    ]);
}

function exportForm(User $owner, array $fields = [], array $overrides = []): Form
{
    return Form::factory()->for($owner)->create(array_merge([
        'title' => 'Client Intake',
        'schema' => exportSchema($fields),
    ], $overrides));
}

function exportSubmission(Form $form, array $payload, int $version = 1): FormSubmission
{
    return FormSubmission::factory()
        ->for($form)
        ->version($version)
        ->withPayload($payload)
        ->create();
}

function exportCsv(User $actor, Form $form): TestResponse
{
    return test()->actingAs($actor)->get(route('forms.submissions.export', $form));
}

/**
 * Parse the streamed CSV back into rows, so assertions read the same data a
 * spreadsheet would rather than a raw string.
 */
function exportRows(User $actor, Form $form): array
{
    return parseCsv(exportCsv($actor, $form)->streamedContent());
}

function parseCsv(string $content): array
{
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $rows = [];

    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('lets the owner download their submissions', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [exportField('text', ['key' => 'full_name'])]);

    exportSubmission($form, ['full_name' => 'Ada Lovelace']);

    $response = exportCsv($owner, $form)->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->headers->get('content-disposition'))
        ->toContain($form->slug.'-submissions-'.now()->format('Y-m-d').'.csv');
});

it('forbids a signed in non owner', function () {
    $owner = exportOwner();
    $intruder = exportOwner();
    $form = exportForm($owner, [exportField('text', ['key' => 'full_name'])]);

    exportCsv($intruder, $form)->assertForbidden();
});

it('sends a guest to login', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [exportField('text', ['key' => 'full_name'])]);

    test()->get(route('forms.submissions.export', $form))->assertRedirect(route('login'));
});

it('returns 404 for a soft deleted form', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [exportField('text', ['key' => 'full_name'])]);

    $form->delete();

    exportCsv($owner, $form)->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Columns
|--------------------------------------------------------------------------
*/

it('builds headers from schema labels in schema order', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('text', ['key' => 'full_name', 'label' => 'Full Name']),
        exportField('email', ['key' => 'work_email', 'label' => 'Work Email']),
        exportField('number', ['key' => 'team_size', 'label' => 'Team Size']),
    ]);

    $rows = exportRows($owner, $form);

    expect($rows[0])->toBe([
        'Submitted At', 'Status', 'Form Version',
        'Full Name', 'Work Email', 'Team Size',
    ]);
});

it('never turns a heading into a column', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('heading', ['key' => 'about_you', 'label' => 'About you']),
        exportField('text', ['key' => 'full_name', 'label' => 'Full Name']),
    ]);

    $rows = exportRows($owner, $form);

    expect($rows[0])->toBe(['Submitted At', 'Status', 'Form Version', 'Full Name']);
});

it('exports a header row for a form with no submissions', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [exportField('text', ['key' => 'full_name', 'label' => 'Full Name'])]);

    $content = exportCsv($owner, $form)->assertOk()->streamedContent();
    $rows = parseCsv($content);

    expect($content)->toStartWith("\xEF\xBB\xBF");
    expect($rows)->toHaveCount(1);
    expect($rows[0])->toBe(['Submitted At', 'Status', 'Form Version', 'Full Name']);
});

it('exports every submission in chronological order', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [exportField('text', ['key' => 'full_name', 'label' => 'Full Name'])]);

    foreach (['Ada', 'Grace', 'Katherine'] as $name) {
        exportSubmission($form, ['full_name' => $name]);
    }

    $rows = exportRows($owner, $form);

    expect($rows)->toHaveCount(4);
    expect(array_column(array_slice($rows, 1), 3))->toBe(['Ada', 'Grace', 'Katherine']);
});

it('carries the metadata columns for each row', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [exportField('text', ['key' => 'full_name', 'label' => 'Full Name'])]);

    $submission = exportSubmission($form, ['full_name' => 'Ada'], version: 4);

    $row = exportRows($owner, $form)[1];

    expect($row[0])->toBe($submission->created_at->utc()->format('Y-m-d H:i:s'));
    expect($row[1])->toBe('completed');
    expect($row[2])->toBe('4');
});

/*
|--------------------------------------------------------------------------
| Value formatting
|--------------------------------------------------------------------------
*/

it('exports an unanswered field as an empty cell', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('text', ['key' => 'full_name', 'label' => 'Full Name']),
        exportField('text', ['key' => 'company', 'label' => 'Company']),
    ]);

    exportSubmission($form, ['full_name' => 'Ada', 'company' => null]);

    expect(array_slice(exportRows($owner, $form)[1], 3))->toBe(['Ada', '']);
});

it('joins checkbox answers as labels in schema option order', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('checkbox', [
            'key' => 'topics',
            'label' => 'Topics',
            'options' => [
                ['value' => 'news', 'label' => 'Newsletter'],
                ['value' => 'offers', 'label' => 'Offers'],
            ],
        ]),
    ]);

    // Stored in the opposite order to prove the cell follows the schema.
    exportSubmission($form, ['topics' => ['offers', 'news']]);

    expect(exportRows($owner, $form)[1][3])->toBe('Newsletter, Offers');
});

it('exports a choice answer as its label', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('dropdown', [
            'key' => 'plan',
            'label' => 'Plan',
            'options' => [['value' => 'pro', 'label' => 'Pro plan']],
        ]),
    ]);

    exportSubmission($form, ['plan' => 'pro']);

    expect(exportRows($owner, $form)[1][3])->toBe('Pro plan');
});

it('falls back to the stored value when an option is gone', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('dropdown', [
            'key' => 'plan',
            'label' => 'Plan',
            'options' => [['value' => 'pro', 'label' => 'Pro plan']],
        ]),
    ]);

    exportSubmission($form, ['plan' => 'retired_tier']);

    expect(exportRows($owner, $form)[1][3])->toBe('retired_tier');
});

it('shows only the filename for a file answer', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('file', ['key' => 'resume', 'label' => 'Resume']),
    ]);

    exportSubmission($form, ['resume' => 'My Resume.pdf']);

    $content = exportCsv($owner, $form)->streamedContent();

    expect(parseCsv($content)[1][3])->toBe('My Resume.pdf');
    expect($content)->not->toContain('submissions/');
    expect($content)->not->toContain('storage');
    expect($content)->not->toContain($form->id);
});

/*
|--------------------------------------------------------------------------
| CSV safety
|--------------------------------------------------------------------------
*/

it('survives commas quotes newlines and unicode', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('text', ['key' => 'commas', 'label' => 'Commas']),
        exportField('text', ['key' => 'quotes', 'label' => 'Quotes']),
        exportField('textarea', ['key' => 'lines', 'label' => 'Lines']),
        exportField('text', ['key' => 'unicode', 'label' => 'Unicode']),
    ]);

    exportSubmission($form, [
        'commas' => 'Doe, Jane, and Co.',
        'quotes' => 'She said "hello" twice',
        'lines' => "first line\nsecond line",
        'unicode' => 'Café ☕ Ünïcodé',
    ]);

    $row = exportRows($owner, $form)[1];

    expect($row[3])->toBe('Doe, Jane, and Co.');
    expect($row[4])->toBe('She said "hello" twice');
    expect($row[5])->toBe("first line\nsecond line");
    expect($row[6])->toBe('Café ☕ Ünïcodé');
});

it('neutralises spreadsheet formulas without touching numbers', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('text', ['key' => 'formula', 'label' => 'Formula']),
        exportField('text', ['key' => 'command', 'label' => 'Command']),
        exportField('number', ['key' => 'balance', 'label' => 'Balance']),
    ]);

    exportSubmission($form, [
        'formula' => '=SUM(A1:A2)',
        'command' => '@SUM(1+1)*cmd|/c calc',
        'balance' => -5,
    ]);

    $row = exportRows($owner, $form)[1];

    expect($row[3])->toBe("'=SUM(A1:A2)");
    expect($row[4])->toBe("'@SUM(1+1)*cmd|/c calc");
    // A negative number is a number, not a formula.
    expect($row[5])->toBe('-5');
});

it('guards a dangerous label in the header row', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('text', ['key' => 'full_name', 'label' => '=HYPERLINK("http://evil","click")']),
    ]);

    expect(exportRows($owner, $form)[0][3])->toBe('\'=HYPERLINK("http://evil","click")');
});

it('keeps a trailing backslash intact', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('text', ['key' => 'path_like', 'label' => 'Path Like']),
    ]);

    // PHP's default fputcsv escape would corrupt this.
    exportSubmission($form, ['path_like' => 'C:\\Users\\ada\\']);

    expect(exportRows($owner, $form)[1][3])->toBe('C:\\Users\\ada\\');
});

/*
|--------------------------------------------------------------------------
| Historical schema
|--------------------------------------------------------------------------
*/

it('keeps a removed field as a trailing column from its version snapshot', function () {
    $owner = exportOwner();

    $original = [
        exportField('text', ['key' => 'full_name', 'label' => 'Full Name']),
        exportField('text', ['key' => 'nickname', 'label' => 'Nickname']),
    ];

    $form = exportForm($owner, $original);

    // A real create flow would snapshot version 1; SchemaService::save() only
    // writes the version it creates.
    FormVersion::query()->create([
        'form_id' => $form->id,
        'version' => 1,
        'schema' => $form->schema,
        'note' => 'Initial version',
        'created_by' => $owner->id,
        'created_at' => now(),
    ]);

    exportSubmission($form, ['full_name' => 'Ada', 'nickname' => 'The Countess'], version: 1);

    app(SchemaService::class)->save($form, exportSchema([
        exportField('text', ['key' => 'full_name', 'label' => 'Full Name']),
        exportField('text', ['key' => 'company', 'label' => 'Company']),
    ]), $owner);

    exportSubmission($form->refresh(), ['full_name' => 'Grace', 'company' => 'Navy'], version: 2);

    $rows = exportRows($owner, $form);

    expect($rows[0])->toBe([
        'Submitted At', 'Status', 'Form Version',
        'Full Name', 'Company', 'Nickname (removed)',
    ]);
    expect(array_slice($rows[1], 3))->toBe(['Ada', '', 'The Countess']);
    expect(array_slice($rows[2], 3))->toBe(['Grace', 'Navy', '']);
});

it('recovers a removed field from the payload when no snapshot exists', function () {
    $owner = exportOwner();

    $form = exportForm($owner, [
        exportField('text', ['key' => 'full_name', 'label' => 'Full Name']),
    ]);

    // No FormVersion row at all, which is the normal state for a form that
    // never left version 1.
    exportSubmission($form, ['full_name' => 'Ada', 'nickname' => 'The Countess'], version: 1);

    $rows = exportRows($owner, $form);

    expect($rows[0])->toBe([
        'Submitted At', 'Status', 'Form Version',
        'Full Name', 'nickname (removed)',
    ]);
    expect(array_slice($rows[1], 3))->toBe(['Ada', 'The Countess']);
});

it('reads a historical snapshot without repairing it', function () {
    $owner = exportOwner();

    $form = exportForm($owner, [
        exportField('text', ['key' => 'company', 'label' => 'Company']),
    ], ['schema_version' => 2]);

    // Written directly rather than through SchemaService::save(), so the stored
    // shape is exactly what a legacy or imported snapshot could look like.
    FormVersion::query()->create([
        'form_id' => $form->id,
        'version' => 1,
        'schema' => [
            'title' => 'Legacy Intake',
            'sections' => [
                [
                    'fields' => [
                        // A key normalize would slugify into full_name.
                        ['key' => 'full-name', 'label' => 'Full name', 'type' => 'signature'],
                        // Options normalize would discard entirely.
                        ['key' => 'plan', 'label' => 'Plan', 'type' => 'dropdown', 'options' => [
                            ['value' => 'pro'],
                            'basic',
                        ]],
                    ],
                ],
            ],
        ],
        'note' => null,
        'created_by' => $owner->id,
        'created_at' => now(),
    ]);

    exportSubmission($form, ['full-name' => 'Ada', 'plan' => 'pro'], version: 1);

    $rows = exportRows($owner, $form);
    $headers = $rows[0];

    expect($headers)->toBe([
        'Submitted At', 'Status', 'Form Version',
        'Company', 'Full name (removed)', 'Plan (removed)',
    ]);

    // The historical key survived verbatim, so the column actually matches the
    // payload instead of exporting empty beside a duplicate orphan column.
    expect(array_slice($rows[1], 3))->toBe(['', 'Ada', 'pro']);
    expect($headers)->not->toContain('full_name (removed)');
});

/*
|--------------------------------------------------------------------------
| Immutability
|--------------------------------------------------------------------------
*/

it('issues no write statements while exporting', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [exportField('text', ['key' => 'full_name', 'label' => 'Full Name'])]);

    exportSubmission($form, ['full_name' => 'Ada']);

    $statements = [];

    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    exportCsv($owner, $form)->streamedContent();

    $writes = array_values(array_filter(
        $statements,
        fn (string $sql): bool => (bool) preg_match('/^\s*(insert|update|delete)\b/i', $sql),
    ));

    expect($writes)->toBe([]);
});

it('leaves the form and its versions untouched', function () {
    $owner = exportOwner();
    $form = exportForm($owner, [exportField('text', ['key' => 'full_name', 'label' => 'Full Name'])]);

    FormVersion::query()->create([
        'form_id' => $form->id,
        'version' => 1,
        'schema' => $form->schema,
        'note' => 'Initial version',
        'created_by' => $owner->id,
        'created_at' => now(),
    ]);

    exportSubmission($form, ['full_name' => 'Ada']);

    $before = [
        'schema' => $form->schema,
        'schema_version' => $form->schema_version,
        'updated_at' => $form->updated_at,
        'versions' => FormVersion::query()->orderBy('version')->pluck('schema')->all(),
        'version_count' => FormVersion::query()->count(),
        'submissions' => FormSubmission::query()->count(),
        'activity' => DB::table('activity_logs')->count(),
    ];

    exportCsv($owner, $form)->streamedContent();

    $form->refresh();

    expect($form->schema)->toBe($before['schema']);
    expect($form->schema_version)->toBe($before['schema_version']);
    expect($form->updated_at->eq($before['updated_at']))->toBeTrue();
    expect(FormVersion::query()->orderBy('version')->pluck('schema')->all())->toBe($before['versions']);
    expect(FormVersion::query()->count())->toBe($before['version_count']);
    expect(FormSubmission::query()->count())->toBe($before['submissions']);
    expect(DB::table('activity_logs')->count())->toBe($before['activity']);
});

it('never writes the repaired schema back over a legacy row', function () {
    $owner = exportOwner();

    $legacy = [
        'title' => 'Legacy Form',
        'description' => '',
        'sections' => [],
    ];

    $form = Form::factory()->for($owner)->create([
        'title' => 'Legacy Form',
        'schema' => $legacy,
    ]);

    exportCsv($owner, $form)->assertOk()->streamedContent();

    // Normalization happened in memory only.
    expect($form->refresh()->schema)->toBe($legacy);
});

/*
|--------------------------------------------------------------------------
| Performance
|--------------------------------------------------------------------------
*/

it('runs the same number of queries no matter how many submissions there are', function () {
    config(['formforge.export.csv_chunk_size' => 1000]);

    $owner = exportOwner();
    $form = exportForm($owner, [
        exportField('text', ['key' => 'full_name', 'label' => 'Full Name']),
        exportField('file', ['key' => 'resume', 'label' => 'Resume']),
    ]);

    $count = function () use ($owner, $form): int {
        $queries = 0;

        DB::listen(function () use (&$queries) {
            $queries++;
        });

        exportCsv($owner, $form)->streamedContent();

        return $queries;
    };

    foreach (range(1, 3) as $i) {
        exportSubmission($form, ['full_name' => 'Person '.$i, 'resume' => 'cv.pdf']);
    }

    $small = $count();

    foreach (range(4, 30) as $i) {
        exportSubmission($form, ['full_name' => 'Person '.$i, 'resume' => 'cv.pdf']);
    }

    $large = $count();

    expect($large)->toBe($small);
    expect(exportRows($owner, $form))->toHaveCount(31);
});
