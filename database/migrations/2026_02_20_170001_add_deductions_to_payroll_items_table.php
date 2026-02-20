<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->integer('total_early_minutes')->default(0)->after('total_late_minutes');
            $table->decimal('late_deduction', 12, 2)->default(0)->after('base_pay');
            $table->decimal('early_deduction', 12, 2)->default(0)->after('late_deduction');
            $table->decimal('ot_pay', 12, 2)->default(0)->after('early_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn(['total_early_minutes', 'late_deduction', 'early_deduction', 'ot_pay']);
        });
    }
};
