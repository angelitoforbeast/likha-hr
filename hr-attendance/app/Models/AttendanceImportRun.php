<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceImportRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'uploaded_by',
        'status',
        'stats_json',
    ];

    protected $casts = [
        'stats_json' => 'array',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'source_run_id');
    }

    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class, 'source_run_id');
    }
}
