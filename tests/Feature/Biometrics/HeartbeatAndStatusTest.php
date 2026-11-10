<?php

namespace Tests\Feature\Biometrics;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-012.9 The heartbeat and the feed health on the Import page and the status endpoint.
 * S-012.11 The record-count warnings. S-012.6 The clock drift shown.
 */
class HeartbeatAndStatusTest extends BiometricsTestCase
{
    private function at(string $manila): void
    {
        Carbon::setTestNow(Carbon::parse($manila, 'Asia/Manila'));
    }

    private function page(): string
    {
        return $this->actingAs($this->uploader)->get('/import')->assertOk()->getContent();
    }

    private function codes(): array
    {
        return array_column($this->feedStatus()->assertOk()->json('warnings'), 'code');
    }

    #[TestDox('S-012.9 last contact is stored in the database and shown in Manila time')]
    public function test_s_012_9_last_contact_is_stored_and_shown_in_manila_time(): void
    {
        $this->at('2026-11-10 09:41:07');
        $this->heartbeat()->assertOk()
            ->assertJsonPath('server_time', '2026-11-10T01:41:07.000Z');

        $this->assertDatabaseHas('biometric_feed', ['kind' => 'state', 'key' => 'last_contact']);
        $this->at('2026-11-10 09:42:00');
        $this->assertSame('2026-11-10T01:41:07.000Z', $this->feedStatus()->json('last_contact_at'));
        $this->assertStringContainsString('Nov 10, 2026 9:41 AM', $this->page());
    }

    #[TestDox('S-012.9 a wrong key does not update last contact')]
    public function test_s_012_9_wrong_key_does_not_update_last_contact(): void
    {
        $this->at('2026-11-10 09:00:00');
        $this->heartbeat()->assertOk();
        $this->at('2026-11-10 09:30:00');
        $this->heartbeat([], 'wrong-key')->assertStatus(401);
        $this->heartbeat(['device_record_count' => 999], 'wrong-key')->assertStatus(401);

        $this->assertSame('2026-11-10T01:00:00.000Z', $this->feedStatus()->json('last_contact_at'));
        $this->assertSame(120, $this->feedStatus()->json('device_record_count'));
    }

    #[TestDox('S-012.9 a warning after 10 minutes of silence between 08:00 and 20:00, gone when contact returns')]
    public function test_s_012_9_silence_warning_in_the_day(): void
    {
        $this->at('2026-11-10 09:00:00');
        $this->heartbeat()->assertOk();

        $this->at('2026-11-10 09:10:00');
        $this->assertNotContains('no_contact', $this->codes(), 'exactly 10 minutes is not yet a warning');

        $this->at('2026-11-10 09:10:01');
        $this->assertContains('no_contact', $this->codes());
        $this->assertStringContainsString('No contact since 09:00', $this->page());

        $this->heartbeat()->assertOk();
        $this->assertNotContains('no_contact', $this->codes());
        $this->assertStringNotContainsString('No contact since', $this->page());
    }

    #[TestDox('S-012.9 no silence warning at night; it shows again at 08:00')]
    public function test_s_012_9_no_warning_at_night(): void
    {
        $this->at('2026-11-10 19:00:00');
        $this->heartbeat()->assertOk();

        $this->at('2026-11-10 20:00:01');
        $this->assertNotContains('no_contact', $this->codes());
        $this->at('2026-11-11 03:00:00');
        $this->assertNotContains('no_contact', $this->codes());
        $this->assertStringNotContainsString('No contact since', $this->page());

        $this->at('2026-11-11 07:59:59');
        $this->assertNotContains('no_contact', $this->codes());
        $this->at('2026-11-11 08:00:00');
        $this->assertContains('no_contact', $this->codes());
        $this->assertStringContainsString('No contact since 19:00', $this->page());
    }

    #[TestDox('S-012.9 the agent alive but the device unreachable: both facts are shown')]
    public function test_s_012_9_agent_alive_device_unreachable(): void
    {
        $this->heartbeat(['device_reachable' => false, 'device_record_count' => null])->assertOk();

        $status = $this->feedStatus()->json();
        $this->assertFalse($status['device_reachable']);
        $this->assertNotContains('no_contact', array_column($status['warnings'], 'code'));
        $this->assertContains('device_unreachable', array_column($status['warnings'], 'code'));

        $html = $this->page();
        $this->assertStringContainsString('Office PC: in contact', $html);
        $this->assertStringContainsString('Device: not reachable', $html);
    }

    #[TestDox('S-012.11 at 40,000 records a warning, at 50,000 a louder one')]
    public function test_s_012_11_record_count_warnings(): void
    {
        $this->heartbeat(['device_record_count' => 39999])->assertOk();
        $this->assertSame([], array_values(array_intersect(['device_records_high', 'device_records_full'], $this->codes())));
        $this->assertStringContainsString('39,999 / 50,000', $this->page());

        $this->heartbeat(['device_record_count' => 40000])->assertOk();
        $this->assertContains('device_records_high', $this->codes());
        $this->assertStringContainsString('alert-warning', $this->feedPanel());

        $this->heartbeat(['device_record_count' => 50000])->assertOk();
        $this->assertContains('device_records_full', $this->codes());
        $this->assertNotContains('device_records_high', $this->codes());
        $this->assertStringContainsString('alert-danger', $this->feedPanel());
    }

    #[TestDox('S-012.6 PC 2 minutes off: the drift is stored, shown and warned; within 60 s no warning')]
    public function test_s_012_6_clock_drift_is_shown(): void
    {
        $this->heartbeat(['clock_drift_seconds' => 120, 'clock_set' => false])->assertOk();
        $this->assertSame(120, $this->feedStatus()->json('clock_drift_seconds'));
        $this->assertContains('clock_not_set', $this->codes());
        $this->assertStringContainsString('+120 s', $this->page());

        $this->heartbeat(['clock_drift_seconds' => -60, 'clock_set' => true])->assertOk();
        $this->assertNotContains('clock_not_set', $this->codes());
        $this->assertSame(['2026-11-10|-60 set'], $this->feedRows('drift'), 'the drift is logged once a day');
    }

    #[TestDox('S-012.9 the status endpoint is read-only and carries no names or punch lists')]
    public function test_s_012_9_status_is_read_only_and_has_no_names(): void
    {
        $this->postUsers([['user_id' => '77', 'name' => 'Secret Person']])->assertOk();
        // A held punch at 07:31:13 and a stored one later: only the latest time is reported.
        $this->postPunches([$this->punch('54', '2026-11-01', '07:31:13'), $this->punch('1', self::DAY, '08:00:00')])->assertOk();
        $before = $this->writeCounts() + ['state' => \DB::table('biometric_feed')->where('kind', 'state')->orderBy('id')->get()->all()];
        $contact = $this->feedStatus()->json('last_contact_at');

        $this->travel(5)->minutes();
        $body = $this->feedStatus()->assertOk()->getContent();

        $this->assertEquals($before, $this->writeCounts() + ['state' => \DB::table('biometric_feed')->where('kind', 'state')->orderBy('id')->get()->all()]);
        $this->assertSame($contact, $this->feedStatus()->json('last_contact_at'), 'reading the status is not a contact');
        $this->assertStringNotContainsString('Secret Person', $body);
        $this->assertStringNotContainsString('Employee A', $body);
        $this->assertStringNotContainsString('07:31:13', $body);
        $this->assertStringNotContainsString('"54"', $body);
        $this->assertSame('2026-11-02 08:00:00', json_decode($body, true)['last_punch_at']);
        foreach (['server_time', 'last_punch_at', 'last_contact_at', 'device_reachable', 'device_record_count',
                  'device_capacity', 'held_count', 'refused_count', 'clock_drift_seconds', 'warnings'] as $key) {
            $this->assertArrayHasKey($key, json_decode($body, true));
        }
    }

    #[TestDox('S-012.9 a malformed call (a bad agent id) is not a contact: a misconfigured agent never looks healthy')]
    public function test_s_012_9_malformed_call_is_not_a_contact(): void
    {
        $this->heartbeat(['agent_id' => 'Office PC'])->assertStatus(422);
        $this->postPunches($this->punch('1', self::DAY, '08:00:00'), self::KEY, ['agent_id' => 'Office PC'])->assertStatus(422);
        $this->postUsers([['user_id' => 'x', 'name' => 'X']])->assertStatus(422);

        $this->assertNull($this->feedStatus()->json('last_contact_at'));
        $this->assertSame(0, \DB::table('attendance_logs')->count());
    }

    #[TestDox('S-012.9 bad heartbeat values are refused with 422 and change nothing')]
    public function test_s_012_9_bad_heartbeat_values(): void
    {
        $this->heartbeat()->assertOk();
        foreach ([
            ['device_record_count' => -1],
            ['device_record_count' => 'lots'],
            ['device_reachable' => 'maybe'],
            ['clock_drift_seconds' => 1e12],
            ['agent_version' => str_repeat('x', 100)],
            ['agent_id' => '<script>'],
        ] as $bad) {
            $this->heartbeat($bad)->assertStatus(422);
        }
        $this->assertSame(120, $this->feedStatus()->json('device_record_count'));
    }

    #[TestDox('S-012.9 the panel uses the Import page permission: a user without it cannot see it')]
    public function test_s_012_9_panel_needs_the_import_permission(): void
    {
        $this->heartbeat()->assertOk();
        // hr_staff has no Import permission by default; admin has.
        $hr = \App\Models\User::factory()->create(['role' => 'hr_staff']);
        $this->actingAs($hr)->get('/import')->assertStatus(403);

        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->get('/import')->assertOk()->getContent();
        $this->assertStringContainsString('id="biometrics-feed"', $html);
    }

    #[TestDox('S-012.9 agent runs that stored punches are listed for an admin too, as "Biometric agent"')]
    public function test_s_012_9_agent_runs_are_listed_for_admins(): void
    {
        $runId = $this->postPunches($this->punch('1', self::DAY, '08:00:00'))->json('run_id');
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get('/import')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-run-id="' . $runId . '".*?Biometric agent \(office-pc\)/s', $html);
    }

    private function feedPanel(): string
    {
        $html = $this->page();
        $this->assertSame(1, preg_match('/<div id="biometrics-feed".*?<!-- \/biometrics-feed -->/s', $html, $m));

        return $m[0];
    }
}
