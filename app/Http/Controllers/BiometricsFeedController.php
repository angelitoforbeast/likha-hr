<?php

namespace App\Http\Controllers;

use App\Services\BiometricFeedHealth;
use App\Services\BiometricFeedService;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The endpoints of the office PC biometrics agent (spec 012). Behind the X-BIOMETRICS-FEED-KEY
 * check and its own limit; everything in a request is untrusted. Every answer carries the
 * server time (UTC) for the agent's clock check and the latest stored punch for its window.
 */
class BiometricsFeedController extends Controller
{
    private const AGENT_ID = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'];

    public function __construct(
        protected BiometricFeedService $feed,
        protected BiometricFeedHealth $health,
    ) {
    }

    /** POST /api/biometrics/punches {agent_id, attlog: "zkteco_id<TAB>Y-m-d H:i:s\n..."} */
    public function punches(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'agent_id' => self::AGENT_ID,
            'attlog'   => ['required', 'string', 'max:200000'],
        ]);
        if ($validator->fails()) {
            return $this->reply(['error' => 'invalid_request', 'fields' => array_keys($validator->errors()->toArray())], 422);
        }
        // A contact only once the call is well formed: a misconfigured agent must not look healthy.
        $this->feed->recordContact();

        $lines = BiometricFeedService::attlogLines((string) $request->input('attlog'));
        $max = (int) config('biometrics.max_lines', 1000);
        if (count($lines) > $max) {
            // Refused whole: a partial import would leave the agent unsure what was stored.
            return $this->reply(['error' => 'too_many_lines', 'max_lines' => $max, 'lines' => count($lines)], 422);
        }

        try {
            $run = $this->feed->ingestAgentPost($lines, (string) ($request->input('agent_id') ?: 'agent'));
        } catch (LockTimeoutException) {
            return $this->reply(['error' => 'busy'], 503)->header('Retry-After', '30');
        }

        $stats = $run->statsArray();
        $compute = $stats['compute'] ?? [];
        $counts = [
            'run_id'         => $run->id,
            'lines'          => count($lines),
            'inserted'       => (int) ($stats['total_logs_inserted'] ?? 0),
            'duplicates'     => (int) ($stats['total_duplicates_skipped'] ?? 0),
            'held'           => (int) ($stats['held_punches'] ?? 0),
            'refused'        => (int) ($stats['refused_dates'] ?? 0),
            'invalid'        => (int) ($stats['invalid_lines'] ?? 0),
            'days_computed'  => (int) ($compute['days_computed'] ?? 0),
            'locked_skipped' => (int) ($compute['locked_skipped'] ?? 0),
            'errors'         => (int) ($compute['errors'] ?? 0),
        ];

        if ($run->status !== 'done') {
            // The run's error text stays in its stats and the log; the agent keeps the lines and retries.
            return $this->reply(['error' => 'import_failed'] + $counts, 500);
        }

        return $this->reply($counts);
    }

    /** POST /api/biometrics/heartbeat: the agent and device state after a poll. */
    public function heartbeat(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'agent_id'             => self::AGENT_ID,
            'agent_version'        => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._+-]+$/'],
            'device_reachable'     => ['required', 'boolean'],
            'device_error'         => ['nullable', 'string', 'max:32', 'regex:/^[a-z_]+$/'],
            'device_record_count'  => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'device_user_count'    => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'clock_drift_seconds'  => ['nullable', 'integer', 'min:-1000000000', 'max:1000000000'],
            'clock_set'            => ['nullable', 'boolean'],
            'device_drift_seconds' => ['nullable', 'integer', 'min:-1000000000', 'max:1000000000'],
        ]);
        if ($validator->fails()) {
            return $this->reply(['error' => 'invalid_request', 'fields' => array_keys($validator->errors()->toArray())], 422);
        }
        // A contact only once the call is well formed: a misconfigured agent must not look healthy.
        $this->feed->recordContact();
        $data = $validator->validated();

        $this->feed->setState('last_heartbeat', Carbon::now()->utc()->format('Y-m-d H:i:s'));
        $this->feed->setState('agent_id', $data['agent_id'] ?? null);
        $this->feed->setState('agent_version', $data['agent_version'] ?? null);
        $reachable = filter_var($data['device_reachable'], FILTER_VALIDATE_BOOLEAN);
        $this->feed->setState('device_reachable', $reachable ? '1' : '0');
        $this->feed->setState('device_error', $reachable ? null : ($data['device_error'] ?? null));
        // An unreachable device reports no counts; the last known ones stay.
        foreach (['device_record_count', 'device_user_count'] as $key) {
            if (isset($data[$key])) {
                $this->feed->setState($key, (string) (int) $data[$key]);
            }
        }
        if (isset($data['clock_drift_seconds'])) {
            $this->feed->recordClockCheck(
                (int) $data['clock_drift_seconds'],
                filter_var($data['clock_set'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );
        }
        if (isset($data['device_drift_seconds'])) {
            $this->feed->setState('device_drift_seconds', (string) (int) $data['device_drift_seconds']);
        }

        return $this->reply(['ok' => true]);
    }

    /** POST /api/biometrics/users {users: [{user_id, name}]}: only listed, never applied to employees. */
    public function users(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'agent_id'      => self::AGENT_ID,
            'users'         => ['present', 'array', 'max:5000'],
            'users.*'       => ['array'],
            'users.*.user_id' => ['required', 'regex:/^\d{1,9}$/'],
            'users.*.name'  => ['nullable', 'string', 'max:64'],
        ]);
        if ($validator->fails()) {
            return $this->reply(['error' => 'invalid_request'], 422);
        }
        // A contact only once the call is well formed: a misconfigured agent must not look healthy.
        $this->feed->recordContact();

        return $this->reply($this->feed->replaceDeviceUsers($validator->validated()['users']));
    }

    /** GET /api/biometrics/status: read-only health; no names and no punch lists. */
    public function status(): JsonResponse
    {
        $s = $this->health->snapshot(false);

        return $this->reply([
            'last_contact_at'        => $s['last_contact']?->format('Y-m-d\TH:i:s.v\Z'),
            'agent_version'          => $s['agent_version'],
            'device_reachable'       => $s['device_reachable'],
            'device_record_count'    => $s['device_record_count'],
            'device_user_count'      => $s['device_user_count'],
            'device_capacity'        => $s['device_capacity'],
            'held_count'             => $s['held_count'],
            'held_ids'               => $s['held_ids'],
            'refused_count'          => $s['refused_count'],
            'device_users_not_in_hr' => $s['device_users_not_in_hr'],
            'clock_drift_seconds'    => $s['clock_drift_seconds'],
            'clock_set'              => $s['clock_set'],
            'clock_checked_at'       => $s['clock_checked_at']?->format('Y-m-d\TH:i:s.v\Z'),
            'warnings'               => array_map(fn ($w) => ['code' => $w['code'], 'level' => $w['level'], 'message' => $w['message']], $s['warnings']),
        ], 200, $s['last_punch_at']);
    }

    private function reply(array $body, int $status = 200, ?string $lastPunch = null): JsonResponse
    {
        return response()->json([
            'server_time'   => Carbon::now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'last_punch_at' => $lastPunch ?? $this->feed->lastPunchAt(),
        ] + $body, $status);
    }
}
