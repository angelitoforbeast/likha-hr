<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashAdvance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'amount',
        'remaining_balance',
        'deduction_per_cutoff',
        'date_granted',
        'effective_from',
        'effective_until',
        'status',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
        'deduction_per_cutoff' => 'decimal:2',
        'date_granted' => 'date',
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
