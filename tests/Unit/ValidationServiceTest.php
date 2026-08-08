<?php

use App\Enums\FieldType;
use App\Services\SchemaService;
use App\Services\ValidationService;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->schemas = new SchemaService;
    $this->validation = new ValidationService;
});

test('supportedTypes matches FieldType values', function () {
    expect($this->validation->supportedTypes())->toBe(FieldType::values())
        ->and($this->validation->supportedTypes())->toContain('dropdown', 'rating', 'file', 'heading');
});

test('rulesFromSchema builds rules for email file dropdown and rating', function () {
    $schema = $this->schemas->blank('Rules');
    $sectionId = $schema['sections'][0]['id'];

    $schema = $this->schemas->addField($schema, $sectionId, FieldType::Email, [
        'key' => 'email',
        'label' => 'Email',
        'required' => true,
    ]);
    $schema = $this->schemas->addField($schema, $sectionId, FieldType::Dropdown, [
        'key' => 'role',
        'label' => 'Role',
        'options' => [
            ['value' => 'admin', 'label' => 'Admin'],
            ['value' => 'user', 'label' => 'User'],
        ],
    ]);
    $schema = $this->schemas->addField($schema, $sectionId, FieldType::File, [
        'key' => 'resume',
        'label' => 'Resume',
        'validation' => [
            'file_types' => ['pdf'],
            'max_file_size_kb' => 2048,
        ],
    ]);
    $schema = $this->schemas->addField($schema, $sectionId, FieldType::Rating, [
        'key' => 'score',
        'label' => 'Score',
    ]);
    $schema = $this->schemas->addField($schema, $sectionId, FieldType::Heading, [
        'key' => 'intro',
        'label' => 'Intro',
    ]);

    $this->schemas->assertValid($schema);
    $rules = $this->validation->rulesFromSchema($schema);

    expect($rules)->toHaveKeys(['email', 'role', 'resume', 'score'])
        ->and($rules)->not->toHaveKey('intro')
        ->and($rules['email'])->toContain('required', 'email')
        ->and($rules['role'])->toContain('in:admin,user')
        ->and($rules['resume'])->toContain('file', 'mimes:pdf', 'max:2048')
        ->and($rules['score'])->toContain('integer', 'min:1', 'max:5');
});

test('attributes and messages are derived from labels', function () {
    $schema = $this->schemas->blank();
    $schema = $this->schemas->addField($schema, $schema['sections'][0]['id'], FieldType::Text, [
        'key' => 'full_name',
        'label' => 'Full Name',
        'required' => true,
    ]);

    expect($this->validation->attributesFromSchema($schema)['full_name'])->toBe('Full Name')
        ->and($this->validation->messagesFromSchema($schema)['full_name.required'])->toBe('Full Name is required.');
});
