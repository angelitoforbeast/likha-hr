<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'punched_at',
        'source_run_id',
    ];

    protected $casts = [
        'punched_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(AttendanceImportRun::class, 'source_run_id');
    }
}
