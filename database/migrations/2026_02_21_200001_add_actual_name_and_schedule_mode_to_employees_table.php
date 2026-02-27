<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('actual_name')->nullable()->after('full_name');
            $table->enum('schedule_mode', ['department', 'manual'])->default('department')->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['actual_name', 'schedule_mode']);
        });
    }
};
