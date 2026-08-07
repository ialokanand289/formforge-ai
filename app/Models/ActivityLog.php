<?php

namespace App\Models;

use App\Enums\ActivityAction;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'form_id',
        'action',
        'subject_type',
        'subject_id',
        'properties',
        'ip_hash',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => ActivityAction::class,
            'properties' => 'array',
            'created_at' => 'datetime',
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
