<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->dateTime('time_in')->nullable();
            $table->dateTime('lunch_out')->nullable();
            $table->dateTime('lunch_in')->nullable();
            $table->dateTime('time_out')->nullable();
            $table->integer('computed_work_minutes')->default(0);
            $table->integer('computed_late_minutes')->default(0);
            $table->integer('computed_early_minutes')->default(0);
            $table->integer('computed_overtime_minutes')->default(0);
            $table->integer('payable_work_minutes')->default(0);
            $table->boolean('needs_review')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('source_run_id')->nullable()->constrained('attendance_import_runs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_days');
    }
};
