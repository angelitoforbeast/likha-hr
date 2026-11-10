<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the office biometrics feed (spec 012): agent state, held and refused punches,
 * device users not in Likha HR and the daily clock drift. See the migration for the kinds.
 */
class BiometricFeed extends Model
{
    protected $table = 'biometric_feed';

    protected $fillable = ['kind', 'key', 'value'];

    public const STATE = 'state';
    public const HELD = 'held';
    public const REFUSED = 'refused';
    public const DEVICE_USER = 'device_user';
    public const DRIFT = 'drift';
}
