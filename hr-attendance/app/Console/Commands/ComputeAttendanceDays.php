<?php

namespace App\Console\Commands;

use App\Services\AttendanceComputeService;
use Illuminate\Console\Command;

class ComputeAttendanceDays extends Command
{
    protected $signature = 'attendance:compute
                            {start_date : Start date (Y-m-d)}
                            {end_date : End date (Y-m-d)}
                            {--run-id= : Optional source import run ID}';

    protected $description = 'Compute daily attendance summaries from attendance logs';

    public function handle(AttendanceComputeService $service): int
    {
        $startDate = $this->argument('start_date');
        $endDate = $this->argument('end_date');
        $runId = $this->option('run-id') ? (int) $this->option('run-id') : null;

        $this->info("Computing attendance days from {$startDate} to {$endDate}...");

        $stats = $service->computeForDateRange($startDate, $endDate, $runId);

        $this->info("Done! Processed: {$stats['processed']}, Errors: {$stats['errors']}");

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
