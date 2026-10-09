<?php

namespace Tests\Feature\Import;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AttendanceComputeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-03 A compute problem never fails the import.
 */
class ImportSurvivesComputeFailureTest extends ImportTestCase
{
    private const FILTER = [
        'date_from'      => '2026-10-05',
        'date_to'        => '2026-10-07',
        'included_count' => 2,
        'kept_lines'     => 9,
        'dropped_lines'  => 3,
    ];

    #[TestDox('S-03.1 one day that cannot be computed is counted and logged, the other days go on')]
    public function test_s_03_1_one_failing_day_does_not_stop_the_others(): void
    {
        $failFor = [$this->a->id, self::D1];
        $this->app->bind(AttendanceComputeService::class, fn () => new class($failFor) extends AttendanceComputeService {
            public function __construct(private array $failFor)
            {
            }

            public function computeDay(
                Employee $employee,
                string $workDate,
                Collection $punches,
                ?Shift $shift,
                ?int $sourceRunId = null
            ): AttendanceDay {
                if ([$employee->id, $workDate] === $this->failFor) {
                    throw new \RuntimeException('this day cannot be computed');
                }

                return parent::computeDay($employee, $workDate, $punches, $shift, $sourceRunId);
            }
        });
        Log::spy();

        $run = $this->process($this->makeRun(array_merge(
            $this->fullDay('1', self::D1),
            $this->fullDay('1', self::D2),
            $this->fullDay('2', self::D1),
        )));

        $this->assertSame('done', $run->status);
        $this->assertSame(12, DB::table('attendance_logs')->count());
        $this->assertSame([$this->key($this->a, self::D2), $this->key($this->b, self::D1)], $this->dayKeys());
        $this->assertSummary(2, 0, 1, $run);

        $employeeId = $this->a->id;
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, "#{$employeeId}") && str_contains($message, self::D1));
    }

    #[TestDox('S-03.2 a compute step that fails as a whole leaves the run done with the punches saved')]
    public function test_s_03_2_failing_compute_step_does_not_fail_the_import(): void
    {
        $this->app->bind(AttendanceComputeService::class, fn () => new class extends AttendanceComputeService {
            public function computeEmployeeDays(array $employeeDays, ?int $sourceRunId = null): array
            {
                throw new \RuntimeException('the compute step is down');
            }
        });
        Log::spy();

        $run = $this->process($this->makeRun($this->fullDay('1', self::D1)));

        // The log names the run and the kind of failure, never the failure's own text.
        $runId = $run->id;
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn ($message) => str_contains($message, "#{$runId}")
                && str_contains($message, 'RuntimeException')
                && !str_contains($message, 'the compute step is down')
        );
        $this->assertSame('done', $run->status);
        $this->assertSame(4, $this->punchCount($this->a, self::D1));
        $this->assertArrayNotHasKey('error', $run->stats_json);
        $this->assertNotNull($this->summary($run), 'the compute summary must be recorded even when the step fails');
        $this->assertSummary(0, 0, 1, $run);
        $this->assertSame(0, DB::table('attendance_days')->count());
    }

    #[TestDox('S-03.3 the preview filter stored at commit is still in the stats when the import finishes')]
    public function test_s_03_3_preview_filter_is_kept(): void
    {
        $run = $this->makeRun($this->fullDay('1', self::D1), ['preview_filter' => self::FILTER]);

        $run = $this->process($run);

        $this->assertIsArray($run->stats_json);
        $this->assertSame(self::FILTER, $run->stats_json['preview_filter']);
        $this->assertSame(4, $run->stats_json['total_logs_parsed']);
        $this->assertSame(4, $run->stats_json['total_logs_inserted']);
        $this->assertSummary(1, 0, 0, $run);
    }

    #[TestDox('S-03.4 a preview filter stored in the older string form is kept as well')]
    public function test_s_03_4_preview_filter_in_the_older_form_is_kept(): void
    {
        $run = $this->makeRun($this->fullDay('1', self::D1));
        // The form written before this slice: a JSON string handed to a column the model casts to array.
        $run->update(['stats_json' => json_encode(['preview_filter' => self::FILTER])]);
        $this->assertIsString($run->fresh()->stats_json);

        $run = $this->process($run);

        $this->assertIsArray($run->stats_json);
        $this->assertSame(self::FILTER, $run->stats_json['preview_filter']);
        $this->assertSame(4, $run->stats_json['total_logs_inserted']);
        $this->assertSummary(1, 0, 0, $run);
    }

    #[TestDox('S-03.5 a failed parse keeps the preview filter, sets the error and has no compute summary')]
    public function test_s_03_5_failed_parse_keeps_the_preview_filter(): void
    {
        $run = $this->makeRun(null, ['preview_filter' => self::FILTER]);

        $run = $this->process($run);

        $this->assertSame('failed', $run->status);
        $this->assertIsArray($run->stats_json);
        $this->assertNotEmpty($run->stats_json['error']);
        $this->assertSame(self::FILTER, $run->stats_json['preview_filter']);
        $this->assertArrayNotHasKey('compute', $run->stats_json);
    }

    #[TestDox('S-03.6 the compute summary always has its three integer keys')]
    public function test_s_03_6_summary_has_three_integer_keys(): void
    {
        $withDays = $this->process($this->makeRun($this->fullDay('1', self::D1)));
        $empty = $this->process($this->makeRun(['']));

        foreach ([$withDays, $empty] as $run) {
            $summary = $this->summary($run);
            $this->assertIsArray($summary);
            $this->assertSame(['days_computed', 'locked_skipped', 'errors'], array_keys($summary));
            foreach ($summary as $value) {
                $this->assertIsInt($value);
            }
        }
        $this->assertSame(1, $withDays->stats_json['compute']['days_computed']);
        $this->assertSame(0, $empty->stats_json['compute']['days_computed']);
    }
}
