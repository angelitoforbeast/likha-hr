<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-employee, per-year SIL entitlement. Default is DOLE's 5 days; CEO can adjust.
        Schema::create('employee_sil_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->decimal('total_days', 5, 2)->default(5.00);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'year']);
        });

        // Each row = one SIL day applied. Used vs remaining derived from count.
        Schema::create('sil_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('sil_date');
            $table->text('reason');
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'sil_date']);
        });

        // Per-employee SIL eligibility flag (default: false — must be enabled by CEO)
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('sil_eligible')->default(false)->after('night_differential_eligible');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('sil_eligible');
        });
        Schema::dropIfExists('sil_applications');
        Schema::dropIfExists('employee_sil_balances');
    }
};
