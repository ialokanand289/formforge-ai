<?php

use App\Enums\FormStatus;
use App\Livewire\Forms\FormBuilder;
use App\Livewire\Forms\FormCreate;
use App\Livewire\Forms\FormIndex;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function complianceUser(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

function complianceForm(User $owner, array $attributes = []): Form
{
    return Form::factory()->for($owner)->create(array_merge(['title' => 'Client Intake'], $attributes));
}

function complianceBuilder(?User $owner = null, ?Form $form = null): Testable
{
    $owner ??= complianceUser();
    $form ??= complianceForm($owner);

    return Livewire::actingAs($owner)->test(FormBuilder::class, ['form' => $form]);
}

/**
 * The builder's current schema as the JSON the editor would be showing.
 */
function complianceDraft(Testable $component): array
{
    return json_decode($component->get('schemaDraft'), true);
}

function complianceEncode(array $schema): string
{
    return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Put a document in the editor and press Apply.
 */
function complianceApply(Testable $component, array|string $document): Testable
{
    return $component
        ->set('schemaDraft', is_string($document) ? $document : complianceEncode($document))
        ->call('applyJson');
}

/**
 * A field shaped the way normalize() leaves one, so it survives a round trip
 * without being rewritten.
 */
function complianceField(string $key, string $label, string $type = 'text', array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(),
        'type' => $type,
        'key' => $key,
        'label' => $label,
        'placeholder' => '',
        'help_text' => '',
        'default' => null,
        'required' => false,
        'options' => [],
        'validation' => [
            'min' => null,
            'max' => null,
            'min_length' => null,
            'max_length' => null,
            'regex' => null,
            'file_types' => [],
            'max_file_size_kb' => null,
        ],
        'conditions' => [],
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Manual creation
|--------------------------------------------------------------------------
*/

it('offers a create action on the forms index', function () {
    $owner = complianceUser();

    Livewire::actingAs($owner)->test(FormIndex::class)
        ->assertOk()
        ->assertSee('Create Form')
        ->assertSee(route('forms.create'), escape: false);
});

it('creates a form and hands it to the builder', function () {
    $owner = complianceUser();

    Livewire::actingAs($owner)->test(FormCreate::class)
        ->set('title', 'Employee Registration')
        ->set('description', 'Join the team.')
        ->call('create')
        ->assertHasNoErrors();

    $form = Form::query()->sole();

    expect($form->title)->toBe('Employee Registration')
        ->and($form->description)->toBe('Join the team.');
});

it('records the signed in user as the owner', function () {
    $owner = complianceUser();
    $other = complianceUser();

    Livewire::actingAs($owner)->test(FormCreate::class)
        ->set('title', 'Mine')
        ->call('create');

    expect(Form::query()->sole()->user_id)->toBe($owner->id)
        ->and(Form::query()->sole()->user_id)->not->toBe($other->id);
});

it('creates the form as a draft', function () {
    Livewire::actingAs(complianceUser())->test(FormCreate::class)
        ->set('title', 'Draft Form')
        ->call('create');

    expect(Form::query()->sole()->status)->toBe(FormStatus::Draft)
        ->and(Form::query()->sole()->published_at)->toBeNull();
});

it('mints a public token the browser never supplied', function () {
    Livewire::actingAs(complianceUser())->test(FormCreate::class)
        ->set('title', 'Tokened')
        ->call('create');

    $token = Form::query()->sole()->public_token;

    expect($token)->toBeString()
        ->and(Str::isUuid($token))->toBeTrue();
});

it('starts from a valid blank schema built by the service', function () {
    Livewire::actingAs(complianceUser())->test(FormCreate::class)
        ->set('title', 'Blank Start')
        ->set('description', 'A description.')
        ->call('create');

    $form = Form::query()->sole();
    $schemas = app(SchemaService::class);

    expect($schemas->isValid($form->schema))->toBeTrue()
        ->and($form->schema['title'])->toBe('Blank Start')
        // Carried into the schema, so the first builder save cannot wipe it.
        ->and($form->schema['description'])->toBe('A description.')
        ->and($form->schema['sections'])->toHaveCount(1)
        ->and($form->schema['sections'][0]['fields'])->toBe([])
        ->and($form->schema_version)->toBe(1);
});

it('creates no version row until the first save', function () {
    Livewire::actingAs(complianceUser())->test(FormCreate::class)
        ->set('title', 'No Version Yet')
        ->call('create');

    expect(FormVersion::query()->count())->toBe(0);
});

it('resolves a duplicate slug for the same user', function () {
    $owner = complianceUser();

    foreach (range(1, 3) as $ignored) {
        Livewire::actingAs($owner)->test(FormCreate::class)
            ->set('title', 'Contact Us')
            ->call('create');
    }

    expect(Form::query()->orderBy('created_at')->pluck('slug')->all())
        ->toBe(['contact-us', 'contact-us-2', 'contact-us-3']);
});

it('lets two users hold the same slug', function () {
    foreach ([complianceUser(), complianceUser()] as $user) {
        Livewire::actingAs($user)->test(FormCreate::class)
            ->set('title', 'Contact Us')
            ->call('create');
    }

    expect(Form::query()->pluck('slug')->all())->toBe(['contact-us', 'contact-us']);
});

it('treats a soft deleted form as still holding its slug', function () {
    $owner = complianceUser();

    Livewire::actingAs($owner)->test(FormCreate::class)
        ->set('title', 'Contact Us')
        ->call('create');

    Form::query()->sole()->delete();

    Livewire::actingAs($owner)->test(FormCreate::class)
        ->set('title', 'Contact Us')
        ->call('create');

    // The trashed row still occupies unique(user_id, slug).
    expect(Form::query()->sole()->slug)->toBe('contact-us-2');
});

it('steps past a slug that was free when it looked', function () {
    $owner = complianceUser();

    Form::factory()->for($owner)->create(['slug' => 'contact-us']);

    Livewire::actingAs($owner)->test(FormCreate::class)
        ->set('title', 'Contact Us')
        ->call('create')
        ->assertSet('createError', null);

    expect(Form::query()->where('slug', 'contact-us-2')->exists())->toBeTrue();
});

it('recovers when another request takes the slug between the check and the insert', function () {
    $owner = complianceUser();
    $raced = false;

    // Occupy the slug in the window the read-time check cannot cover, which is
    // the only way to reach the unique index and therefore the retry. Seeding
    // the row up front would not do it: uniqueSlug() would simply see it.
    DB::listen(function ($query) use ($owner, &$raced) {
        if ($raced || ! str_contains($query->sql, 'select exists')) {
            return;
        }

        $raced = true;

        Form::factory()->for($owner)->create(['slug' => 'contact-us']);
    });

    Livewire::actingAs($owner)->test(FormCreate::class)
        ->set('title', 'Contact Us')
        ->call('create')
        ->assertSet('createError', null);

    // The planted row was written inside the transaction, so the failed
    // attempt rolled it back with everything else. That is why the survivor
    // holds the original slug rather than -2, and why there is one row and not
    // two: the collision happened, was rolled back, and the second attempt
    // found the slug free again.
    $form = Form::query()->sole();

    expect($raced)->toBeTrue()
        ->and($form->title)->toBe('Contact Us')
        ->and($form->user_id)->toBe($owner->id);

    // Without the retry the first attempt's exception would surface here, so a
    // null createError is what proves the loop ran.
});

it('fails safely and leaves nothing behind when the retry budget runs out', function () {
    $owner = complianceUser();

    // Take whatever slug the component settles on, every time it looks.
    DB::listen(function ($query) use ($owner) {
        if (! str_contains($query->sql, 'select exists')) {
            return;
        }

        $slug = $query->bindings[1] ?? null;

        if (is_string($slug) && ! Form::withTrashed()->where('slug', $slug)->exists()) {
            Form::factory()->for($owner)->create(['slug' => $slug]);
        }
    });

    $component = Livewire::actingAs($owner)->test(FormCreate::class)
        ->set('title', 'Contact Us')
        ->call('create')
        ->assertNoRedirect()
        ->assertSet('createError', 'That form could not be created. Please try again.');

    // Nothing about the failure reaches the page.
    $component->assertDontSee('SQLSTATE')
        ->assertDontSee('Integrity constraint')
        ->assertDontSee('UniqueConstraintViolationException');

    // Only the rows the listener planted; the component created none.
    expect(Form::query()->where('title', 'Contact Us')->count())->toBe(0);
});

it('redirects to the builder for the new form', function () {
    Livewire::actingAs(complianceUser())->test(FormCreate::class)
        ->set('title', 'Redirect Me')
        ->call('create')
        ->assertRedirect(route('forms.builder', Form::query()->sole()));
});

it('rejects a creation it cannot validate', function (string $field, string $value) {
    Livewire::actingAs(complianceUser())->test(FormCreate::class)
        ->set($field, $value)
        ->call('create')
        ->assertHasErrors($field);

    expect(Form::query()->count())->toBe(0);
})->with([
    'no title' => ['title', ''],
    'title too long' => ['title', str_repeat('a', 256)],
    'description too long' => ['description', str_repeat('a', 2001)],
]);

it('refuses a guest', function () {
    $this->get(route('forms.create'))->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| Canvas to JSON
|--------------------------------------------------------------------------
*/

it('shows the current schema in the editor on mount', function () {
    $component = complianceBuilder();

    expect(complianceDraft($component))->toBe($component->get('schema'));

    // Rendered into the textarea rather than left to client hydration, so the
    // schema is on the page even before Livewire boots. assertSee escapes the
    // needle, which is what the quotes in the JSON become.
    $component->assertSee('"title": "Client Intake"');
});

it('follows a canvas mutation into the editor', function () {
    $component = complianceBuilder()->call('addField', 'email');

    expect(complianceDraft($component))->toBe($component->get('schema'))
        ->and($component->get('schemaDraft'))->toContain('"type": "email"');
});

it('follows a property edit into the editor', function () {
    $component = complianceBuilder()->call('addField', 'text');
    $component->set('fieldForm.label', 'Your Full Name');

    expect($component->get('schemaDraft'))->toContain('Your Full Name')
        ->and(complianceDraft($component))->toBe($component->get('schema'));
});

it('follows a section addition into the editor', function () {
    $component = complianceBuilder()->call('addSection');

    expect(complianceDraft($component)['sections'])->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| JSON to canvas
|--------------------------------------------------------------------------
*/

it('applies an edited document to the canvas', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['title'] = 'Renamed By Hand';
    $schema['sections'][0]['title'] = 'Applicant';

    complianceApply($component, $schema)->assertSet('jsonError', null);

    expect($component->get('schema')['title'])->toBe('Renamed By Hand')
        ->and($component->get('schema')['sections'][0]['title'])->toBe('Applicant');
});

it('applies a changed field property', function () {
    $component = complianceBuilder()->call('addField', 'text');

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][0]['label'] = 'Edited In JSON';
    $schema['sections'][0]['fields'][0]['required'] = true;

    complianceApply($component, $schema);

    $field = $component->get('schema')['sections'][0]['fields'][0];

    expect($field['label'])->toBe('Edited In JSON')
        ->and($field['required'])->toBeTrue();
});

it('applies a new field', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][] = complianceField('work_email', 'Work Email', 'email');

    complianceApply($component, $schema)->assertSet('jsonError', null);

    expect($component->get('schema')['sections'][0]['fields'])->toHaveCount(1)
        ->and($component->get('schema')['sections'][0]['fields'][0]['key'])->toBe('work_email');
});

it('applies a removed field', function () {
    $component = complianceBuilder()->call('addField', 'text')->call('addField', 'email');

    $schema = $component->get('schema');
    array_shift($schema['sections'][0]['fields']);

    complianceApply($component, $schema);

    expect($component->get('schema')['sections'][0]['fields'])->toHaveCount(1)
        ->and($component->get('schema')['sections'][0]['fields'][0]['type'])->toBe('email');
});

it('applies a new section', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][] = [
        'id' => (string) Str::ulid(),
        'title' => 'Address',
        'description' => null,
        'fields' => [],
    ];

    complianceApply($component, $schema)->assertSet('jsonError', null);

    expect($component->get('schema')['sections'])->toHaveCount(2)
        ->and($component->get('schema')['sections'][1]['title'])->toBe('Address');
});

it('applies a field moved between sections', function () {
    $component = complianceBuilder()->call('addField', 'text')->call('addSection');

    $schema = $component->get('schema');
    $moved = array_shift($schema['sections'][0]['fields']);
    $schema['sections'][1]['fields'][] = $moved;

    complianceApply($component, $schema)->assertSet('jsonError', null);

    expect($component->get('schema')['sections'][0]['fields'])->toBe([])
        ->and($component->get('schema')['sections'][1]['fields'][0]['id'])->toBe($moved['id']);
});

it('applies changed options', function () {
    $component = complianceBuilder()->call('addField', 'dropdown');

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][0]['options'] = [
        ['value' => 'hr', 'label' => 'HR'],
        ['value' => 'engineering', 'label' => 'Engineering'],
    ];

    complianceApply($component, $schema)->assertSet('jsonError', null);

    expect($component->get('schema')['sections'][0]['fields'][0]['options'])->toBe([
        ['value' => 'hr', 'label' => 'HR'],
        ['value' => 'engineering', 'label' => 'Engineering'],
    ]);
});

it('marks the builder dirty so Save stays the persistence path', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['title'] = 'Dirty Now';

    complianceApply($component, $schema)->assertSet('dirty', true);
});

/*
|--------------------------------------------------------------------------
| Rejected documents
|--------------------------------------------------------------------------
*/

it('rejects a document it cannot use', function (array|string $document, string $fragment) {
    $component = complianceBuilder()->call('addField', 'text');

    complianceApply($component, $document);

    expect($component->get('jsonError'))->toContain($fragment);
})->with([
    'malformed' => ['{"title": "Broken",', 'Invalid JSON'],
    'array root' => ['[{"title": "Nope"}]', 'must be a JSON object'],
    'scalar root' => ['"just a string"', 'must be a JSON object'],
    'no title' => [['sections' => []], 'non-empty title'],
    'blank title' => [['title' => '   ', 'sections' => []], 'non-empty title'],
    'no sections' => [['title' => 'Titled'], 'sections array'],
]);

it('rejects an invented field type instead of turning it into text', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][] = complianceField('signature', 'Signature', 'signature');

    complianceApply($component, $schema);

    expect($component->get('jsonError'))->toContain('Unsupported field type [signature]')
        ->and($component->get('schema')['sections'][0]['fields'])->toBe([]);
});

it('rejects a duplicate field key instead of suffixing it', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'] = [
        complianceField('full_name', 'Full Name'),
        complianceField('full_name', 'Full Name Again'),
    ];

    complianceApply($component, $schema);

    expect($component->get('jsonError'))->toContain('Duplicate field key [full_name]');
});

it('rejects a duplicate field id', function () {
    $component = complianceBuilder();

    $id = (string) Str::ulid();
    $schema = $component->get('schema');
    $schema['sections'][0]['fields'] = [
        complianceField('one', 'One', 'text', ['id' => $id]),
        complianceField('two', 'Two', 'text', ['id' => $id]),
    ];

    complianceApply($component, $schema);

    expect($component->get('jsonError'))->toContain('Duplicate field id');
});

it('rejects a duplicate section id', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][] = $schema['sections'][0];

    complianceApply($component, $schema);

    expect($component->get('jsonError'))->toContain('Duplicate section id');
});

it('rejects a choice field with no options', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][] = complianceField('department', 'Department', 'dropdown');

    complianceApply($component, $schema);

    expect($component->get('jsonError'))->toContain('require at least one option');
});

it('rejects a condition pointing at a field that does not exist', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][] = complianceField('notes', 'Notes', 'text', [
        'conditions' => [['field_key' => 'no_such_field', 'operator' => 'equals', 'value' => 'yes']],
    ]);

    complianceApply($component, $schema);

    expect($component->get('jsonError'))->toContain('unknown field key [no_such_field]');
});

/*
|--------------------------------------------------------------------------
| Silent repairs are rejected, not absorbed
|--------------------------------------------------------------------------
*/

it('rejects a key it would have to rewrite', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][] = complianceField('Full Name', 'Full Name');

    complianceApply($component, $schema);

    // normalize() would slugify this to full_name without saying so.
    expect($component->get('jsonError'))->toContain('The key [Full Name] is not usable as written')
        ->and($component->get('schema')['sections'][0]['fields'])->toBe([]);
});

it('rejects an id it would have to replace', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][] = complianceField('notes', 'Notes', 'text', ['id' => 'field-1']);

    complianceApply($component, $schema);

    expect($component->get('jsonError'))->toContain('The id [field-1] is not usable as written');
});

it('rejects a section it would have to drop', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][] = 'not a section at all';

    complianceApply($component, $schema);

    expect($component->get('jsonError'))->toContain('Every section must be an object');
});

it('rejects a field it would have to drop', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][] = 'not a field at all';

    complianceApply($component, $schema);

    expect($component->get('jsonError'))->toContain('Every field must be an object');
});

/*
|--------------------------------------------------------------------------
| Atomicity
|--------------------------------------------------------------------------
*/

it('changes nothing at all when a document is rejected', function (string $document) {
    $component = complianceBuilder()->call('addField', 'text');
    $fieldId = $component->get('schema')['sections'][0]['fields'][0]['id'];
    $component->call('selectField', $fieldId)->call('save');

    $before = [
        'schema' => $component->get('schema'),
        'field' => $component->get('selectedFieldId'),
        'section' => $component->get('selectedSectionId'),
        'fieldForm' => $component->get('fieldForm'),
        'dirty' => $component->get('dirty'),
    ];

    $component->set('schemaDraft', $document)->call('applyJson');

    expect($component->get('schema'))->toBe($before['schema'])
        ->and($component->get('selectedFieldId'))->toBe($before['field'])
        ->and($component->get('selectedSectionId'))->toBe($before['section'])
        ->and($component->get('fieldForm'))->toBe($before['fieldForm'])
        ->and($component->get('dirty'))->toBe($before['dirty'])
        // The rejected text stays put so it can be corrected.
        ->and($component->get('schemaDraft'))->toBe($document);
})->with([
    'malformed' => ['{"title": "Broken",'],
    'array root' => ['[]'],
    'no roots' => ['{}'],
    'bad type' => ['{"title":"T","sections":[{"id":"01HZZZZZZZZZZZZZZZZZZZZZZZ","title":"S","fields":[{"type":"signature","key":"s","label":"S"}]}]}'],
]);

it('leaves an unsaved canvas edit intact when a document is rejected', function () {
    $component = complianceBuilder()->call('addField', 'email')
        ->assertSet('dirty', true);

    $before = $component->get('schema');

    $component->set('schemaDraft', 'not json')->call('applyJson');

    expect($component->get('schema'))->toBe($before)
        ->and($component->get('dirty'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Selection
|--------------------------------------------------------------------------
*/

it('keeps the selection when the selected field survives', function () {
    $component = complianceBuilder()->call('addField', 'text');
    $fieldId = $component->get('schema')['sections'][0]['fields'][0]['id'];
    $component->call('selectField', $fieldId);

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][0]['label'] = 'Still Here';

    complianceApply($component, $schema);

    expect($component->get('selectedFieldId'))->toBe($fieldId)
        ->and($component->get('fieldForm')['label'])->toBe('Still Here');
});

it('clears the selection when the selected field is deleted', function () {
    $component = complianceBuilder()->call('addField', 'text');
    $fieldId = $component->get('schema')['sections'][0]['fields'][0]['id'];
    $component->call('selectField', $fieldId);

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'] = [];

    complianceApply($component, $schema);

    expect($component->get('selectedFieldId'))->toBeNull()
        ->and($component->get('fieldForm'))->toBe([]);
});

it('follows the selected field into its new section', function () {
    $component = complianceBuilder()->call('addField', 'text')->call('addSection');
    $fieldId = $component->get('schema')['sections'][0]['fields'][0]['id'];
    $component->call('selectField', $fieldId);

    $schema = $component->get('schema');
    $moved = array_shift($schema['sections'][0]['fields']);
    $schema['sections'][1]['fields'][] = $moved;

    complianceApply($component, $schema);

    expect($component->get('selectedFieldId'))->toBe($fieldId)
        ->and($component->get('selectedSectionId'))->toBe($schema['sections'][1]['id']);
});

/*
|--------------------------------------------------------------------------
| Formatting
|--------------------------------------------------------------------------
*/

it('pretty prints a valid document', function () {
    $component = complianceBuilder();

    $component->set('schemaDraft', '{"title":"Compact","sections":[]}')
        ->call('formatJson')
        ->assertSet('jsonError', null);

    expect($component->get('schemaDraft'))->toBe(
        "{\n    \"title\": \"Compact\",\n    \"sections\": []\n}"
    );
});

it('leaves an unparseable document exactly as typed', function () {
    $component = complianceBuilder();
    $broken = '{"title": "Broken",';

    $component->set('schemaDraft', $broken)->call('formatJson');

    expect($component->get('schemaDraft'))->toBe($broken)
        ->and($component->get('jsonError'))->toContain('Invalid JSON');
});

it('formats without applying, so the canvas is untouched', function () {
    $component = complianceBuilder();
    $before = $component->get('schema');

    $component->set('schemaDraft', '{"title":"Never Applied","sections":[]}')->call('formatJson');

    expect($component->get('schema'))->toBe($before)
        ->and($component->get('dirty'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Persistence
|--------------------------------------------------------------------------
*/

it('creates no version when JSON is applied', function () {
    $owner = complianceUser();
    $form = complianceForm($owner);
    $component = complianceBuilder($owner, $form);

    $schema = $component->get('schema');
    $schema['title'] = 'Applied Only';

    complianceApply($component, $schema);

    $form->refresh();

    expect(FormVersion::query()->where('form_id', $form->id)->count())->toBe(0)
        ->and($form->schema_version)->toBe(1)
        ->and($form->title)->toBe('Client Intake');
});

it('creates exactly one version when the applied JSON is saved', function () {
    $owner = complianceUser();
    $form = complianceForm($owner);
    $component = complianceBuilder($owner, $form);

    $schema = $component->get('schema');
    $schema['title'] = 'Applied Then Saved';

    complianceApply($component, $schema)->call('save')->assertSet('dirty', false);

    $form->refresh();

    expect($form->schema_version)->toBe(2)
        ->and($form->title)->toBe('Applied Then Saved')
        ->and(FormVersion::query()->where('form_id', $form->id)->count())->toBe(1);
});

it('reloads the persisted schema into the editor', function () {
    $owner = complianceUser();
    $form = complianceForm($owner);

    $component = complianceBuilder($owner, $form);
    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][] = complianceField('reloaded', 'Reloaded');

    complianceApply($component, $schema)->call('save');

    $reopened = complianceBuilder($owner, $form->refresh());

    expect(complianceDraft($reopened))->toBe($form->refresh()->schema)
        ->and($reopened->get('schemaDraft'))->toContain('reloaded');
});

/*
|--------------------------------------------------------------------------
| Authorization and safety
|--------------------------------------------------------------------------
*/

it('forbids a non owner from reaching the builder', function () {
    $form = complianceForm(complianceUser());

    Livewire::actingAs(complianceUser())->test(FormBuilder::class, ['form' => $form])
        ->assertForbidden();
});

it('escapes schema content rather than rendering it', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][] = complianceField('xss', '<script>alert(1)</script>');

    complianceApply($component, $schema)->assertSet('jsonError', null);

    // The label round-tripped, so the assertion below is about escaping rather
    // than about the content having been dropped.
    expect($component->get('schema')['sections'][0]['fields'][0]['label'])
        ->toBe('<script>alert(1)</script>');

    $component->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee('<script>alert(1)</script>');
});

/*
|--------------------------------------------------------------------------
| Regression
|--------------------------------------------------------------------------
*/

it('leaves the existing field operations working after a JSON apply', function () {
    $component = complianceBuilder();

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][] = complianceField('first', 'First');

    complianceApply($component, $schema)
        ->call('addField', 'email')
        ->call('addSection');

    $after = $component->get('schema');
    $fieldId = $after['sections'][0]['fields'][0]['id'];

    $component->call('duplicateField', $fieldId)
        ->call('moveField', $fieldId, $after['sections'][1]['id'], 0)
        ->assertSet('schemaError', null);

    $final = $component->get('schema');

    expect($final['sections'][1]['fields'][0]['id'])->toBe($fieldId)
        ->and(complianceDraft($component))->toBe($final);
});

it('keeps the property editor in step with an applied document', function () {
    $component = complianceBuilder()->call('addField', 'text');
    $fieldId = $component->get('schema')['sections'][0]['fields'][0]['id'];
    $component->call('selectField', $fieldId);

    $schema = $component->get('schema');
    $schema['sections'][0]['fields'][0]['required'] = true;
    $schema['sections'][0]['fields'][0]['placeholder'] = 'Set in JSON';

    complianceApply($component, $schema);

    expect($component->get('fieldForm')['required'])->toBeTrue()
        ->and($component->get('fieldForm')['placeholder'])->toBe('Set in JSON');

    // And the editor still writes back the other way.
    $component->set('fieldForm.placeholder', 'Set in the panel');

    expect(complianceDraft($component)['sections'][0]['fields'][0]['placeholder'])
        ->toBe('Set in the panel');
});
