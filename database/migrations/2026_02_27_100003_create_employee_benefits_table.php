<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Benefit/deduction types (customizable)
        Schema::create('benefit_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // SSS Premium, PhilHealth Premium, Pag-ibig Premium, Rice Allowance, etc.
            $table->enum('category', ['earning', 'deduction']); // earning = added to pay, deduction = subtracted
            $table->string('unit')->default('fixed'); // fixed, per_day, per_cutoff
            $table->string('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Employee benefits (per employee, with effective dates)
        Schema::create('employee_benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('benefit_type_id')->constrained('benefit_types')->cascadeOnDelete();
            $table->boolean('is_eligible')->default(true);
            $table->decimal('amount', 10, 2)->default(0);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'benefit_type_id', 'effective_from'], 'emp_ben_eff_idx');
        });

        // Seed default benefit types
        DB::table('benefit_types')->insert([
            // Deductions
            ['name' => 'SSS Premium', 'category' => 'deduction', 'unit' => 'fixed', 'description' => 'Social Security System contribution', 'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'PhilHealth Premium', 'category' => 'deduction', 'unit' => 'fixed', 'description' => 'Philippine Health Insurance contribution', 'sort_order' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Pag-ibig Premium', 'category' => 'deduction', 'unit' => 'fixed', 'description' => 'Pag-IBIG Fund contribution', 'sort_order' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'SSS Loan', 'category' => 'deduction', 'unit' => 'fixed', 'description' => 'SSS Loan deduction per cutoff', 'sort_order' => 4, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Pag-ibig Loan', 'category' => 'deduction', 'unit' => 'fixed', 'description' => 'Pag-IBIG Loan deduction per cutoff', 'sort_order' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Withholding Tax', 'category' => 'deduction', 'unit' => 'fixed', 'description' => 'Withholding tax deduction', 'sort_order' => 6, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            // Earnings
            ['name' => 'Rice Allowance', 'category' => 'earning', 'unit' => 'per_day', 'description' => 'Daily rice allowance', 'sort_order' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_benefits');
        Schema::dropIfExists('benefit_types');
    }
};
