<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutoff_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['fixed_day_ranges', 'manual'])->default('fixed_day_ranges');
            $table->json('rule_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cutoff_rules');
    }
};
