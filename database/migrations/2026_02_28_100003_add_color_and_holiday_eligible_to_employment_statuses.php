<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_statuses', function (Blueprint $table) {
            $table->string('color', 7)->default('#6c757d')->after('description');
            $table->boolean('holiday_eligible')->default(true)->after('color');
        });

        // Set default colors for existing statuses
        DB::table('employment_statuses')->where('name', 'Training')->update(['color' => '#ffc107', 'holiday_eligible' => false]);
        DB::table('employment_statuses')->where('name', 'Hybrid')->update(['color' => '#17a2b8', 'holiday_eligible' => true]);
        DB::table('employment_statuses')->where('name', 'Probationary')->update(['color' => '#fd7e14', 'holiday_eligible' => true]);
        DB::table('employment_statuses')->where('name', 'Regular')->update(['color' => '#28a745', 'holiday_eligible' => true]);
    }

    public function down(): void
    {
        Schema::table('employment_statuses', function (Blueprint $table) {
            $table->dropColumn(['color', 'holiday_eligible']);
        });
    }
};
