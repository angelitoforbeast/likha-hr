<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Shift;
use App\Services\ScheduleResolver;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Read-only feed of who is due to work, on which shift, and why not, for the owner's
 * office. Names and schedule only: no punches, attendance results, pay or contact data
 * leave through it. The schedule comes from ScheduleResolver, the rules the calendar
 * and payroll use.
 */
class StaffScheduleFeedController extends Controller
{
    public const MAX_DAYS = 31;
    protected const TZ = 'Asia/Manila';

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'date' => ['prohibits:from,to', 'date_format:Y-m-d'],
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to'   => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $validator->after(function ($v) use ($request) {
            if ($v->errors()->isEmpty() && $request->query('from') !== null
                && Carbon::parse($request->query('from'))->diffInDays(Carbon::parse($request->query('to'))) + 1 > self::MAX_DAYS) {
                $v->errors()->add('to', 'The range is at most ' . self::MAX_DAYS . ' days.');
            }
        });
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid dates.', 'errors' => $validator->errors()], 422);
        }

        $from = $request->query('from') ?? $request->query('date') ?? Carbon::now(self::TZ)->format('Y-m-d');
        $to   = $request->query('to') ?? $from;

        $employees = Employee::with(['department', 'defaultShift'])->get()
            ->sortBy(fn ($e) => strtolower(($e->department->name ?? 'zzz') . '|' . $e->display_name))
            ->values();
        $schedule = new ScheduleResolver($employees, $from, $to);

        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->format('Y-m-d');
            $days[] = [
                'date'      => $date,
                'employees' => $employees->map(fn ($e) => $this->entry($e, $date, $schedule->resolve($e, $date)))->all(),
            ];
        }

        return response()->json([
            'from'         => $from,
            'to'           => $to,
            'generated_at' => Carbon::now(self::TZ)->toIso8601String(),
            'days'         => $days,
        ]);
    }

    /** One employee on one date. Every field is listed here on purpose: nothing else may leave. */
    protected function entry(Employee $employee, string $date, array $resolved): array
    {
        $entry = [
            'employee_id' => $employee->id,
            'name'        => $employee->display_name,
            'department'  => $employee->department?->name,
            'date'        => $date,
            'scheduled'   => $resolved['scheduled'],
        ];

        if ($resolved['scheduled']) {
            $entry['shift'] = $resolved['shift'] ? $this->shift($resolved['shift']) : null;
        } else {
            $entry['reason'] = $resolved['reason'];
            if ($resolved['reason'] === ScheduleResolver::REASON_DAY_OFF) {
                $entry['day_off_type'] = 'day_off';
            }
            if ($resolved['reason'] === ScheduleResolver::REASON_HOLIDAY) {
                $entry['holiday_name'] = $resolved['holiday']->name;
            }
        }

        return $entry;
    }

    protected function shift(Shift $shift): array
    {
        $hm = fn ($time) => $time ? substr((string) $time, 0, 5) : null;

        return [
            'name'             => $shift->name,
            'start'            => $hm($shift->start_time),
            'end'              => $hm($shift->end_time),
            'lunch_start'      => $hm($shift->lunch_start),
            'lunch_end'        => $hm($shift->lunch_end),
            'grace_in_minutes' => (int) ($shift->grace_in_minutes ?? 0),
        ];
    }
}
