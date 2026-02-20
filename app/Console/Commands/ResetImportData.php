<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\AttendanceImportRun;
use App\Models\AttendanceLog;
use App\Models\AttendanceOverride;
use App\Models\Employee;
use Illuminate\Console\Command;

class ResetImportData extends Command
{
    protected $signature = 'import:reset {--force : Skip confirmation}';

    protected $description = 'Clear all imported data (employees, attendance logs, attendance days, overrides, import runs) for a fresh re-import';

    public function handle(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will DELETE all employees, attendance logs, attendance days, overrides, and import runs. Continue?')) {
                $this->info('Cancelled.');
                return 0;
            }
        }

        $this->info('Clearing data...');

        // Order matters due to foreign keys
        AttendanceOverride::truncate();
        AttendanceDay::truncate();
        AttendanceLog::truncate();
        AttendanceImportRun::query()->delete();
        Employee::query()->delete();

        $this->info('All import data has been cleared. You can now re-import your files.');

        return 0;
    }
}
