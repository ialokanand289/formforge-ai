<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\AiGenerationLog;
use App\Models\Form;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGenerationLog>
 */
class AiGenerationLogFactory extends Factory
{
    protected $model = AiGenerationLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'form_id' => Form::factory(),
            'type' => GenerationType::Generate,
            'prompt' => 'Create a contact form with a name and an email.',
            'status' => GenerationStatus::Queued,
            'model' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'latency_ms' => null,
            'attempts' => 0,
            'response_raw' => null,
            'schema_result' => null,
            'error_message' => null,
        ];
    }

    public function edit(): static
    {
        return $this->state(fn () => ['type' => GenerationType::Edit]);
    }

    public function status(GenerationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
