<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSilBalance extends Model
{
    use Auditable;

    protected $fillable = [
        'employee_id',
        'year',
        'total_days',
        'notes',
    ];

    protected $casts = [
        'total_days' => 'decimal:2',
        'year'       => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
