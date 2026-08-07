<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGenerationLog extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'user_id',
        'form_id',
        'type',
        'prompt',
        'status',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'latency_ms',
        'attempts',
        'response_raw',
        'schema_result',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'type' => GenerationType::class,
            'status' => GenerationStatus::class,
            'schema_result' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
