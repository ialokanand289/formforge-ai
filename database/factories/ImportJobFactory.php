<?php

namespace Database\Factories;

use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Models\Form;
use App\Models\ImportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImportJob>
 */
class ImportJobFactory extends Factory
{
    protected $model = ImportJob::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'form_id' => Form::factory(),
            'source' => ImportSource::Docx,
            'original_filename' => 'employee-registration.docx',
            'disk_path' => 'imports/'.Str::ulid().'.docx',
            'status' => ImportStatus::Queued,
            'preview' => null,
            'errors' => null,
            'final_schema' => null,
        ];
    }

    public function source(ImportSource $source): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => $source,
            'original_filename' => 'employee-registration.'.$source->value,
            'disk_path' => 'imports/'.Str::ulid().'.'.$source->value,
        ]);
    }

    public function status(ImportStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
