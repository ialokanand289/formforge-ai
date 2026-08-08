<?php

namespace App\Models;

use App\Enums\FormStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Form extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'slug',
        'public_token',
        'status',
        'schema',
        'schema_version',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'status' => FormStatus::class,
            'schema_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Forms reachable from the public renderer.
     *
     * Gated on status alone: published_at stays informational until the
     * publish workflow exists.
     *
     * @param  Builder<Form>  $query
     * @return Builder<Form>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', FormStatus::Published);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function aiGenerationLogs(): HasMany
    {
        return $this->hasMany(AiGenerationLog::class);
    }

    public function importJobs(): HasMany
    {
        return $this->hasMany(ImportJob::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
