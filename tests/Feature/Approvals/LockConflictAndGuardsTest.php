<?php

namespace Tests\Feature\Approvals;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * What a run on MySQL with real parallel requests found after the first result:
 * a deadlock between an approval and a rejection, and four smaller gaps.
 * None of these cases has a number in the stories; they pin rules of S-16 and S-19.
 */
class LockConflictAndGuardsTest extends ApprovalsTestCase
{
    /**
     * Makes the next $times statements that change the request table, inside a
     * transaction, fail the way MySQL reports a lock conflict: by default a deadlock
     * (SQLSTATE 40001, error 1213). The statement is the last one of a decision, so
     * everything it wrote before is in the transaction that has to be rolled back.
     */
    private function deadlocks(
        int $times,
        string $statement = 'update "attendance_change_requests" set',
        string $sqlState = '40001',
        int $error = 1213,
        string $text = 'Deadlock found when trying to get lock; try restarting transaction'
    ): void {
        $left = $times;
        DB::connection()->beforeExecuting(function (string $sql) use (&$left, $statement, $sqlState, $error, $text) {
            if ($left > 0 && DB::transactionLevel() > 0 && str_starts_with($sql, $statement)) {
                $left--;
                $driver = new class("SQLSTATE[{$sqlState}]: {$error} {$text}", $sqlState, $error, $text) extends \PDOException {
                    public function __construct(string $message, string $sqlState, int $error, string $text)
                    {
                        parent::__construct($message);
                        $this->code = $sqlState;
                        $this->errorInfo = [$sqlState, $error, $text];
                    }
                };
                throw new QueryException('sqlite', $sql, [], $driver);
            }
        });
    }

    /** A lock wait that timed out: MySQL error 1205, whose SQLSTATE (HY000) says nothing by itself. */
    private function lockWaitTimeouts(int $times): void
    {
        $this->deadlocks($times, 'update "attendance_change_requests" set', 'HY000', 1205, 'Lock wait timeout exceeded');
    }

    #[TestDox('a lock wait that timed out (error 1205) is treated like a deadlock: retried once, then 409')]
    public function test_lock_wait_timeout_is_a_lock_conflict(): void
    {
        $this->askFourTimes();
        $this->askFourTimes($this->h, $this->e2);
        $officialBefore = $this->official();

        $this->lockWaitTimeouts(1);
        $this->reject($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->assertSame(4, $this->requestCount('rejected'));

        $this->lockWaitTimeouts(2);
        $response = $this->approve($this->c, $this->e2, self::DN);
        $response->assertStatus(409);
        $this->assertSame('Someone else is changing this request. Try again.', $response->json('message'));
        $this->assertCount(4, $this->pending($this->e2, self::DN));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('storing a request is retried after one deadlock and answers 409 with no request row after two')]
    public function test_storing_a_request_goes_through_the_retry(): void
    {
        $insert = 'insert into "attendance_change_requests"';

        $this->deadlocks(1, $insert);
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00'])->assertStatus(200)->assertJson(['pending' => true]);
        $this->assertSame(1, $this->requestCount('pending'), 'stored once, by the second attempt');

        $this->deadlocks(2, $insert);
        $response = $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00', 'time_out' => '17:00']);
        $response->assertStatus(409)->assertJson(['success' => false]);
        $this->assertSame('Someone else is changing this request. Try again.', $response->json('message'));
        $this->assertSame(0, DB::table('attendance_change_requests')->where('employee_id', $this->e2->id)->count());
        $this->assertNull($this->day($this->e2, self::DN));
    }

    #[TestDox('a rejection that meets one deadlock is retried and succeeds')]
    public function test_reject_is_retried_once_after_a_deadlock(): void
    {
        $this->askFourTimes();
        $this->deadlocks(1);

        $this->reject($this->c, $this->e1, self::DN)->assertStatus(200)->assertJson(['success' => true]);

        $this->assertSame(4, $this->requestCount('rejected'));
    }

    #[TestDox('a rejection that meets a deadlock twice answers 409, writes nothing and leaves the request pending')]
    public function test_reject_answers_409_after_two_deadlocks(): void
    {
        $this->askFourTimes();
        $officialBefore = $this->official();
        $this->deadlocks(2);

        $response = $this->reject($this->c, $this->e1, self::DN);

        $response->assertStatus(409)->assertJson(['success' => false]);
        $this->assertSame('Someone else is changing this request. Try again.', $response->json('message'));
        $this->assertCount(4, $this->pending($this->e1, self::DN));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('an approval that meets one deadlock is retried and applies the change exactly once')]
    public function test_approve_is_retried_once_after_a_deadlock(): void
    {
        $this->askFourTimes();
        $this->deadlocks(1);

        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200)->assertJson(['success' => true]);

        $this->assertSame(4, $this->requestCount('approved'));
        $this->assertSame(1, DB::table('attendance_days')->where('employee_id', $this->e1->id)->whereDate('work_date', self::DN)->count());
        $this->assertSame(4, DB::table('attendance_overrides')->count(), 'the first, rolled back attempt left no override rows');
        $this->assertSame(self::DN . ' 08:00:00', $this->day($this->e1, self::DN)['time_in']);
    }

    #[TestDox('an approval that meets a deadlock twice answers 409, writes nothing and leaves the request pending')]
    public function test_approve_answers_409_after_two_deadlocks(): void
    {
        $this->askFourTimes();
        $officialBefore = $this->official();
        $this->deadlocks(2);

        $response = $this->approve($this->c, $this->e1, self::DN);

        $response->assertStatus(409)->assertJson(['success' => false]);
        $this->assertSame('Someone else is changing this request. Try again.', $response->json('message'));
        $this->assertCount(4, $this->pending($this->e1, self::DN));
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('"approve selected" names an entry that met a deadlock twice and goes on with the others')]
    public function test_several_reports_a_lock_conflict(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);
        $entries = [
            ['id' => $this->anchorId($this->e1, self::DN), 'seen' => $this->seen($this->e1, self::DN)],
            ['id' => $this->anchorId($this->e2, self::DN), 'seen' => $this->seen($this->e2, self::DN)],
        ];
        $this->deadlocks(2);

        $response = $this->actingAs($this->c)->postJson('/approvals/approve-several', ['entries' => $entries]);

        $response->assertStatus(200);
        $this->assertSame('1 approved, 1 not approved: someone else is changing it', $response->json('message'));
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertNotNull($this->day($this->e2, self::DN));
    }

    #[TestDox('a change or a withdrawal by the requester is retried after one deadlock and answers 409 after two')]
    public function test_change_and_withdraw_handle_a_deadlock(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DN);
        $before = $this->pending($this->e1, self::DN);

        $this->deadlocks(2);
        $change = $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/change", ['new_value' => '08:20', 'reason' => 'Itinama ang oras']);
        $change->assertStatus(409);
        $this->assertSame('Someone else is changing this request. Try again.', $change->json('message'));
        $this->assertSame($before, $this->pending($this->e1, self::DN));

        $this->deadlocks(2);
        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(409);
        $this->assertSame($before, $this->pending($this->e1, self::DN));

        $this->deadlocks(1);
        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/change", ['new_value' => '08:20', 'reason' => 'Itinama ang oras'])->assertStatus(200);
        $this->assertSame('08:20:00', $this->pending($this->e1, self::DN)['time_in']['new_value']);
        $this->assertEquals(2, $this->pending($this->e1, self::DN)['time_in']['version'], 'changed once, not twice');

        $this->deadlocks(1);
        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(200);
        $this->assertSame(1, $this->requestCount('withdrawn'));
    }

    #[TestDox('reject, change and withdraw lock the employee row first, then the request rows, as approve does')]
    public function test_every_decision_locks_in_one_order(): void
    {
        // sqlite leaves the lock clause out of its SQL; this grammar writes it as a comment.
        $connection = DB::connection();
        $connection->setQueryGrammar($connection->withTablePrefix(new class extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
            protected function compileLock(\Illuminate\Database\Query\Builder $query, $value)
            {
                return $value === true ? '/* for update */' : '';
            }
        }));
        $lockedTables = [];
        DB::listen(function ($query) use (&$lockedTables) {
            if (DB::transactionLevel() > 0 && str_contains($query->sql, '/* for update */')) {
                preg_match('/from "(\w+)"/', $query->sql, $m);
                $lockedTables[] = $m[1] ?? '?';
            }
        });
        $order = function (callable $action) use (&$lockedTables) {
            $lockedTables = [];
            $action();

            return array_slice($lockedTables, 0, 2);
        };
        $expected = ['employees', 'attendance_change_requests'];

        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DN);
        $this->assertSame($expected, $order(fn () => $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/change", ['new_value' => '08:20', 'reason' => 'Itinama ang oras'])->assertStatus(200)), 'change');
        $this->assertSame($expected, $order(fn () => $this->reject($this->c, $this->e1, self::DN)->assertStatus(200)), 'reject');

        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e2, self::DN);
        $this->assertSame($expected, $order(fn () => $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(200)), 'withdraw');

        $this->saveAll($this->h, $this->e2, self::DX, ['time_in' => '08:00']);
        $this->assertSame($expected, $order(fn () => $this->approve($this->c, $this->e2, self::DX)->assertStatus(200)), 'approve');
    }

    #[TestDox('HR staff never remove SIL directly, also when the SIL is applied while their removal is on its way')]
    public function test_sil_removal_by_hr_staff_never_reaches_the_direct_path(): void
    {
        $this->silSetup($this->e1, 5);
        // The CEO applies SIL after the entry point looked for it and before the write locks the employee.
        $state = new \stdClass();
        $state->done = false;
        $state->applier = $this->c->id;
        $state->date = self::DN;
        $this->app->bind(\App\Services\AttendanceChangeService::class, fn ($app) => new class($app->make(\App\Services\AttendanceComputeService::class), $state) extends \App\Services\AttendanceChangeService {
            public function __construct(\App\Services\AttendanceComputeService $compute, private \stdClass $state)
            {
                parent::__construct($compute);
            }

            public function lockEmployeeById(int $employeeId): Employee
            {
                $this->appliedMeanwhile($employeeId);

                return parent::lockEmployeeById($employeeId);
            }

            public function lockEmployee(Employee $employee): void
            {
                $this->appliedMeanwhile($employee->id);
                parent::lockEmployee($employee);
            }

            private function appliedMeanwhile(int $employeeId): void
            {
                if (!$this->state->done) {
                    $this->state->done = true;
                    DB::table('sil_applications')->insert([
                        'employee_id' => $employeeId, 'sil_date' => $this->state->date, 'reason' => 'Inilagay ng CEO',
                        'applied_by' => $this->state->applier, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });

        $response = $this->removeSil($this->h, $this->e1, self::DN);

        $this->assertSame(1, DB::table('sil_applications')->count(), 'the SIL the CEO applied is still there');
        $response->assertStatus(200)->assertJson(['pending' => true]);
        $this->assertSame('remove', $this->pending($this->e1, self::DN)['sil']['new_value']);
    }

    #[TestDox('HR staff asking to remove SIL where none is applied get the message and no request')]
    public function test_sil_removal_without_sil_makes_no_request(): void
    {
        $this->silSetup($this->e1, 5);

        $response = $this->removeSil($this->h, $this->e1, self::DN);

        $response->assertStatus(200)->assertJsonMissing(['pending' => true]);
        $this->assertSame('No SIL was applied on this date.', $response->json('message'));
        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('for anyone but the CEO an approval address answers 403 whether the id exists or not')]
    public function test_approval_addresses_do_not_tell_which_ids_exist(): void
    {
        $this->askFourTimes();
        $real = $this->anchorId($this->e1, self::DN);
        $seen = $this->seen($this->e1, self::DN);

        foreach ([$this->h, $this->m] as $user) {
            foreach ([$real, 999999] as $id) {
                $this->actingAs($user)->postJson("/approvals/{$id}/approve", ['seen' => $seen])->assertStatus(403);
                $this->actingAs($user)->postJson("/approvals/{$id}/reject", ['seen' => $seen, 'reason' => 'Hindi tama'])->assertStatus(403);
            }
        }
        // The CEO is told that an id does not exist.
        $this->actingAs($this->c)->postJson('/approvals/999999/approve', ['seen' => $seen])->assertStatus(404);
        $this->actingAs($this->c)->postJson('/approvals/999999/reject', ['seen' => $seen, 'reason' => 'Hindi tama'])->assertStatus(404);
        $this->assertCount(4, $this->pending($this->e1, self::DN));
    }

    #[TestDox('HR staff and admins get 403 on the batch addresses and nothing changes')]
    public function test_batch_addresses_are_ceo_only(): void
    {
        $this->actingAs($this->h)->postJson('/attendance-calendar/bulk-action', [
            'date' => self::DN, 'employee_ids' => [$this->e1->id, $this->e2->id], 'action' => 'fill_from_shift', 'reason' => self::REASON,
        ])->assertStatus(200);
        $batch = DB::table('attendance_change_requests')->value('batch_id');
        $entries = [
            ['id' => $this->anchorId($this->e1, self::DN), 'seen' => $this->seen($this->e1, self::DN)],
            ['id' => $this->anchorId($this->e2, self::DN), 'seen' => $this->seen($this->e2, self::DN)],
        ];
        $officialBefore = $this->official();

        foreach ([$this->h, $this->m] as $user) {
            $this->actingAs($user)->postJson("/approvals/batch/{$batch}/reject", ['entries' => $entries, 'reason' => 'Hindi tama'])->assertStatus(403);
            $this->actingAs($user)->postJson("/approvals/batch/{$batch}/approve", ['entries' => $entries])->assertStatus(403);
            $this->actingAs($user)->postJson('/approvals/reject-several', ['entries' => $entries, 'reason' => 'Hindi tama'])->assertStatus(403);
        }

        $this->assertSame(8, $this->requestCount('pending'));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('a requester who became CEO cannot approve or change their old request, and may reject or withdraw it')]
    public function test_requester_who_became_ceo(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);
        DB::table('users')->where('id', $this->h->id)->update(['role' => 'ceo']);
        $promoted = User::find($this->h->id);
        $ofE1 = $this->anchorId($this->e1, self::DN);
        $ofE2 = $this->anchorId($this->e2, self::DN);
        $officialBefore = $this->official();

        // Not approve, not change: either would make their own value official or keep it alive unseen.
        $this->approve($promoted, $this->e1, self::DN)->assertStatus(403);
        $this->actingAs($promoted)->postJson("/attendance-requests/{$ofE1}/change", ['new_value' => '09:00', 'reason' => 'Akin ito dati'])->assertStatus(403);
        $this->assertSame('08:00:00', $this->pending($this->e1, self::DN)['time_in']['new_value']);
        $this->assertEquals(1, $this->pending($this->e1, self::DN)['time_in']['version']);

        // Reject and withdraw make nothing official.
        $this->reject($promoted, $this->e1, self::DN, 'Hindi na kailangan')->assertStatus(200);
        $this->actingAs($promoted)->postJson("/attendance-requests/{$ofE2}/withdraw")->assertStatus(200);

        $this->assertSame('rejected', DB::table('attendance_change_requests')->where('id', $ofE1)->value('status'));
        $this->assertSame('withdrawn', DB::table('attendance_change_requests')->where('id', $ofE2)->value('status'));
        $this->assertSame($officialBefore, $this->official());
    }

    // Before slice 004 the approved day kept the creation note of the CEO's own edit.
    // An approval now rechecks the day, so its note names what the day still lacks.
    #[TestDox('an approved request from the older page creates the day, and the recheck notes what it still lacks')]
    public function test_old_page_request_is_rechecked_after_approval(): void
    {
        $post = fn (User $user, Employee $employee) => $this->actingAs($user)->postJson('/attendance/override', [
            'employee_id' => $employee->id, 'work_date' => self::DN, 'field' => 'time_in', 'new_value' => '08:00', 'reason' => self::REASON,
        ]);
        // The control: the CEO's direct edit of an absent day through the same address.
        $post($this->c, $this->e2)->assertStatus(200)->assertJsonMissing(['pending' => true]);

        $post($this->h, $this->e1)->assertStatus(200)->assertJson(['pending' => true]);
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        $this->assertSame('Manually created by CEO from absent row.', $this->day($this->e2, self::DN)['notes']);
        $approved = $this->day($this->e1, self::DN);
        $this->assertSame(
            'Lunch punches could not be inferred. No Time Out recorded (missed timeout). Preserved overrides: time_in.',
            $approved['notes']
        );
        $this->assertEquals(1, $approved['needs_review']);
    }
}
