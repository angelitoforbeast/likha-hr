<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A request for a shift assignment of the employee profile covers a range (from, until or
     * ongoing, the assignment it edits or removes), which no other column can hold. Nullable and
     * read only for that kind of request: every existing row stays as it is.
     */
    public function up(): void
    {
        Schema::table('attendance_change_requests', function (Blueprint $table) {
            $table->json('details')->nullable()->after('day_state');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_change_requests', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
