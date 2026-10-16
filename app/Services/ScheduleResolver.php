<?php

namespace App\Services;

use App\Models\DayOff;
use App\Models\Employee;
use App\Models\EmployeeActiveStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeStatusHistory;
use App\Models\Holiday;
use App\Models\RestDayPattern;
use App\Models\Shift;
use App\Models\SilApplication;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The one place that decides an employee's schedule on a date: shift, rest day, day off,
 * SIL, holiday and active status. The Employee model, the Attendance Calendar and the
 * staff schedule feed all ask it, so the rules exist once.
 *
 * Built for a set of employees and a date range, it loads each kind of data with one
 * query the first time it is needed, so a range costs the same few queries whatever its
 * size. Asking about an employee or a date outside what it was built for is an error.
 */
class ScheduleResolver
{
    public const REASON_INACTIVE = 'inactive';
    public const REASON_LEAVE    = 'leave';
    public const REASON_DAY_OFF  = 'day_off';
    public const REASON_REST_DAY = 'rest_day';
    public const REASON_HOLIDAY  = 'holiday';

    /** @var Collection<int, Employee> keyed by id */
    protected Collection $employees;
    protected string $from;
    protected string $to;

    /** @var array<string, Collection> loaded data, by kind */
    protected array $loaded = [];

    public function __construct(iterable $employees, string $from, string $to)
    {
        $this->employees = collect($employees)->keyBy('id');
        $this->from = self::day($from);
        $this->to   = self::day($to);
    }

    public static function forEmployee(Employee $employee, string $date): self
    {
        return new self([$employee], $date, $date);
    }

    /**
     * The shift of the employee on the date: the shift assignment whose range holds the
     * date, the most recently started one first (a one-day assignment wins over an
     * ongoing one on its day), else the employee's default shift. Department shifts reach
     * an employee as shift assignments copied when the department's shift is set.
     */
    public function shiftFor(Employee $employee, string $date): ?Shift
    {
        $this->check($employee, $date);
        $assignment = self::effectiveRows($this->rows('assignments')->get($employee->id, collect()), $date, 'effective_date')->first();

        return $assignment ? $assignment->shift : $employee->defaultShift;
    }

    /**
     * Why the date is off for the employee: 'day_off' when a day-off row says so,
     * 'rest_day' when a weekly rest-day pattern covers it, or null when it is a working
     * day (including a rest day cancelled by a cancel_day_off row).
     */
    public function dayOffKind(Employee $employee, string $date): ?string
    {
        $this->check($employee, $date);
        $date = self::day($date);
        $override = $this->rows('dayOffs')->get($employee->id . '_' . $date)?->first();

        if ($override) {
            if ($override->type === DayOff::TYPE_DAY_OFF) return self::REASON_DAY_OFF;
            if ($override->type === DayOff::TYPE_CANCEL_DAY_OFF) return null;
        }

        $dayOfWeek = Carbon::parse($date)->dayOfWeek; // 0=Sunday, 6=Saturday
        $patterns = self::effectiveRows($this->rows('restDays')->get($employee->id, collect()), $date, 'effective_from');

        return $patterns->contains(fn ($p) => (int) $p->day_of_week === $dayOfWeek) ? self::REASON_REST_DAY : null;
    }

    public function isDayOff(Employee $employee, string $date): bool
    {
        return $this->dayOffKind($employee, $date) !== null;
    }

    /** The holiday on the date: an exact-date holiday first, else a recurring one on the same month and day. */
    public function holidayFor(string $date): ?Holiday
    {
        $this->check(null, $date);
        $date = Carbon::parse(self::day($date));
        $holidays = $this->rows('holidays');

        return $holidays->first(fn ($h) => $h->date->format('Y-m-d') === $date->format('Y-m-d'))
            ?? $holidays->first(fn ($h) => $h->recurring && $h->date->month === $date->month && $h->date->day === $date->day);
    }

    /** Holiday eligibility from the employment status of the date; no status means eligible. */
    public function isHolidayEligible(Employee $employee, string $date): bool
    {
        $this->check($employee, $date);
        $history = self::effectiveRows($this->rows('statusHistory')->get($employee->id, collect()), $date, 'effective_from')->first();
        $status = $history?->employmentStatus;

        return $status ? (bool) $status->holiday_eligible : true;
    }

    /** The SIL application of the employee on the date. Only applied (approved) SIL has a row. */
    public function silFor(Employee $employee, string $date): ?SilApplication
    {
        $this->check($employee, $date);

        return $this->rows('sil')->get($employee->id . '_' . self::day($date));
    }

    /**
     * Whether the employee is active on the date: the active-status row whose range holds
     * the date, else the status kept on the employee (an employee without history is
     * active, as EmployeeActiveStatus::syncEmployeeStatus sets it).
     */
    public function isActive(Employee $employee, string $date): bool
    {
        $this->check($employee, $date);
        $row = self::effectiveRows($this->rows('activeStatuses')->get($employee->id, collect()), $date, 'effective_from')->first();

        return ($row ? $row->status : $employee->status) === 'active';
    }

    /**
     * The schedule of the employee on the date. The first rule that applies wins:
     * inactive, then approved SIL (the calendar shows SIL over a rest day), then a day
     * off or rest day (payroll counts a rest day before a holiday), then a holiday the
     * employee is eligible for (payroll treats a holiday as a working day for an
     * employee who is not), else scheduled on the shift of the date.
     *
     * @return array{scheduled: bool, reason: ?string, shift: ?Shift, holiday: ?Holiday}
     */
    public function resolve(Employee $employee, string $date): array
    {
        $off = fn (string $reason, ?Holiday $holiday = null) => ['scheduled' => false, 'reason' => $reason, 'shift' => null, 'holiday' => $holiday];

        if (!$this->isActive($employee, $date)) return $off(self::REASON_INACTIVE);
        if ($this->silFor($employee, $date)) return $off(self::REASON_LEAVE);
        if ($kind = $this->dayOffKind($employee, $date)) return $off($kind);

        $holiday = $this->holidayFor($date);
        if ($holiday && $this->isHolidayEligible($employee, $date)) return $off(self::REASON_HOLIDAY, $holiday);

        return ['scheduled' => true, 'reason' => null, 'shift' => $this->shiftFor($employee, $date), 'holiday' => null];
    }

    /**
     * The rows whose effective range holds the date, the most recently started first;
     * of two that start the same day, the later-created one first.
     */
    protected static function effectiveRows(Collection $rows, string $date, string $fromColumn): Collection
    {
        $date = self::day($date);

        return $rows
            ->filter(fn ($r) => self::day($r->{$fromColumn}) <= $date
                && ($r->effective_until === null || self::day($r->effective_until) >= $date))
            ->sort(fn ($a, $b) => [self::day($b->{$fromColumn}), $b->id] <=> [self::day($a->{$fromColumn}), $a->id])
            ->values();
    }

    /** One kind of data for every employee and the whole range, loaded with one query the first time. */
    protected function rows(string $kind): Collection
    {
        if (isset($this->loaded[$kind])) {
            return $this->loaded[$kind];
        }

        $ids = $this->employees->keys()->all();
        $from = $this->from;
        $toEnd = $this->to . ' 23:59:59'; // a date column holding a time part is still in range
        $overlapping = fn ($q, string $fromColumn) => $q->whereIn('employee_id', $ids)
            ->where($fromColumn, '<=', $toEnd)
            ->where(fn ($w) => $w->whereNull('effective_until')->orWhere('effective_until', '>=', $from));

        return $this->loaded[$kind] = match ($kind) {
            'assignments' => $overlapping(EmployeeShiftAssignment::with('shift'), 'effective_date')->get()->groupBy('employee_id'),
            'restDays' => $overlapping(RestDayPattern::query(), 'effective_from')->get()->groupBy('employee_id'),
            'activeStatuses' => $overlapping(EmployeeActiveStatus::query(), 'effective_from')->get()->groupBy('employee_id'),
            'statusHistory' => $overlapping(EmployeeStatusHistory::with('employmentStatus'), 'effective_from')->get()->groupBy('employee_id'),
            'dayOffs' => DayOff::whereIn('employee_id', $ids)->whereBetween('off_date', [$from, $toEnd])->orderBy('id')->get()
                ->groupBy(fn ($d) => $d->employee_id . '_' . $d->off_date->format('Y-m-d')),
            'sil' => SilApplication::whereIn('employee_id', $ids)->whereBetween('sil_date', [$from, $toEnd])->get()
                ->keyBy(fn ($s) => $s->employee_id . '_' . $s->sil_date->format('Y-m-d')),
            'holidays' => Holiday::where(fn ($q) => $q->whereBetween('date', [$from, $toEnd])->orWhere('recurring', true))
                ->orderBy('id')->get(),
        };
    }

    /** Data was loaded for these employees and dates only: anything else would read as "nothing found". */
    protected function check(?Employee $employee, string $date): void
    {
        $date = self::day($date);
        if ($date < $this->from || $date > $this->to) {
            throw new \OutOfRangeException("ScheduleResolver built for {$this->from}..{$this->to} was asked about {$date}");
        }
        if ($employee && !$this->employees->has($employee->id)) {
            throw new \OutOfRangeException("ScheduleResolver was not built for employee {$employee->id}");
        }
    }

    protected static function day($value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
