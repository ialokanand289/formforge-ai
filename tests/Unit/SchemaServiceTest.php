<?php

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\User;
use App\Services\SchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->schemas = new SchemaService;
});

test('blank returns a complete valid schema', function () {
    $schema = $this->schemas->blank('My Form');

    expect($schema)
        ->schema_version->toBe(1)
        ->title->toBe('My Form')
        ->description->toBe('')
        ->settings->toHaveKeys(['multi_step', 'submit_button_text', 'success_message'])
        ->sections->toBeArray()
        ->and($this->schemas->isValid($schema))->toBeTrue()
        ->and($schema['sections'][0]['id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/i')
        ->and($schema['sections'][0]['id'])->not->toStartWith('sec_');
});

test('normalize trims strings slugifies keys and applies defaults', function () {
    $schema = $this->schemas->normalize([
        'schema_version' => 1,
        'title' => '  Hello  ',
        'description' => '  Desc  ',
        'settings' => ['submit_button' => ' Save '],
        'sections' => [
            [
                'title' => ' Sec ',
                'fields' => [
                    [
                        'type' => 'text',
                        'label' => ' Full Name ',
                        'key' => 'Full Name!',
                        'options' => [
                            ['value' => 'a', 'label' => 'A'],
                            null,
                            ['value' => '', 'label' => 'bad'],
                            ['value' => 'b', 'label' => null],
                            'invalid',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect($schema['title'])->toBe('Hello')
        ->and($schema['description'])->toBe('Desc')
        ->and($schema['settings']['submit_button_text'])->toBe('Save')
        ->and($schema['sections'][0]['fields'][0]['key'])->toBe('full_name')
        ->and($schema['sections'][0]['fields'][0]['options'])->toBe([
            ['value' => 'a', 'label' => 'A'],
        ])
        ->and($schema['sections'][0]['fields'][0])->toHaveKeys([
            'id', 'type', 'key', 'label', 'placeholder', 'help_text', 'default',
            'required', 'options', 'validation', 'conditions',
        ]);
});

test('field and section ids are raw ulids without prefixes', function () {
    $schema = $this->schemas->blank();
    $schema = $this->schemas->addField($schema, $schema['sections'][0]['id'], FieldType::Text, [
        'label' => 'Name',
    ]);

    $fieldId = $schema['sections'][0]['fields'][0]['id'];

    expect($fieldId)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/i')
        ->and($fieldId)->not->toStartWith('fld_')
        ->and($fieldId)->not->toStartWith('sec_');
});

test('rejects missing required root keys', function () {
    expect(fn () => $this->schemas->assertValid(['title' => 'X']))
        ->toThrow(ValidationException::class);
});

test('rejects duplicate section ids', function () {
    $id = (string) Str::ulid();
    $schema = $this->schemas->normalize([
        'schema_version' => 1,
        'title' => 'T',
        'settings' => [],
        'sections' => [
            ['id' => $id, 'title' => 'A', 'fields' => []],
            ['id' => $id, 'title' => 'B', 'fields' => []],
        ],
    ]);

    // normalize regenerates duplicate ids when invalid format — force same id after normalize
    $schema['sections'][1]['id'] = $schema['sections'][0]['id'];

    expect(fn () => $this->schemas->assertValid($schema))
        ->toThrow(ValidationException::class);
});

test('rejects duplicate field ids', function () {
    $schema = $this->schemas->blank();
    $sectionId = $schema['sections'][0]['id'];
    $schema = $this->schemas->addField($schema, $sectionId, FieldType::Text, ['label' => 'A', 'key' => 'a']);
    $schema = $this->schemas->addField($schema, $sectionId, FieldType::Text, ['label' => 'B', 'key' => 'b']);
    $schema['sections'][0]['fields'][1]['id'] = $schema['sections'][0]['fields'][0]['id'];

    expect(fn () => $this->schemas->assertValid($schema))
        ->toThrow(ValidationException::class);
});

test('rejects duplicate field keys', function () {
    $schema = $this->schemas->blank();
    $sectionId = $schema['sections'][0]['id'];
    $schema = $this->schemas->addField($schema, $sectionId, FieldType::Text, ['label' => 'A', 'key' => 'same']);
    $schema = $this->schemas->addField($schema, $sectionId, FieldType::Text, ['label' => 'B', 'key' => 'other']);
    $schema['sections'][0]['fields'][1]['key'] = 'same';

    expect(fn () => $this->schemas->assertValid($schema))
        ->toThrow(ValidationException::class);
});

test('rejects empty options for dropdown radio and checkbox', function () {
    foreach ([FieldType::Dropdown, FieldType::Radio, FieldType::Checkbox] as $type) {
        $schema = $this->schemas->blank();
        $schema = $this->schemas->addField($schema, $schema['sections'][0]['id'], $type, [
            'label' => 'Choice',
            'key' => 'choice_'.$type->value,
            'options' => [],
        ]);

        expect(fn () => $this->schemas->assertValid($schema))
            ->toThrow(ValidationException::class);
    }
});

test('rejects invalid option lists', function () {
    $schema = $this->schemas->blank();
    $schema = $this->schemas->addField($schema, $schema['sections'][0]['id'], FieldType::Dropdown, [
        'label' => 'Choice',
        'key' => 'choice',
        'options' => [['value' => 'ok', 'label' => 'OK']],
    ]);
    $schema['sections'][0]['fields'][0]['options'] = [
        ['value' => 'ok', 'label' => 'OK'],
        ['value' => '', 'label' => 'Bad'],
    ];

    expect(fn () => $this->schemas->assertValid($schema))
        ->toThrow(ValidationException::class);
});

test('rejects invalid regex definitions', function () {
    $schema = $this->schemas->blank();
    $schema = $this->schemas->addField($schema, $schema['sections'][0]['id'], FieldType::Text, [
        'label' => 'Code',
        'key' => 'code',
        'validation' => ['regex' => '([invalid'],
    ]);

    expect(fn () => $this->schemas->assertValid($schema))
        ->toThrow(ValidationException::class);
});

test('rejects unsupported field types', function () {
    $schema = $this->schemas->blank();
    $schema = $this->schemas->addField($schema, $schema['sections'][0]['id'], FieldType::Text, [
        'label' => 'X',
        'key' => 'x',
    ]);
    $schema['sections'][0]['fields'][0]['type'] = 'magic';

    expect(fn () => $this->schemas->assertValid($schema))
        ->toThrow(ValidationException::class);
});

test('add move duplicate and remove field helpers work', function () {
    $schema = $this->schemas->blank();
    $sectionA = $schema['sections'][0]['id'];
    $schema = $this->schemas->addSection($schema, 'Two');
    $sectionB = $schema['sections'][1]['id'];

    $schema = $this->schemas->addField($schema, $sectionA, FieldType::Text, [
        'label' => 'Name',
        'key' => 'name',
    ]);
    $fieldId = $schema['sections'][0]['fields'][0]['id'];

    $schema = $this->schemas->duplicateField($schema, $fieldId);
    expect($schema['sections'][0]['fields'])->toHaveCount(2)
        ->and($schema['sections'][0]['fields'][1]['key'])->toStartWith('name_copy');

    $schema = $this->schemas->moveField($schema, $fieldId, $sectionB, 0);
    expect($schema['sections'][0]['fields'])->toHaveCount(1)
        ->and($schema['sections'][1]['fields'][0]['id'])->toBe($fieldId);

    $schema = $this->schemas->removeField($schema, $fieldId);
    expect($schema['sections'][1]['fields'])->toHaveCount(0);

    $schema = $this->schemas->removeSection($schema, $sectionB);
    expect($schema['sections'])->toHaveCount(1);
});

test('save bumps schema version and creates form version snapshot', function () {
    $user = User::factory()->create();
    $form = Form::factory()->for($user)->create(['schema_version' => 1]);

    $schema = $this->schemas->blank('Saved Form');
    $schema = $this->schemas->addField($schema, $schema['sections'][0]['id'], FieldType::Email, [
        'label' => 'Email',
        'key' => 'email',
        'required' => true,
    ]);

    $form = $this->schemas->save($form, $schema, $user, 'phase3 test');

    expect($form->schema_version)->toBe(2)
        ->and($form->title)->toBe('Saved Form')
        ->and($form->versions)->toHaveCount(1)
        ->and($form->versions->first()->version)->toBe(2)
        ->and($form->versions->first()->note)->toBe('phase3 test')
        ->and($form->schema['sections'][0]['fields'][0]['type'])->toBe('email');
});
