<?php

use App\Enums\SubmissionStatus;
use App\Livewire\Forms\FormSubmissions;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function listUser(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

/**
 * A published form at version 1 with its snapshot on record, so submission
 * labels can be resolved from history.
 */
function listForm(User $owner): Form
{
    $schema = app(SchemaService::class)->normalize([
        'title' => 'Client Intake',
        'sections' => [[
            'title' => 'Details',
            'fields' => [
                ['type' => 'text', 'key' => 'full_name', 'label' => 'Full Name'],
                ['type' => 'email', 'key' => 'work_email', 'label' => 'Work Email'],
            ],
        ]],
    ]);

    $form = Form::factory()->for($owner)->published()->create([
        'title' => 'Client Intake',
        'schema' => $schema,
        'schema_version' => 1,
    ]);

    FormVersion::query()->create([
        'form_id' => $form->id,
        'version' => 1,
        'schema' => $schema,
        'note' => 'Initial version',
        'created_by' => $owner->id,
        'created_at' => now(),
    ]);

    return $form;
}

function listSubmission(Form $form, array $attributes = []): FormSubmission
{
    return FormSubmission::factory()->for($form)->create($attributes);
}

function listPage(User $actor, Form $form): Testable
{
    return Livewire::actingAs($actor)->test(FormSubmissions::class, ['form' => $form]);
}

test('the list shows this forms submissions newest first', function () {
    $owner = listUser();
    $form = listForm($owner);

    listSubmission($form, ['search_text' => 'Oldest answer', 'created_at' => now()->subDays(2)]);
    listSubmission($form, ['search_text' => 'Middle answer', 'created_at' => now()->subDay()]);
    listSubmission($form, ['search_text' => 'Newest answer', 'created_at' => now()]);

    listPage($owner, $form)
        ->assertOk()
        ->assertSeeInOrder(['Newest answer', 'Middle answer', 'Oldest answer']);
});

test('the list is paginated at fifteen per page', function () {
    $owner = listUser();
    $form = listForm($owner);

    FormSubmission::factory()->for($form)->count(20)->create();

    $page = listPage($owner, $form)->instance()->submissions();

    expect($page->perPage())->toBe(15)
        ->and($page->total())->toBe(20)
        ->and($page->count())->toBe(15);
});

test('another forms submissions never appear in this list', function () {
    $owner = listUser();
    $form = listForm($owner);
    $other = listForm($owner);

    listSubmission($form, ['search_text' => 'Belongs here']);
    listSubmission($other, ['search_text' => 'Belongs elsewhere']);

    listPage($owner, $form)
        ->assertSee('Belongs here')
        ->assertDontSee('Belongs elsewhere');
});

test('search narrows the list to matching answers', function () {
    $owner = listUser();
    $form = listForm($owner);

    listSubmission($form, ['search_text' => 'Ada Lovelace ada@example.com']);
    listSubmission($form, ['search_text' => 'Grace Hopper grace@example.com']);

    listPage($owner, $form)
        ->set('search', 'Lovelace')
        ->assertSee('Ada Lovelace')
        ->assertDontSee('Grace Hopper');
});

test('a search that matches nothing reports an empty result', function () {
    $owner = listUser();
    $form = listForm($owner);

    listSubmission($form, ['search_text' => 'Ada Lovelace']);

    listPage($owner, $form)
        ->set('search', 'nobody by that name')
        ->assertSee('No submissions found')
        ->assertDontSee('Ada Lovelace');
});

test('wildcards in a search term are matched literally', function () {
    $owner = listUser();
    $form = listForm($owner);

    listSubmission($form, ['search_text' => 'Discount was 50% this quarter']);
    listSubmission($form, ['search_text' => 'No discount applied']);

    // Unescaped, a bare "%" is the match-everything wildcard. Escaped, it only
    // finds the row that actually contains a percent sign.
    listPage($owner, $form)
        ->set('search', '%')
        ->assertSee('Discount was 50%')
        ->assertDontSee('No discount applied');

    listPage($owner, $form)
        ->set('search', '50%')
        ->assertSee('Discount was 50%')
        ->assertDontSee('No discount applied');
});

test('an underscore in a search term does not act as a single character wildcard', function () {
    $owner = listUser();
    $form = listForm($owner);

    listSubmission($form, ['search_text' => 'reference a_1 confirmed']);
    listSubmission($form, ['search_text' => 'reference ab1 rejected']);

    listPage($owner, $form)
        ->set('search', 'a_1')
        ->assertSee('confirmed')
        ->assertDontSee('rejected');
});

test('a backslash in a search term is matched literally', function () {
    $owner = listUser();
    $form = listForm($owner);

    listSubmission($form, ['search_text' => 'path c:\\temp\\notes']);
    listSubmission($form, ['search_text' => 'path elsewhere']);

    listPage($owner, $form)
        ->set('search', 'c:\\temp')
        ->assertSee('c:\\temp\\notes')
        ->assertDontSee('path elsewhere');
});

test('the status filter narrows the list to one status', function () {
    $owner = listUser();
    $form = listForm($owner);

    listSubmission($form, ['search_text' => 'A genuine answer', 'status' => SubmissionStatus::Completed]);
    listSubmission($form, ['search_text' => 'A junk answer', 'status' => SubmissionStatus::Spam]);

    listPage($owner, $form)
        ->set('statusFilter', SubmissionStatus::Spam->value)
        ->assertSee('A junk answer')
        ->assertDontSee('A genuine answer');

    listPage($owner, $form)
        ->set('statusFilter', SubmissionStatus::Completed->value)
        ->assertSee('A genuine answer')
        ->assertDontSee('A junk answer');
});

test('an unrecognised status filter is ignored rather than hiding everything', function () {
    $owner = listUser();
    $form = listForm($owner);

    listSubmission($form, ['search_text' => 'A genuine answer']);

    listPage($owner, $form)
        ->set('statusFilter', 'nonsense')
        ->assertSee('A genuine answer');
});

test('changing the search or filter returns to the first page', function () {
    $owner = listUser();
    $form = listForm($owner);

    FormSubmission::factory()->for($form)->count(20)->create();

    listPage($owner, $form)
        ->call('setPage', 2)
        ->set('search', 'anything')
        ->assertSet('paginators.page', 1)
        ->set('statusFilter', SubmissionStatus::Spam->value)
        ->assertSet('paginators.page', 1);
});

test('opening a submission shows its answers labelled from its own schema version', function () {
    $owner = listUser();
    $form = listForm($owner);

    $submission = listSubmission($form, [
        'payload' => ['full_name' => 'Ada Lovelace', 'work_email' => 'ada@example.com'],
        'search_text' => 'Ada Lovelace ada@example.com',
    ]);

    // The current schema renames the field; the answer must keep its old label.
    $renamed = $form->schema;
    $renamed['sections'][0]['fields'][0]['label'] = 'Legal Name';
    app(SchemaService::class)->save($form, $renamed, $owner);

    $selected = listPage($owner, $form->refresh())
        ->call('select', $submission->id)
        ->assertSee('Full Name')
        ->assertDontSee('Legal Name')
        ->instance()
        ->selected();

    expect($selected['version'])->toBe(1)
        ->and(collect($selected['answers'])->firstWhere('key', 'full_name')['label'])->toBe('Full Name')
        ->and(collect($selected['answers'])->firstWhere('key', 'work_email')['value'])->toBe('ada@example.com');
});

test('an answer with no snapshot to name it falls back to its raw key', function () {
    $owner = listUser();
    $form = listForm($owner);

    $submission = listSubmission($form, [
        // A version with no snapshot on record, which is what a pruned or
        // pre-versioning history looks like.
        'form_version' => 7,
        'payload' => ['mystery_field' => 'some answer'],
        'search_text' => 'some answer',
    ]);

    $selected = listPage($owner, $form)->call('select', $submission->id)->instance()->selected();

    expect($selected['answers'][0]['label'])->toBe('mystery_field')
        ->and($selected['answers'][0]['value'])->toBe('some answer');
});

test('list values are rendered readably', function () {
    $owner = listUser();
    $form = listForm($owner);

    $submission = listSubmission($form, [
        'payload' => ['equipment' => ['laptop', 'monitor'], 'subscribe' => true, 'notes' => null],
    ]);

    $answers = collect(listPage($owner, $form)->call('select', $submission->id)->instance()->selected()['answers'])
        ->keyBy('key');

    expect($answers['equipment']['value'])->toBe('laptop, monitor')
        ->and($answers['subscribe']['value'])->toBe('Yes')
        ->and($answers['notes']['value'])->toBe('');
});

test('attached files are listed by name with no download link', function () {
    $owner = listUser();
    $form = listForm($owner);

    $submission = listSubmission($form, ['payload' => ['cv' => 'resume.pdf']]);

    SubmissionFile::query()->create([
        'form_submission_id' => $submission->id,
        'field_key' => 'cv',
        'original_name' => 'resume.pdf',
        'disk' => 'local',
        'path' => 'form-uploads/secret/path/resume.pdf',
        'mime_type' => 'application/pdf',
        'size' => 2048,
        'created_at' => now(),
    ]);

    listPage($owner, $form)
        ->call('select', $submission->id)
        ->assertSee('resume.pdf')
        // The storage path is private and must never reach the page.
        ->assertDontSee('form-uploads/secret/path');
});

test('a submission belonging to another form cannot be opened', function () {
    $owner = listUser();
    $form = listForm($owner);
    $other = listForm($owner);

    $foreign = listSubmission($other, ['search_text' => 'Belongs elsewhere']);

    $component = listPage($owner, $form)->call('select', $foreign->id);

    expect($component->instance()->selected())->toBeNull();

    $component->assertDontSee('Belongs elsewhere');
});

test('another users submissions are forbidden', function () {
    $owner = listUser();
    $stranger = listUser();
    $form = listForm($owner);

    listSubmission($form, ['search_text' => 'Private answer']);

    Livewire::actingAs($stranger)->test(FormSubmissions::class, ['form' => $form])
        ->assertForbidden();

    $this->actingAs($stranger)->get(route('forms.submissions.index', $form))->assertForbidden();
});

test('the submissions page requires a signed in user', function () {
    $form = listForm(listUser());

    $this->get(route('forms.submissions.index', $form))->assertRedirect(route('login'));
});

test('browsing searching and filtering write nothing to the database', function () {
    $owner = listUser();
    $form = listForm($owner);

    $submission = listSubmission($form, [
        'payload' => ['full_name' => 'Ada Lovelace'],
        'search_text' => 'Ada Lovelace',
    ]);

    $writes = [];

    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    listPage($owner, $form)
        ->set('search', 'Ada')
        ->set('statusFilter', SubmissionStatus::Completed->value)
        ->call('select', $submission->id);

    expect($writes)->toBe([]);
});
