<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Spec 015: a half-day SIL on a required day leaves half a day absent, so a payroll
     * item can hold 14.5 absent days. Existing whole numbers keep their value.
     *
     * Without doctrine/dbal, Laravel 10 cannot ->change() a column, so this is plain SQL
     * (as in 2026_11_10_000002). SQLite needs nothing: an INTEGER column there keeps a
     * value like 14.5 as it is (type affinity), which is what the tests run on.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE payroll_items MODIFY absent_days DECIMAL(4,1) NOT NULL DEFAULT 0');
        }
    }

    /** Back to whole days. MySQL rounds a stored half on the way (14.5 becomes 15). */
    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE payroll_items MODIFY absent_days INT NOT NULL DEFAULT 0');
        }
    }
};
