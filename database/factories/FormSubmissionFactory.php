<?php

namespace Database\Factories;

use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormSubmission>
 */
class FormSubmissionFactory extends Factory
{
    protected $model = FormSubmission::class;

    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'form_version' => 1,
            'payload' => [],
            'search_text' => null,
            'status' => SubmissionStatus::Completed,
            'ip_hash' => hash('sha256', 'factory'),
            'user_agent' => 'Factory',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(array $payload): static
    {
        return $this->state(fn () => ['payload' => $payload]);
    }

    public function version(int $version): static
    {
        return $this->state(fn () => ['form_version' => $version]);
    }
}
