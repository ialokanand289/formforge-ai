<?php

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Build a published form whose schema carries the given fields in one section.
 */
function publishedForm(array $fields = [], array $overrides = [], array $settings = []): Form
{
    $schema = app(SchemaService::class)->normalize([
        'schema_version' => 1,
        'title' => $overrides['title'] ?? 'Client Intake',
        'description' => $overrides['description'] ?? 'Tell us about your project.',
        'settings' => array_merge(['submit_button_text' => 'Send it'], $settings),
        'sections' => [
            [
                'id' => (string) Str::ulid(),
                'title' => 'About you',
                'description' => 'Basic details.',
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
        ], array_diff_key($overrides, ['description' => null])));
}

function field(string $type, array $attributes = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(),
        'type' => $type,
        'key' => $type.'_field',
        'label' => Str::headline($type).' Field',
    ], $attributes);
}

function visit(Form $form)
{
    return test()->get(route('forms.public', ['token' => $form->public_token]));
}

it('renders a published form to a guest', function () {
    $form = publishedForm([field('text')]);

    visit($form)
        ->assertOk()
        ->assertSee('Client Intake')
        ->assertSee('Tell us about your project.')
        ->assertSee('About you');
});

it('does not redirect a guest to login', function () {
    $form = publishedForm([field('text')]);

    visit($form)->assertOk()->assertDontSee('Log in');
});

it('returns 404 for a draft form', function () {
    $form = publishedForm([field('text')], ['status' => FormStatus::Draft]);

    visit($form)->assertNotFound();
});

it('returns 404 for an archived form', function () {
    $form = publishedForm([field('text')], ['status' => FormStatus::Archived]);

    visit($form)->assertNotFound();
});

it('returns 404 for an unknown token', function () {
    test()->get(route('forms.public', ['token' => (string) Str::uuid()]))->assertNotFound();
});

it('returns 404 for a soft deleted form', function () {
    $form = publishedForm([field('text')]);

    $form->delete();

    visit($form)->assertNotFound();
});

it('renders every supported field type', function () {
    $form = publishedForm([
        field('text'),
        field('textarea'),
        field('number'),
        field('email'),
        field('phone'),
        field('date'),
        field('dropdown', ['options' => [['value' => 'a', 'label' => 'Option A']]]),
        field('radio', ['options' => [['value' => 'b', 'label' => 'Option B']]]),
        field('checkbox', ['options' => [['value' => 'c', 'label' => 'Option C']]]),
        field('file'),
        field('heading'),
        field('rating'),
    ]);

    $response = visit($form)->assertOk();

    $response->assertSee('type="text"', false);
    $response->assertSee('<textarea', false);
    $response->assertSee('type="number"', false);
    $response->assertSee('type="email"', false);
    $response->assertSee('type="tel"', false);
    $response->assertSee('type="date"', false);
    $response->assertSee('<select', false);
    $response->assertSee('type="radio"', false);
    $response->assertSee('type="checkbox"', false);
    $response->assertSee('type="file"', false);
    $response->assertSee('Rating Field');
});

it('marks required fields for sighted and assistive users', function () {
    $form = publishedForm([field('text', ['required' => true])]);

    visit($form)
        ->assertSee('aria-required="true"', false)
        ->assertSee('(required)');
});

it('renders placeholders help text and defaults', function () {
    $form = publishedForm([
        field('text', [
            'placeholder' => 'Jane Doe',
            'help_text' => 'Use your legal name.',
            'default' => 'Prefilled',
        ]),
    ]);

    visit($form)
        ->assertSee('placeholder="Jane Doe"', false)
        ->assertSee('Use your legal name.')
        ->assertSee('value="Prefilled"', false)
        ->assertSee('aria-describedby=', false);
});

it('renders every choice option with its value and label', function () {
    $form = publishedForm([
        field('dropdown', ['key' => 'plan', 'options' => [
            ['value' => 'basic', 'label' => 'Basic plan'],
            ['value' => 'pro', 'label' => 'Pro plan'],
        ]]),
        field('radio', ['key' => 'contact', 'options' => [
            ['value' => 'email', 'label' => 'By email'],
        ]]),
        field('checkbox', ['key' => 'topics', 'options' => [
            ['value' => 'news', 'label' => 'Newsletter'],
        ]]),
    ]);

    visit($form)
        ->assertSee('value="basic"', false)
        ->assertSee('Basic plan')
        ->assertSee('value="pro"', false)
        ->assertSee('Pro plan')
        ->assertSee('By email')
        ->assertSee('Newsletter')
        ->assertSee('name="topics[]"', false);
});

it('renders a heading without an input', function () {
    $form = publishedForm([
        field('heading', ['label' => 'Section notes']),
        field('text', ['key' => 'full_name']),
    ]);

    $html = visit($form)->assertOk()->getContent();

    expect($html)->toContain('Section notes');
    expect($html)->not->toContain('name="heading_field"');
    expect($html)->toContain('name="full_name"');
});

it('advertises file limits without accepting uploads', function () {
    $form = publishedForm([
        field('file', ['validation' => ['file_types' => ['pdf', 'PNG'], 'max_file_size_kb' => 2048]]),
    ]);

    visit($form)
        ->assertSee('accept=".pdf,.png"', false)
        ->assertSee('Maximum size: 2048 KB.');
});

it('renders the configured rating scale', function () {
    $form = publishedForm([
        field('rating', ['validation' => ['min' => 1, 'max' => 3]]),
    ]);

    $html = visit($form)->assertOk()->getContent();

    expect($html)->toContain('value="1"');
    expect($html)->toContain('value="3"');
    expect($html)->not->toContain('value="4"');
});

it('renders the configured submit button text as a disabled control', function () {
    $form = publishedForm([field('text')]);

    visit($form)
        ->assertSee('Send it')
        ->assertSee('Submissions are not accepted yet');
});

it('escapes schema content authored by the form owner', function () {
    $form = publishedForm([
        field('text', [
            'label' => '<script>alert(1)</script>',
            'help_text' => '<img src=x onerror=alert(2)>',
        ]),
    ]);

    $html = visit($form)->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->not->toContain('<img src=x onerror=alert(2)>');
    expect($html)->toContain('&lt;script&gt;');
});

it('drops a regex pattern that carries flags', function () {
    $withFlags = publishedForm([
        field('text', ['validation' => ['regex' => '/^abc$/i']]),
    ]);

    $plain = publishedForm([
        field('text', ['validation' => ['regex' => '/^abc$/']]),
    ]);

    expect(visit($withFlags)->getContent())->not->toContain('pattern=');
    expect(visit($plain)->getContent())->toContain('pattern="^abc$"');
});

it('renders a legacy schema that predates the current roots', function () {
    $form = Form::factory()->published()->create([
        'title' => 'Legacy Form',
        'schema' => [
            'title' => 'Legacy Form',
            'description' => '',
            'sections' => [],
        ],
    ]);

    visit($form)
        ->assertOk()
        ->assertSee('Legacy Form')
        ->assertSee('This form has no questions yet');
});

it('shows the empty state and no submit button when there is nothing to answer', function () {
    $form = publishedForm([field('heading')]);

    visit($form)
        ->assertSee('This form has no questions yet')
        ->assertDontSee('Send it');
});

it('never writes to the database while rendering', function () {
    $form = Form::factory()->published()->create([
        'title' => 'Legacy Form',
        'schema' => [
            'title' => 'Legacy Form',
            'description' => '',
            'sections' => [],
        ],
    ]);

    $before = $form->only(['schema', 'schema_version', 'updated_at']);

    visit($form)->assertOk();

    $form->refresh();

    // The normalization applied for rendering must stay in memory.
    expect($form->schema)->toBe($before['schema']);
    expect($form->schema_version)->toBe($before['schema_version']);
    expect($form->updated_at->eq($before['updated_at']))->toBeTrue();
    expect(FormVersion::query()->count())->toBe(0);
    expect(FormSubmission::query()->count())->toBe(0);
});

it('keeps owner and internal identifiers out of the markup', function () {
    $owner = User::factory()->create([
        'name' => 'Olivia Owner',
        'email' => 'owner@example.test',
    ]);
    $form = Form::factory()->published()->for($owner)->create(['title' => 'Client Intake']);

    $html = visit($form)->assertOk()->getContent();

    expect($html)->not->toContain($form->id);
    expect($html)->not->toContain($form->slug);
    expect($html)->not->toContain($owner->email);
    expect($html)->not->toContain($owner->name);
});

it('keeps schema internals out of the livewire snapshot', function () {
    $form = publishedForm([field('text', ['key' => 'full_name'])]);

    $sectionId = $form->schema['sections'][0]['id'];
    $fieldId = $form->schema['sections'][0]['fields'][0]['id'];

    $html = visit($form)->assertOk()->getContent();

    // Only the token round trips; the schema is rebuilt server side per request.
    expect($html)->not->toContain($sectionId);
    expect($html)->not->toContain($fieldId);
    expect($html)->toContain('id="field-full_name"');
});
