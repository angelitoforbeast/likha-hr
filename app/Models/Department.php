<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['name', 'description'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(DepartmentShiftAssignment::class)->orderByDesc('effective_date');
    }

    /**
     * Get the current active shift for this department.
     */
    public function getCurrentShift(): ?Shift
    {
        return DepartmentShiftAssignment::getActiveShift($this->id, now()->toDateString());
    }

    /**
     * Get the current active shift assignment record for this department.
     */
    public function getCurrentShiftAssignment(): ?DepartmentShiftAssignment
    {
        return DepartmentShiftAssignment::getCurrentShift($this->id);
    }
}
