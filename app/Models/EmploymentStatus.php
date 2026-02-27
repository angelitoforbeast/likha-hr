<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmploymentStatus extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'sort_order'];

    public function employeeStatusHistories()
    {
        return $this->hasMany(EmployeeStatusHistory::class);
    }
}
