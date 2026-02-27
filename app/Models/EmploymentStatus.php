<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmploymentStatus extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'color', 'holiday_eligible', 'sort_order'];

    protected $casts = [
        'holiday_eligible' => 'boolean',
    ];

    public function employeeStatusHistories()
    {
        return $this->hasMany(EmployeeStatusHistory::class);
    }

    /**
     * Alias for employeeStatusHistories (used in views).
     */
    public function statusHistories()
    {
        return $this->employeeStatusHistories();
    }
}
