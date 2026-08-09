<?php

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\FormSubmission;
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
        ->and($form->schema['title'])->toBe('Demo Form')
        ->and($form->versions)->toHaveCount(1)
        ->and($form->versions->first()->version)->toBe(1)
        ->and($form->versions->first()->schema)->toMatchArray($form->schema);
});

test('database seeder publishes the demo form and gives it submissions to browse', function () {
    $this->seed(DatabaseSeeder::class);

    $form = Form::query()->where('slug', 'demo-form')->firstOrFail();

    expect($form->status)->toBe(FormStatus::Published)
        ->and($form->published_at)->not->toBeNull()
        ->and($form->public_token)->not->toBeEmpty();

    $types = collect($form->schema['sections'])
        ->flatMap(fn (array $section): array => $section['fields'])
        ->pluck('type')
        ->unique();

    expect($types->count())->toBeGreaterThanOrEqual(8);

    $submissions = FormSubmission::query()->where('form_id', $form->id)->get();

    expect($submissions)->toHaveCount(6)
        ->and($submissions->every(fn ($submission): bool => $submission->form_version === 1))->toBeTrue()
        // Search on the owner's submission list runs against this column.
        ->and($submissions->pluck('search_text')->filter()->count())->toBe(6);
});

test('form uses ulid primary key and soft deletes', function () {
    $form = Form::factory()->create();

    expect(strlen($form->id))->toBe(26);

    $form->delete();

    expect(Form::withTrashed()->find($form->id))->not->toBeNull()
        ->and(Form::find($form->id))->toBeNull();
});
