<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SilApplication extends Model
{
    use Auditable;

    protected $fillable = [
        'employee_id',
        'sil_date',
        'reason',
        'applied_by',
    ];

    protected $casts = [
        'sil_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
