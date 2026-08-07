<?php

use App\Models\Form;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('database seeder creates demo user form and version relations', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::query()->where('email', 'demo@formforge.test')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeTrue();

    $form = $user->forms()->first();

    expect($form)->not->toBeNull()
        ->and($form->title)->toBe('Demo Form')
        ->and($form->schema)->toMatchArray([
            'title' => 'Demo Form',
            'description' => '',
            'sections' => [],
        ])
        ->and($form->versions)->toHaveCount(1)
        ->and($form->versions->first()->version)->toBe(1)
        ->and($form->versions->first()->schema)->toMatchArray($form->schema);
});

test('form uses ulid primary key and soft deletes', function () {
    $form = Form::factory()->create();

    expect(strlen($form->id))->toBe(26);

    $form->delete();

    expect(Form::withTrashed()->find($form->id))->not->toBeNull()
        ->and(Form::find($form->id))->toBeNull();
});
