<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('daily_rate', 10, 2);
            $table->date('effective_date');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'effective_date'], 'emp_rate_eff_unique');
            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_rates');
    }
};
