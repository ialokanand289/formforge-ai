<?php

namespace Database\Factories;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    protected $model = Form::class;

    public function definition(): array
    {
        $title = 'Demo Form';

        return [
            'user_id' => User::factory(),
            'title' => $title,
            'description' => '',
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'public_token' => (string) Str::uuid(),
            'status' => FormStatus::Draft,
            'schema' => [
                'title' => $title,
                'description' => '',
                'sections' => [],
            ],
            'schema_version' => 1,
            'published_at' => null,
        ];
    }
}
