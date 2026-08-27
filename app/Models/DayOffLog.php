<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DayOffLog extends Model
{
    protected $fillable = [
        'employee_id',
        'off_date',
        'action',
        'old_type',
        'new_type',
        'reason',
        'updated_by',
    ];

    protected $casts = [
        'off_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
