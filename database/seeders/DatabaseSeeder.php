<?php

namespace Database\Seeders;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::query()->create([
            'name' => 'Demo User',
            'email' => 'demo@formforge.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $schema = [
            'title' => 'Demo Form',
            'description' => '',
            'sections' => [],
        ];

        $form = Form::query()->create([
            'user_id' => $user->id,
            'title' => 'Demo Form',
            'description' => '',
            'slug' => 'demo-form',
            'public_token' => (string) Str::uuid(),
            'status' => FormStatus::Draft,
            'schema' => $schema,
            'schema_version' => 1,
            'published_at' => null,
        ]);

        FormVersion::query()->create([
            'form_id' => $form->id,
            'version' => 1,
            'schema' => $schema,
            'note' => 'Initial version',
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
    }
}
