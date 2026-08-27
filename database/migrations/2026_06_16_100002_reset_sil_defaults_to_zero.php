<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Reset any legacy auto-created balances that used the old 5-day default to 0.
        // Employees only get SIL balance once the CEO explicitly sets it per year.
        // Only touches rows still at the initial value; user-adjusted balances stay put.
        DB::table('employee_sil_balances')->where('total_days', 5.00)->update(['total_days' => 0.00]);
    }

    public function down(): void
    {
        // Intentionally no-op — bringing back 5.00 would overwrite intentional zeros.
    }
};
