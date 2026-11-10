<?php

namespace App\Services;

use App\Models\BiometricFeed;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The health of the office biometrics feed, for the Import page and the status endpoint
 * (spec 012, decisions 8, 10 and 11). Read-only.
 */
class BiometricFeedHealth
{
    public function __construct(protected BiometricFeedService $feed)
    {
    }

    /**
     * @param bool $withLists the page also gets the held punches per ID and the device users
     *                        not in HR; the status endpoint never does (no names, no punches)
     */
    public function snapshot(bool $withLists = false): array
    {
        $state = BiometricFeed::where('kind', BiometricFeed::STATE)
            ->orderBy('updated_at')->orderBy('id')->pluck('value', 'key')->all();

        $lastContact = isset($state['last_contact']) ? Carbon::parse($state['last_contact'], 'UTC') : null;
        $clockCheckedAt = isset($state['clock_checked_at']) ? Carbon::parse($state['clock_checked_at'], 'UTC') : null;
        $int = fn (string $key) => isset($state[$key]) && is_numeric($state[$key]) ? (int) $state[$key] : null;
        $bool = fn (string $key) => isset($state[$key]) ? $state[$key] === '1' : null;

        $snapshot = [
            'last_contact'          => $lastContact,
            'agent_id'              => $state['agent_id'] ?? null,
            'agent_version'         => $state['agent_version'] ?? null,
            'device_reachable'      => $bool('device_reachable'),
            'device_error'          => $state['device_error'] ?? null,
            'device_record_count'   => $int('device_record_count'),
            'device_user_count'     => $int('device_user_count'),
            'device_capacity'       => (int) config('biometrics.device_capacity', 50000),
            'held_count'            => BiometricFeed::where('kind', BiometricFeed::HELD)->count(),
            'held_ids'              => BiometricFeed::where('kind', BiometricFeed::HELD)->distinct()->count('key'),
            'refused_count'         => BiometricFeed::where('kind', BiometricFeed::REFUSED)->count(),
            'clock_drift_seconds'   => $int('clock_drift_seconds'),
            'clock_set'             => $bool('clock_set'),
            'clock_checked_at'      => $clockCheckedAt,
            'device_drift_seconds'  => $int('device_drift_seconds'),
            'last_punch_at'         => $this->feed->lastPunchAt(),
        ];
        $snapshot['device_users_not_in_hr'] = $this->deviceUsersNotInHr();
        $snapshot['warnings'] = $this->warnings($snapshot);

        if ($withLists) {
            $snapshot['held_by_id'] = DB::table('biometric_feed')->where('kind', BiometricFeed::HELD)
                ->groupBy('key')->orderBy('key')
                ->selectRaw('`key` as zkteco_id, count(*) as punches, min(value) as first_punch, max(value) as last_punch')
                ->get()->map(fn ($row) => (array) $row)->all();
        } else {
            $snapshot['device_users_not_in_hr'] = count($snapshot['device_users_not_in_hr']);
        }

        return $snapshot;
    }

    /**
     * Device users listed by the agent whose ID is still not an employee's. Filtered when read,
     * so a user HR has added since the last list drops off at once.
     */
    protected function deviceUsersNotInHr(): array
    {
        $known = Employee::whereNotNull('zkteco_id')->pluck('zkteco_id')->map(fn ($zk) => (string) $zk)->flip();

        return BiometricFeed::where('kind', BiometricFeed::DEVICE_USER)->orderByRaw('length(`key`)')->orderBy('key')->get()
            ->reject(fn ($row) => isset($known[(string) $row->key]))
            ->map(fn ($row) => ['user_id' => $row->key, 'name' => $row->value])
            ->values()->all();
    }

    /** @return array<int, array{code:string, level:string, message:string}> */
    protected function warnings(array $s): array
    {
        $warnings = [];
        $now = Carbon::now('Asia/Manila');

        // "No contact since HH:MM" after 10 minutes of silence, only between 08:00 and 20:00.
        $time = $now->format('H:i');
        $watching = $time >= config('biometrics.watch_from', '08:00') && $time < config('biometrics.watch_until', '20:00');
        $silence = (int) config('biometrics.silence_minutes', 10) * 60;
        if ($watching) {
            if ($s['last_contact'] === null) {
                $warnings[] = ['code' => 'no_contact', 'level' => 'danger',
                    'message' => 'No contact from the office PC yet.'];
            } elseif ($now->getTimestamp() - $s['last_contact']->getTimestamp() > $silence) {
                $since = $s['last_contact']->copy()->setTimezone('Asia/Manila');
                $warnings[] = ['code' => 'no_contact', 'level' => 'danger',
                    'message' => 'No contact since ' . $since->format('H:i')
                        . ($since->isSameDay($now) ? '' : ' on ' . $since->format('M d')) . '.'];
            }
        }

        if ($s['device_reachable'] === false) {
            $warnings[] = ['code' => 'device_unreachable', 'level' => 'danger',
                'message' => 'The office PC cannot reach the biometric device.'];
        }

        $count = $s['device_record_count'];
        $capacity = $s['device_capacity'];
        if ($count !== null && $count >= $capacity) {
            $warnings[] = ['code' => 'device_records_full', 'level' => 'danger',
                'message' => 'The device is full: ' . number_format($count) . ' of ' . number_format($capacity)
                    . ' records. Clear it by hand now, after a download.'];
        } elseif ($count !== null && $count >= (int) config('biometrics.device_warn_at', 40000)) {
            $warnings[] = ['code' => 'device_records_high', 'level' => 'warning',
                'message' => 'The device holds ' . number_format($count) . ' of ' . number_format($capacity)
                    . ' records. Plan a clear by hand.'];
        }

        $drift = $s['clock_drift_seconds'];
        if ($drift !== null && abs($drift) > (int) config('biometrics.max_clock_drift_seconds', 60)) {
            $warnings[] = ['code' => 'clock_not_set', 'level' => 'warning',
                'message' => sprintf('The office PC clock is %+d s off Likha HR, so the device clock was not set. Fix the PC clock.', $drift)];
        }

        return $warnings;
    }
}
