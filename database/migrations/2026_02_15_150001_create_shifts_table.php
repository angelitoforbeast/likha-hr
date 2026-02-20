<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->time('lunch_start');
            $table->time('lunch_end');
            $table->unsignedInteger('required_work_minutes')->default(480);
            $table->unsignedInteger('grace_in_minutes')->default(0);
            $table->unsignedInteger('grace_out_minutes')->default(0);
            $table->unsignedInteger('lunch_inference_window_before_minutes')->default(0);
            $table->unsignedInteger('lunch_inference_window_after_minutes')->default(60);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
