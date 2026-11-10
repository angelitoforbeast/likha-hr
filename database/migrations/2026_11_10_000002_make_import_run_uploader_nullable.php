<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A run fed by the office biometrics agent has no uploader (spec 012). The column keeps its
     * foreign key; only NULL becomes allowed. Without doctrine/dbal, Laravel 10 cannot ->change()
     * a column, so this is plain SQL per driver.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE attendance_import_runs MODIFY uploaded_by BIGINT UNSIGNED NULL');
            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildSqlite(true);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Runs without an uploader cannot stay: the column becomes NOT NULL again.
            DB::statement('ALTER TABLE attendance_import_runs MODIFY uploaded_by BIGINT UNSIGNED NOT NULL');
            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildSqlite(false);
        }
    }

    /**
     * SQLite cannot alter a column: copy the table into a new one with the same columns and keys.
     * Foreign keys are switched off while the old table is dropped, so its referencing rows stay.
     */
    private function rebuildSqlite(bool $nullable): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::statement('CREATE TABLE "attendance_import_runs_new" (
                "id" integer primary key autoincrement not null,
                "uploaded_by" integer ' . ($nullable ? 'null' : 'not null') . ',
                "status" varchar check ("status" in (\'queued\', \'processing\', \'done\', \'failed\')) not null default \'queued\',
                "stats_json" text,
                "created_at" datetime,
                "updated_at" datetime,
                foreign key("uploaded_by") references "users"("id") on delete cascade
            )');
            DB::statement('INSERT INTO "attendance_import_runs_new" ("id", "uploaded_by", "status", "stats_json", "created_at", "updated_at")
                SELECT "id", "uploaded_by", "status", "stats_json", "created_at", "updated_at" FROM "attendance_import_runs"');
            DB::statement('DROP TABLE "attendance_import_runs"');
            DB::statement('ALTER TABLE "attendance_import_runs_new" RENAME TO "attendance_import_runs"');
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
