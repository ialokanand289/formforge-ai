<?php

use App\Enums\FieldType;
use App\Rules\ValidFormSchema;
use App\Services\SchemaService;
use Illuminate\Support\Facades\Validator;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->schemas = new SchemaService;
});

test('ValidFormSchema accepts a valid schema array', function () {
    $schema = $this->schemas->blank('Valid');
    $schema = $this->schemas->addField($schema, $schema['sections'][0]['id'], FieldType::Text, [
        'key' => 'name',
        'label' => 'Name',
    ]);

    $validator = Validator::make(
        ['schema' => $schema],
        ['schema' => [new ValidFormSchema($this->schemas)]]
    );

    expect($validator->passes())->toBeTrue();
});

test('ValidFormSchema accepts valid json string', function () {
    $schema = $this->schemas->blank('JSON');

    $validator = Validator::make(
        ['schema' => json_encode($schema)],
        ['schema' => [new ValidFormSchema($this->schemas)]]
    );

    expect($validator->passes())->toBeTrue();
});

test('ValidFormSchema rejects missing roots and empty choice options', function () {
    $missing = Validator::make(
        ['schema' => ['title' => 'Only title']],
        ['schema' => [new ValidFormSchema($this->schemas)]]
    );
    expect($missing->fails())->toBeTrue();

    $schema = $this->schemas->blank();
    $schema = $this->schemas->addField($schema, $schema['sections'][0]['id'], FieldType::Dropdown, [
        'key' => 'choice',
        'label' => 'Choice',
        'options' => [],
    ]);

    $emptyOptions = Validator::make(
        ['schema' => $schema],
        ['schema' => [new ValidFormSchema($this->schemas)]]
    );
    expect($emptyOptions->fails())->toBeTrue();
});
