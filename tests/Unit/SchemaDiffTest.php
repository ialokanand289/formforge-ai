<?php

use App\Services\SchemaService;

beforeEach(function () {
    $this->schemas = new SchemaService;
});

/**
 * A schema shaped like the ones normalize() produces, but written by hand so a
 * test can vary one property at a time.
 *
 * @param  list<array<string, mixed>>  $fields
 * @return array<string, mixed>
 */
function diffSchema(array $sections, string $title = 'Form', string $description = ''): array
{
    return [
        'schema_version' => 1,
        'title' => $title,
        'description' => $description,
        'settings' => ['multi_step' => false, 'submit_button_text' => 'Submit', 'success_message' => ''],
        'sections' => $sections,
    ];
}

/**
 * @param  list<array<string, mixed>>  $fields
 * @return array<string, mixed>
 */
function diffSection(string $id, string $title, array $fields = [], string $description = ''): array
{
    return ['id' => $id, 'title' => $title, 'description' => $description, 'fields' => $fields];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function diffField(string $id, string $key, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'type' => 'text',
        'key' => $key,
        'label' => ucfirst($key),
        'placeholder' => '',
        'help_text' => '',
        'default' => null,
        'required' => false,
        'options' => [],
        'validation' => [],
        'conditions' => [],
    ], $overrides);
}

const SECTION_A = '01HZZZZZZZZZZZZZZZZZZZZZA1';
const SECTION_B = '01HZZZZZZZZZZZZZZZZZZZZZB2';
const FIELD_A = '01HFFFFFFFFFFFFFFFFFFFFFA1';
const FIELD_B = '01HFFFFFFFFFFFFFFFFFFFFFB2';

/**
 * Find one section in the diff result by its displayed title.
 *
 * @param  array<string, mixed>  $diff
 * @return array<string, mixed>|null
 */
function sectionNamed(array $diff, string $title): ?array
{
    foreach ($diff['sections'] as $section) {
        if ($section['title'] === $title) {
            return $section;
        }
    }

    return null;
}

/**
 * @param  array<string, mixed>  $section
 * @return array<string, mixed>|null
 */
function fieldNamed(array $section, string $label): ?array
{
    foreach ($section['fields'] as $field) {
        if ($field['label'] === $label) {
            return $field;
        }
    }

    return null;
}

test('identical schemas report no changes at all', function () {
    $schema = diffSchema([
        diffSection(SECTION_A, 'Details', [diffField(FIELD_A, 'name')]),
    ]);

    $diff = $this->schemas->diff($schema, $schema);

    expect($diff['summary']['has_changes'])->toBeFalse()
        ->and($diff['title']['changed'])->toBeFalse()
        ->and($diff['sections'][0]['status'])->toBe('unchanged')
        ->and($diff['sections'][0]['fields'][0]['status'])->toBe('unchanged')
        ->and($diff['sections'][0]['fields'][0]['changes'])->toBe([]);
});

test('a section present only in the newer schema is added', function () {
    $from = diffSchema([diffSection(SECTION_A, 'Details')]);
    $to = diffSchema([
        diffSection(SECTION_A, 'Details'),
        diffSection(SECTION_B, 'Extras', [diffField(FIELD_B, 'extra')]),
    ]);

    $diff = $this->schemas->diff($from, $to);
    $added = sectionNamed($diff, 'Extras');

    expect($added['status'])->toBe('added')
        // Everything inside an added section is itself new.
        ->and($added['fields'][0]['status'])->toBe('added')
        ->and($diff['summary']['sections_added'])->toBe(1)
        ->and($diff['summary']['fields_added'])->toBe(1);
});

test('a section present only in the older schema is removed', function () {
    $from = diffSchema([
        diffSection(SECTION_A, 'Details'),
        diffSection(SECTION_B, 'Extras', [diffField(FIELD_B, 'extra')]),
    ]);
    $to = diffSchema([diffSection(SECTION_A, 'Details')]);

    $diff = $this->schemas->diff($from, $to);
    $removed = sectionNamed($diff, 'Extras');

    expect($removed['status'])->toBe('removed')
        ->and($removed['fields'][0]['status'])->toBe('removed')
        ->and($diff['summary']['sections_removed'])->toBe(1)
        ->and($diff['summary']['fields_removed'])->toBe(1);
});

test('a renamed section is changed rather than replaced', function () {
    $from = diffSchema([diffSection(SECTION_A, 'Details', [], 'Old blurb')]);
    $to = diffSchema([diffSection(SECTION_A, 'Your Details', [], 'New blurb')]);

    $diff = $this->schemas->diff($from, $to);

    expect($diff['sections'][0]['status'])->toBe('changed')
        ->and($diff['sections'][0]['changes']['title'])->toBe(['from' => 'Details', 'to' => 'Your Details'])
        ->and($diff['sections'][0]['changes']['description'])->toBe(['from' => 'Old blurb', 'to' => 'New blurb'])
        ->and($diff['summary']['sections_changed'])->toBe(1);
});

test('a section whose only edit is inside a field is still marked changed', function () {
    $from = diffSchema([diffSection(SECTION_A, 'Details', [diffField(FIELD_A, 'name')])]);
    $to = diffSchema([diffSection(SECTION_A, 'Details', [diffField(FIELD_A, 'name', ['required' => true])])]);

    $diff = $this->schemas->diff($from, $to);

    expect($diff['sections'][0]['status'])->toBe('changed')
        ->and($diff['sections'][0]['changes'])->toBe([])
        ->and($diff['sections'][0]['fields'][0]['status'])->toBe('changed');
});

test('fields added to and removed from an existing section are reported', function () {
    $from = diffSchema([diffSection(SECTION_A, 'Details', [diffField(FIELD_A, 'name')])]);
    $to = diffSchema([diffSection(SECTION_A, 'Details', [diffField(FIELD_B, 'email')])]);

    $diff = $this->schemas->diff($from, $to);
    $section = $diff['sections'][0];

    expect(fieldNamed($section, 'Email')['status'])->toBe('added')
        ->and(fieldNamed($section, 'Name')['status'])->toBe('removed')
        ->and($diff['summary']['fields_added'])->toBe(1)
        ->and($diff['summary']['fields_removed'])->toBe(1);
});

test('every compared field property is reported with its old and new value', function () {
    $from = diffSchema([diffSection(SECTION_A, 'Details', [
        diffField(FIELD_A, 'name', [
            'label' => 'Name',
            'type' => 'text',
            'placeholder' => 'Type here',
            'help_text' => 'Old help',
            'default' => 'a',
            'required' => false,
            'options' => [['value' => 'one', 'label' => 'One']],
            'validation' => ['max_length' => 10],
            'conditions' => [],
        ]),
    ])]);

    $to = diffSchema([diffSection(SECTION_A, 'Details', [
        diffField(FIELD_A, 'full_name', [
            'label' => 'Full Name',
            'type' => 'textarea',
            'placeholder' => 'Your name',
            'help_text' => 'New help',
            'default' => 'b',
            'required' => true,
            'options' => [['value' => 'two', 'label' => 'Two']],
            'validation' => ['max_length' => 20],
            'conditions' => [['field' => 'x', 'operator' => 'equals', 'value' => 'y']],
        ]),
    ])]);

    $changes = $this->schemas->diff($from, $to)['sections'][0]['fields'][0]['changes'];

    expect(array_keys($changes))->toEqualCanonicalizing([
        'label', 'key', 'type', 'placeholder', 'help_text',
        'default', 'required', 'options', 'validation', 'conditions',
    ])
        ->and($changes['label'])->toBe(['from' => 'Name', 'to' => 'Full Name'])
        ->and($changes['key'])->toBe(['from' => 'name', 'to' => 'full_name'])
        ->and($changes['type'])->toBe(['from' => 'text', 'to' => 'textarea'])
        ->and($changes['required'])->toBe(['from' => false, 'to' => true])
        ->and($changes['options']['from'])->toBe([['value' => 'one', 'label' => 'One']])
        ->and($changes['validation']['to'])->toBe(['max_length' => 20]);
});

test('reordering options counts as a change because order is what respondents see', function () {
    $options = [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']];

    $from = diffSchema([diffSection(SECTION_A, 'S', [diffField(FIELD_A, 'pick', ['options' => $options])])]);
    $to = diffSchema([diffSection(SECTION_A, 'S', [diffField(FIELD_A, 'pick', ['options' => array_reverse($options)])])]);

    expect($this->schemas->diff($from, $to)['sections'][0]['fields'][0]['changes'])->toHaveKey('options');
});

test('the form title and description are compared', function () {
    $from = diffSchema([], 'Old Title', 'Old description');
    $to = diffSchema([], 'New Title', 'Old description');

    $diff = $this->schemas->diff($from, $to);

    expect($diff['title'])->toBe(['from' => 'Old Title', 'to' => 'New Title', 'changed' => true])
        ->and($diff['description']['changed'])->toBeFalse()
        ->and($diff['summary']['has_changes'])->toBeTrue();
});

test('a legacy snapshot with no ids is matched on field key and section title', function () {
    $from = diffSchema([[
        'title' => 'Details',
        'fields' => [['type' => 'text', 'key' => 'name', 'label' => 'Name']],
    ]]);

    $to = diffSchema([[
        'title' => 'Details',
        'fields' => [['type' => 'text', 'key' => 'name', 'label' => 'Full Name']],
    ]]);

    $diff = $this->schemas->diff($from, $to);

    expect($diff['sections'])->toHaveCount(1)
        ->and($diff['sections'][0]['status'])->toBe('changed')
        ->and($diff['sections'][0]['fields'][0]['changes'])->toBe([
            'label' => ['from' => 'Name', 'to' => 'Full Name'],
        ]);
});

test('a malformed id falls back to the key instead of splitting the pair', function () {
    $from = diffSchema([diffSection(SECTION_A, 'Details', [
        diffField('', 'name') + ['id' => ['not', 'a', 'string']],
    ])]);

    $to = diffSchema([diffSection(SECTION_A, 'Details', [
        diffField('', 'name', ['label' => 'Renamed']) + ['id' => null],
    ])]);

    $fields = $this->schemas->diff($from, $to)['sections'][0]['fields'];

    expect($fields)->toHaveCount(1)
        ->and($fields[0]['status'])->toBe('changed');
});

test('an id survives a change of case so the pair still matches', function () {
    $from = diffSchema([diffSection(strtolower(SECTION_A), 'Details', [
        diffField(strtolower(FIELD_A), 'name'),
    ])]);

    $to = diffSchema([diffSection(SECTION_A, 'Details', [
        diffField(FIELD_A, 'name', ['required' => true]),
    ])]);

    $diff = $this->schemas->diff($from, $to);

    expect($diff['sections'])->toHaveCount(1)
        ->and($diff['sections'][0]['fields'])->toHaveCount(1)
        ->and($diff['sections'][0]['fields'][0]['changes'])->toHaveKey('required');
});

test('an id change is read as a removal and an addition', function () {
    $from = diffSchema([diffSection(SECTION_A, 'Details', [diffField(FIELD_A, 'name')])]);
    $to = diffSchema([diffSection(SECTION_A, 'Details', [diffField(FIELD_B, 'name')])]);

    $section = $this->schemas->diff($from, $to)['sections'][0];

    expect($section['fields'])->toHaveCount(2)
        ->and(collect($section['fields'])->pluck('status')->all())->toEqualCanonicalizing(['added', 'removed']);
});

test('duplicate identities are kept apart rather than swallowing each other', function () {
    $duplicated = [diffField(FIELD_A, 'name'), diffField(FIELD_A, 'name')];

    $from = diffSchema([diffSection(SECTION_A, 'Details', $duplicated)]);
    $to = diffSchema([diffSection(SECTION_A, 'Details', $duplicated)]);

    expect($this->schemas->diff($from, $to)['sections'][0]['fields'])->toHaveCount(2);
});

test('null and empty string are treated as the same absent value', function () {
    $from = diffSchema([diffSection(SECTION_A, 'S', [
        diffField(FIELD_A, 'name', ['placeholder' => null, 'help_text' => null, 'required' => 0, 'options' => null]),
    ])]);

    $to = diffSchema([diffSection(SECTION_A, 'S', [
        diffField(FIELD_A, 'name', ['placeholder' => '', 'help_text' => '', 'required' => false, 'options' => []]),
    ])]);

    expect($this->schemas->diff($from, $to)['sections'][0]['fields'][0]['status'])->toBe('unchanged');
});

test('malformed sections and fields are skipped instead of crashing', function () {
    $from = diffSchema([
        'not a section',
        null,
        ['id' => SECTION_A, 'title' => 'Details', 'fields' => ['not a field', 42, diffField(FIELD_A, 'name')]],
    ]);

    $to = diffSchema([
        ['id' => SECTION_A, 'title' => 'Details', 'fields' => null],
    ]);

    $diff = $this->schemas->diff($from, $to);

    expect($diff['sections'])->toHaveCount(1)
        // The two junk entries contribute nothing; only the real field survives.
        ->and($diff['sections'][0]['fields'])->toHaveCount(1)
        ->and($diff['sections'][0]['fields'][0]['status'])->toBe('removed');
});

test('entirely empty and entirely malformed input still produces a result', function () {
    expect($this->schemas->diff([], [])['summary']['has_changes'])->toBeFalse()
        ->and($this->schemas->diff(['sections' => 'nope'], ['sections' => 7])['sections'])->toBe([])
        ->and($this->schemas->diff(['title' => ['array']], ['title' => null])['title']['changed'])->toBeFalse();
});

test('a section or field with no id title or key is matched on its position', function () {
    $from = diffSchema([['fields' => [['type' => 'text']]]]);
    $to = diffSchema([['fields' => [['type' => 'email']]]]);

    $diff = $this->schemas->diff($from, $to);

    expect($diff['sections'])->toHaveCount(1)
        ->and($diff['sections'][0]['title'])->toBe('Untitled Section')
        ->and($diff['sections'][0]['fields'][0]['label'])->toBe('Untitled Field')
        ->and($diff['sections'][0]['fields'][0]['changes']['type'])->toBe(['from' => 'text', 'to' => 'email']);
});

test('diff mutates neither of its arguments', function () {
    $from = diffSchema([diffSection(SECTION_A, 'Details', [diffField(FIELD_A, 'name')])]);
    $to = diffSchema([
        diffSection(SECTION_A, 'Renamed', [diffField(FIELD_A, 'name', ['required' => true])]),
        diffSection(SECTION_B, 'Extras'),
    ]);

    $fromBefore = $from;
    $toBefore = $to;

    $this->schemas->diff($from, $to);

    expect($from)->toBe($fromBefore)
        ->and($to)->toBe($toBefore);
});

test('a malformed snapshot is not repaired on its way through the diff', function () {
    // A version stored before ids existed must be reported as it stands, not
    // as normalize() would rewrite it.
    $legacy = ['sections' => [['title' => 'Old', 'fields' => [['key' => 'Some Key', 'label' => 'Q1']]]]];
    $before = $legacy;

    $diff = $this->schemas->diff($legacy, $legacy);

    expect($legacy)->toBe($before)
        // slugifyKey() would have turned this into some_key had normalize run.
        ->and($diff['sections'][0]['fields'][0]['key'])->toBe('Some Key');
});
