<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_date_locks', function (Blueprint $table) {
            $table->id();
            $table->date('lock_date')->unique(); // one lock per date; range-locks insert many rows
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('source', 32)->default('manual'); // manual | range | payroll_final
            $table->timestamp('locked_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_date_locks');
    }
};
