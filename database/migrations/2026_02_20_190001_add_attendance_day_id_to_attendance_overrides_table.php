<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_overrides', function (Blueprint $table) {
            $table->foreignId('attendance_day_id')->nullable()->after('id')
                  ->constrained('attendance_days')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_overrides', function (Blueprint $table) {
            $table->dropForeign(['attendance_day_id']);
            $table->dropColumn('attendance_day_id');
        });
    }
};
