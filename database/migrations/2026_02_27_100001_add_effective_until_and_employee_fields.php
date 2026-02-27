<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add effective_until to employee_shift_assignments
        Schema::table('employee_shift_assignments', function (Blueprint $table) {
            $table->date('effective_until')->nullable()->after('effective_date');
        });

        // Add effective_until to employee_rates
        Schema::table('employee_rates', function (Blueprint $table) {
            $table->date('effective_until')->nullable()->after('effective_date');
        });

        // Add effective_until to department_shift_assignments
        Schema::table('department_shift_assignments', function (Blueprint $table) {
            $table->date('effective_until')->nullable()->after('effective_date');
        });

        // Add night_differential_eligible to employees
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('night_differential_eligible')->default(false)->after('schedule_mode');
        });
    }

    public function down(): void
    {
        Schema::table('employee_shift_assignments', function (Blueprint $table) {
            $table->dropColumn('effective_until');
        });
        Schema::table('employee_rates', function (Blueprint $table) {
            $table->dropColumn('effective_until');
        });
        Schema::table('department_shift_assignments', function (Blueprint $table) {
            $table->dropColumn('effective_until');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('night_differential_eligible');
        });
    }
};
