<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormSubmission extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'form_id',
        'form_version',
        'payload',
        'search_text',
        'status',
        'ip_hash',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => SubmissionStatus::class,
            'form_version' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class);
    }
}
