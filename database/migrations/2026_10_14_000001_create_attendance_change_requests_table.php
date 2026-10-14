<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendance changes asked by HR staff and admins, waiting for the CEO.
     * One row per employee, date and field. Nothing here is read by payroll or
     * by the attendance compute: a value becomes official only when an approval
     * writes it to the attendance tables.
     */
    public function up(): void
    {
        Schema::create('attendance_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('field', 40);
            $table->string('new_value', 40)->nullable();
            $table->string('old_value', 40)->nullable();
            $table->json('day_state')->nullable();
            $table->text('reason');
            $table->string('origin', 40);
            $table->char('batch_id', 36)->nullable()->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            // 1 while pending, NULL once decided or withdrawn. A unique index treats
            // NULLs as distinct, so the index below allows any number of decided rows
            // and at most one pending row per employee, date and field.
            $table->unsignedTinyInteger('pending_marker')->nullable()->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('changed_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->string('applied_type', 40)->nullable();
            $table->unsignedBigInteger('applied_id')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date', 'field', 'pending_marker'], 'acr_one_pending_unique');
            $table->index(['status', 'work_date'], 'acr_status_date_index');
            $table->index(['requested_by', 'status'], 'acr_requester_status_index');
            $table->index(['applied_type', 'applied_id'], 'acr_applied_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_change_requests');
    }
};
