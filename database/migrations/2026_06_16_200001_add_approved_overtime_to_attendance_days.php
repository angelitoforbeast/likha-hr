<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            // CEO-approved OT minutes. When set, takes precedence over the auto-computed value.
            // When null, payroll falls back to computed_overtime_minutes.
            $table->integer('approved_overtime_minutes')->nullable()->after('computed_overtime_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn('approved_overtime_minutes');
        });
    }
};
