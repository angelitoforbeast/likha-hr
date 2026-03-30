<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manus_edit_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('datetime');
            $table->string('action'); // ADD, EDIT, DELETE
            $table->string('file'); // file path relative to project root
            $table->text('what_changed');
            $table->text('purpose');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manus_edit_logs');
    }
};
