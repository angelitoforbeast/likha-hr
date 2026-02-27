<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'employee_status_history';

    protected $fillable = [
        'employee_id',
        'employment_status_id',
        'effective_from',
        'effective_until',
        'remarks',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function employmentStatus()
    {
        return $this->belongsTo(EmploymentStatus::class);
    }
}
