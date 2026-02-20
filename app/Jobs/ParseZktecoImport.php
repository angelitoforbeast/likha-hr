<?php

namespace App\Jobs;

use App\Models\AttendanceImportRun;
use App\Services\ZktecoParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ParseZktecoImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600; // 10 minutes max

    public function __construct(
        public int $runId
    ) {}

    public function handle(ZktecoParserService $parser): void
    {
        $run = AttendanceImportRun::findOrFail($this->runId);
        $parser->parse($run);
    }
}
