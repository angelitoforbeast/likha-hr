<?php

namespace Tests\Feature\ScheduleFeed;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixtures for the staff schedule feed: a test-only key, two shifts, one
 * department and invented employees, and a sample week (Monday 2026-10-05 to Sunday
 * 2026-10-11).
 */
abstract class ScheduleFeedTestCase extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-only-feed-key-0123456789abcdef';
    protected const MON = '2026-10-05';
    protected const SUN = '2026-10-11';

    protected const STAMP = ['created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00'];

    protected Shift $dayShift;
    protected Shift $lateShift;
    protected Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.schedule_feed.key' => self::KEY]);

        $this->dayShift = Shift::create([
            'name' => 'Day shift', 'start_time' => '08:00:00', 'end_time' => '17:00:00',
            'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00',
            'required_work_minutes' => 480, 'grace_in_minutes' => 10,
        ]);
        $this->lateShift = Shift::create([
            'name' => 'Late shift', 'start_time' => '09:30:00', 'end_time' => '18:30:00',
            'lunch_start' => '13:30:00', 'lunch_end' => '14:30:00',
            'required_work_minutes' => 480, 'grace_in_minutes' => 5,
        ]);
        $this->dept = Department::create(['name' => 'Production']);
    }

    protected function employee(string $zktecoId, string $fullName, array $extra = []): Employee
    {
        return Employee::create(array_merge([
            'zkteco_id'        => $zktecoId,
            'full_name'        => $fullName,
            'status'           => 'active',
            'default_shift_id' => $this->dayShift->id,
            'department_id'    => $this->dept->id,
            'schedule_mode'    => Employee::MODE_MANUAL,
        ], $extra));
    }

    protected function feed(string $query = '', ?string $key = self::KEY): TestResponse
    {
        return $this->call('GET', '/api/staff-schedule' . ($query === '' ? '' : '?' . $query), [], [], [],
            $key === null ? [] : ['HTTP_X_SCHEDULE_FEED_KEY' => $key]);
    }

    protected function week(): TestResponse
    {
        return $this->feed('from=' . self::MON . '&to=' . self::SUN)->assertStatus(200);
    }

    /** The feed's entry for an employee on a date. */
    protected function entry(TestResponse $response, Employee $employee, string $date): array
    {
        foreach ($response->json('days') as $day) {
            if ($day['date'] !== $date) continue;
            foreach ($day['employees'] as $entry) {
                if ($entry['employee_id'] === $employee->id) return $entry;
            }
        }
        $this->fail("no entry for employee {$employee->id} on {$date}");
    }

    protected function insert(string $table, array $row): int
    {
        return DB::table($table)->insertGetId($row + self::STAMP);
    }
}
