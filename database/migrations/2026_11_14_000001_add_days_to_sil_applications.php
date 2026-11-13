<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 015: a SIL can be half a day. `days` is 1.0 (whole) or 0.5 (half); every existing
     * row is a whole day. One row per employee and date stays; used days = SUM(days).
     */
    public function up(): void
    {
        Schema::table('sil_applications', function (Blueprint $table) {
            $table->decimal('days', 3, 1)->default(1.0)->after('sil_date');
        });
    }

    public function down(): void
    {
        Schema::table('sil_applications', function (Blueprint $table) {
            $table->dropColumn('days');
        });
    }
};
