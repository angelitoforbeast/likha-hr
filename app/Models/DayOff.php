<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DayOff extends Model
{
    use HasFactory;

    protected $table = 'day_offs';

    protected $fillable = [
        'employee_id',
        'off_date',
        'type',
        'remarks',
    ];

    protected $casts = [
        'off_date' => 'date',
    ];

    const TYPE_DAY_OFF = 'day_off';
    const TYPE_CANCEL_DAY_OFF = 'cancel_day_off';

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
