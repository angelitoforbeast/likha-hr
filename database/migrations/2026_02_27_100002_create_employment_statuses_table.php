<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Customizable employment statuses
        Schema::create('employment_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Training, Hybrid, Probationary, Regular, etc.
            $table->string('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Employee status history (with effective_from / effective_until)
        Schema::create('employee_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('employment_status_id')->constrained('employment_statuses')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from']);
        });

        // Seed default statuses
        DB::table('employment_statuses')->insert([
            ['name' => 'Training', 'description' => 'Employee in training period', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Hybrid', 'description' => 'Hybrid work arrangement', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Probationary', 'description' => 'Probationary period', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Regular', 'description' => 'Regular employee', 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_status_history');
        Schema::dropIfExists('employment_statuses');
    }
};
