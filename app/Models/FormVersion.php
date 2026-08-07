<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormVersion extends Model
{
    use HasFactory;
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'form_id',
        'version',
        'schema',
        'note',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
