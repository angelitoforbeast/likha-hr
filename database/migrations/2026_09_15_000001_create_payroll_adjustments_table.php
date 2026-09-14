<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master adjustment table — one row per (employee, cutoff) pair.
     *
     * This lives OUTSIDE payroll_runs so adjustments survive:
     *   - Deleting and recreating a payroll run for the same cutoff
     *   - Recomputing a payroll run (via the new Recompute button)
     *
     * The payroll_items.adjustments column continues to exist as a per-run
     * SNAPSHOT populated at compute/recompute/save-adjustment time. That way
     * finalized runs stay historical and can never silently change if the
     * master value is later edited.
     */
    public function up(): void
    {
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('cutoff_start');
            $table->date('cutoff_end');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('notes', 1000)->nullable();
            $table->timestamps();

            // One row per (employee, cutoff). Save-adjustment will upsert into it.
            $table->unique(['employee_id', 'cutoff_start', 'cutoff_end'], 'unique_emp_cutoff_adj');
            $table->index(['cutoff_start', 'cutoff_end'], 'idx_cutoff_adj');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
    }
};