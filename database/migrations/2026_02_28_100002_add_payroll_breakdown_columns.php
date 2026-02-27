<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            // Required mandays and absence tracking
            $table->integer('required_mandays')->default(0)->after('total_days_decimal');
            $table->integer('days_worked')->default(0)->after('required_mandays');
            $table->integer('absent_days')->default(0)->after('days_worked');
            $table->decimal('daily_rate', 12, 2)->default(0)->after('absent_days');

            // Absence deduction
            $table->decimal('absence_deduction', 12, 2)->default(0)->after('early_deduction');

            // Benefits & deductions breakdown (JSON for flexibility)
            $table->json('earnings_breakdown')->nullable()->after('ot_pay');
            $table->json('deductions_breakdown')->nullable()->after('earnings_breakdown');
            $table->decimal('total_earnings', 12, 2)->default(0)->after('deductions_breakdown');
            $table->decimal('total_deductions', 12, 2)->default(0)->after('total_earnings');

            // Gross pay (basic pay before benefit deductions)
            $table->decimal('gross_pay', 12, 2)->default(0)->after('total_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn([
                'required_mandays', 'days_worked', 'absent_days', 'daily_rate',
                'absence_deduction',
                'earnings_breakdown', 'deductions_breakdown',
                'total_earnings', 'total_deductions', 'gross_pay',
            ]);
        });
    }
};
