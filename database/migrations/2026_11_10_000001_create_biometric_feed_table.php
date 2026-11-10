<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything the office biometrics feed keeps, as rows (spec 012, decision 7):
     *   state       key = a fact of the agent or device (last_contact, device_reachable, ...), value = its latest value
     *   held        key = zkteco id with no employee yet, value = punch time "Y-m-d H:i:s"
     *   refused     key = zkteco id, value = a punch time outside 2020-01-01 .. now + 1 day
     *   device_user key = user id on the device that is not in Likha HR, value = the name on the device
     *   drift       key = Manila date of the daily clock check, value = "<seconds> set|not set"
     * The unique index makes a punch held or refused only once, however often it is posted.
     */
    public function up(): void
    {
        Schema::create('biometric_feed', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->string('key', 64);
            $table->string('value', 191);
            $table->timestamps();

            $table->unique(['kind', 'key', 'value'], 'biometric_feed_kind_key_value_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_feed');
    }
};
