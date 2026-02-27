<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cash advances
        Schema::create('cash_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->decimal('remaining_balance', 10, 2); // tracks how much is left to deduct
            $table->decimal('deduction_per_cutoff', 10, 2)->default(0); // how much to deduct per payroll
            $table->date('date_granted');
            $table->date('effective_from'); // start deducting from this cutoff
            $table->date('effective_until')->nullable();
            $table->enum('status', ['active', 'fully_paid', 'cancelled'])->default('active');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        // Audit logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // created, updated, deleted
            $table->string('auditable_type'); // model class name
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('cash_advances');
    }
};
