<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->date('effective_date');
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'effective_date'], 'dept_shift_effective_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_shift_assignments');
    }
};
