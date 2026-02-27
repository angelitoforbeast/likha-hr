<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BenefitType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'category', 'unit', 'description', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employeeBenefits()
    {
        return $this->hasMany(EmployeeBenefit::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEarnings($query)
    {
        return $query->where('category', 'earning');
    }

    public function scopeDeductions($query)
    {
        return $query->where('category', 'deduction');
    }
}
