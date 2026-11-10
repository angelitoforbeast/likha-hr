<?php

namespace Tests\Feature\Biometrics;

use App\Models\AttendanceImportRun;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-012.7 Punches of a new hire who is not in Likha HR yet are held, then applied when HR adds them.
 */
class HeldPunchesTest extends BiometricsTestCase
{
    private function fullDay54(string $date): array
    {
        return array_map(fn ($t) => $this->punch('54', $date, $t), self::FULL_DAY);
    }

    private function newHire(string $zk = '54'): Employee
    {
        return Employee::create([
            'zkteco_id' => $zk, 'full_name' => 'New Hire', 'status' => 'active', 'default_shift_id' => $this->shift->id,
        ]);
    }

    #[TestDox('S-012.7 punches for zk 54 with no employee are held and shown, not dropped')]
    public function test_s_012_7_unmatched_punches_are_held(): void
    {
        $response = $this->postPunches(array_merge($this->fullDay54('2026-11-02'), $this->fullDay54('2026-11-03')))->assertOk();

        $this->assertSame(8, $response->json('held'));
        $this->assertSame(0, $response->json('inserted'));
        $this->assertSame(0, DB::table('attendance_logs')->count());
        $this->assertCount(8, $this->feedRows('held'));
        $this->assertContains('54|2026-11-02 08:00:00', $this->feedRows('held'));
        $this->assertSame(8, $this->feedStatus()->json('held_count'));

        $html = $this->actingAs($this->uploader)->get('/import')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-held-id="54"[^>]*>.*?8 punches/s', $html);
    }

    #[TestDox('S-012.7 creating employee 54 applies the held punches through the import path and computes the days')]
    public function test_s_012_7_creating_the_employee_applies_them(): void
    {
        $this->postPunches(array_merge($this->fullDay54('2026-11-02'), $this->fullDay54('2026-11-03')))->assertOk();

        $hire = $this->newHire();

        $this->assertSame(8, DB::table('attendance_logs')->where('employee_id', $hire->id)->count());
        $this->assertNotNull($this->day($hire, '2026-11-02'));
        $this->assertNotNull($this->day($hire, '2026-11-03'));
        $this->assertSame([], $this->feedRows('held'));
        $run = AttendanceImportRun::orderByDesc('id')->first();
        $this->assertSame('held', $run->stats_json['source']);
        $this->assertSame(8, $run->stats_json['total_logs_inserted']);
        $this->assertSame('done', $run->status);
    }

    #[TestDox('S-012.7 the HR Sync Users page creating employee 54 applies the held punches too')]
    public function test_s_012_7_sync_users_creation_applies_them(): void
    {
        $this->postPunches($this->fullDay54('2026-11-02'))->assertOk();

        // The Sync Users commit creates employees with Employee::create, as the page does.
        $this->actingAs($this->uploader);
        $hire = Employee::create(['zkteco_id' => '54', 'full_name' => 'From user.dat']);

        $this->assertSame(4, DB::table('attendance_logs')->where('employee_id', $hire->id)->count());
        $this->assertNotNull($this->day($hire, '2026-11-02'));
        $run = AttendanceImportRun::orderByDesc('id')->first();
        $this->assertSame($this->uploader->id, (int) $run->uploaded_by, 'the run belongs to the person who added the employee');
    }

    #[TestDox('S-012.7 creating a different ID applies nothing')]
    public function test_s_012_7_other_id_applies_nothing(): void
    {
        $this->postPunches($this->fullDay54('2026-11-02'))->assertOk();
        $runs = AttendanceImportRun::count();

        $this->newHire('55');

        $this->assertSame(0, DB::table('attendance_logs')->count());
        $this->assertCount(4, $this->feedRows('held'));
        $this->assertSame($runs, AttendanceImportRun::count());
    }

    #[TestDox('S-012.7 held punches respect date locks: saved, the locked date not computed')]
    public function test_s_012_7_held_punches_respect_locks(): void
    {
        $this->postPunches(array_merge($this->fullDay54('2026-11-02'), $this->fullDay54('2026-11-03')))->assertOk();
        $this->lock('2026-11-02');

        $hire = $this->newHire();

        $this->assertSame(8, DB::table('attendance_logs')->where('employee_id', $hire->id)->count());
        $this->assertNull($this->day($hire, '2026-11-02'));
        $this->assertNotNull($this->day($hire, '2026-11-03'));
        $run = AttendanceImportRun::orderByDesc('id')->first();
        $this->assertSame(1, $run->stats_json['compute']['locked_skipped']);
    }

    #[TestDox('S-012.7 held punches are de-duplicated: posted every minute, they are held once and stored once')]
    public function test_s_012_7_held_punches_are_deduplicated(): void
    {
        $lines = array_merge($this->fullDay54('2026-11-02'), [$this->punch('54', '2026-11-02', '08:01:00')]);
        $this->postPunches($lines)->assertOk();
        $again = $this->postPunches($lines)->assertOk();

        $this->assertSame(0, $again->json('held'), 'nothing new is held the second time');
        $this->assertCount(5, $this->feedRows('held'));

        $hire = $this->newHire();
        // 08:01 is inside the 3-minute window of 08:00: the import path drops it, as for a file.
        $this->assertSame(4, DB::table('attendance_logs')->where('employee_id', $hire->id)->count());
        $this->assertSame([], $this->feedRows('held'));
    }

    #[TestDox('S-012.7 held punches that match punches stored since are not stored twice')]
    public function test_s_012_7_held_punches_match_stored_ones(): void
    {
        $this->postPunches($this->fullDay54('2026-11-02'))->assertOk();
        $hire = $this->newHire();
        $this->assertSame(4, DB::table('attendance_logs')->count());

        // The agent re-reads the same punches a minute later: now they match the employee.
        $again = $this->postPunches($this->fullDay54('2026-11-02'))->assertOk();
        $this->assertSame(4, $again->json('duplicates'));
        $this->assertSame(0, $again->json('held'));
        $this->assertSame(4, DB::table('attendance_logs')->where('employee_id', $hire->id)->count());
    }

    #[TestDox('S-012.7 a held punch whose employee was added while the ingest was busy is applied by the next post')]
    public function test_s_012_7_next_post_applies_held_punches_of_existing_employees(): void
    {
        $this->postPunches($this->fullDay54('2026-11-02'))->assertOk();
        // The employee appears without the model event (e.g. the lock was busy at that moment).
        $id = DB::table('employees')->insertGetId([
            'zkteco_id' => '54', 'full_name' => 'Quiet Insert', 'status' => 'active',
            'default_shift_id' => $this->shift->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertCount(4, $this->feedRows('held'));

        $this->postPunches($this->punch('1', self::DAY, '08:00:00'))->assertOk();

        $this->assertSame(4, DB::table('attendance_logs')->where('employee_id', $id)->count());
        $this->assertSame([], $this->feedRows('held'));
    }

    #[TestDox('S-012.7 a manual upload with an unmatched ID holds its punches too, and they apply when HR adds the employee')]
    public function test_s_012_7_manual_upload_holds_unmatched_ids(): void
    {
        $run = $this->process($this->makeRun(array_merge(
            $this->fullDay('1', self::D1),
            array_map(fn ($t) => $this->line('99', self::D1, $t), self::FULL_DAY),
        )));

        $this->assertSame('done', $run->status);
        $this->assertSame(1, $run->stats_json['unmatched_zkteco_ids']);
        $this->assertSame(4, $run->stats_json['held_punches']);
        $this->assertCount(4, $this->feedRows('held'));

        $hire = $this->newHire('99');
        $this->assertSame(4, DB::table('attendance_logs')->where('employee_id', $hire->id)->count());
        $this->assertNotNull($this->day($hire, self::D1));
    }

    #[TestDox('S-012.7 the upload preview lets an unmatched ID through and says its punches will be held')]
    public function test_s_012_7_preview_commit_no_longer_blocks_unmatched_ids(): void
    {
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('attlog.dat', implode("\r\n", array_merge(
            $this->fullDay('1', '2026-11-02'),
            array_map(fn ($t) => $this->line('99', '2026-11-02', $t), self::FULL_DAY),
        )) . "\r\n");

        $this->actingAs($this->uploader)->post('/import/upload', ['attlog_dat' => $file])->assertRedirect('/import/preview');
        $preview = $this->actingAs($this->uploader)->get('/import/preview?date_from=2026-11-01&date_to=2026-11-05')->assertOk();
        $preview->assertSee('held until');

        $this->actingAs($this->uploader)->post('/import/preview/commit', [
            'date_from' => '2026-11-01', 'date_to' => '2026-11-05', 'include_zkteco' => ['1', '99'],
        ])->assertRedirect('/import');

        $this->assertSame(4, $this->punchCount($this->a, '2026-11-02'));
        $this->assertCount(4, $this->feedRows('held'));
    }
}
