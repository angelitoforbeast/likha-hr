<?php

namespace App\Jobs;

use App\Models\AttendanceImportRun;
use App\Services\AttendanceIngestLock;
use App\Services\BiometricFeedService;
use App\Services\ZktecoParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ParseZktecoImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600; // 10 minutes max

    /** How long an upload waits for an agent post or another import to finish. */
    private const LOCK_WAIT_SECONDS = 300;

    public function __construct(
        public int $runId
    ) {}

    public function handle(ZktecoParserService $parser, BiometricFeedService $feed): void
    {
        $run = AttendanceImportRun::findOrFail($this->runId);

        // One import at a time with the office biometrics agent (spec 012), so the duplicate
        // check never misses a punch stored by the other. Held punches whose employee exists
        // now (one this file's user list just created, say) are applied after the file.
        try {
            AttendanceIngestLock::run(function () use ($parser, $feed, $run) {
                $parser->parse($run);
                $feed->applyHeldPunches($run->uploaded_by);
            }, self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            $stats = $run->statsArray();
            $stats['error'] = 'Another import kept running; upload the file again.';
            $run->update(['status' => 'failed', 'stats_json' => $stats]);
        }
    }
}
