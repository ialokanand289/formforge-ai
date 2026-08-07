<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormVersion>
 */
class FormVersionFactory extends Factory
{
    protected $model = FormVersion::class;

    public function definition(): array
    {
        $schema = [
            'title' => 'Demo Form',
            'description' => '',
            'sections' => [],
        ];

        return [
            'form_id' => Form::factory(),
            'version' => 1,
            'schema' => $schema,
            'note' => 'Initial version',
            'created_by' => User::factory(),
            'created_at' => now(),
        ];
    }
}
