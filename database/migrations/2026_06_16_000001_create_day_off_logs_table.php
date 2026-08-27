<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_off_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('off_date');
            $table->string('action'); // add_day_off, cancel_day_off, remove_override
            $table->string('old_type')->nullable(); // day_off, cancel_day_off, or null when there was no prior override
            $table->string('new_type')->nullable(); // day_off, cancel_day_off, or null when removed
            $table->text('reason');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'off_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_off_logs');
    }
};
