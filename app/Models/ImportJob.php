<?php

namespace App\Models;

use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJob extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'user_id',
        'form_id',
        'source',
        'original_filename',
        'disk_path',
        'status',
        'preview',
        'errors',
        'final_schema',
    ];

    protected function casts(): array
    {
        return [
            'source' => ImportSource::class,
            'status' => ImportStatus::class,
            'preview' => 'array',
            'errors' => 'array',
            'final_schema' => 'array',
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
