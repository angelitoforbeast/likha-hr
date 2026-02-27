<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Default weekly rest day pattern per employee
        Schema::create('rest_day_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->tinyInteger('day_of_week'); // 0=Sunday, 1=Monday, ..., 6=Saturday
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from']);
        });

        // Day off overrides (add extra day off or cancel a pattern day off)
        Schema::create('day_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('off_date');
            $table->enum('type', ['day_off', 'cancel_day_off']); // day_off = extra off, cancel_day_off = required to work on pattern day
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'off_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_offs');
        Schema::dropIfExists('rest_day_patterns');
    }
};
