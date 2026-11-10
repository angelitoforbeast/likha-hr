<?php

namespace Tests\Feature\Biometrics;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-012.13 (P2) The device user list is shown, never applied to employees.
 */
class DeviceUsersTest extends BiometricsTestCase
{
    #[TestDox('S-012.13 new device users are listed; known employees are never changed or created')]
    public function test_s_012_13_new_users_listed_known_untouched(): void
    {
        $employees = DB::table('employees')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $response = $this->postUsers([
            ['user_id' => '1', 'name' => 'Device Name For A'],
            ['user_id' => '54', 'name' => 'Juan Peña'],
            ['user_id' => '55', 'name' => 'ÑANDO'],
        ])->assertOk();

        $this->assertSame(3, $response->json('device_users'));
        $this->assertSame(2, $response->json('not_in_hr'));
        $this->assertSame($employees, DB::table('employees')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame(['54|Juan Peña', '55|ÑANDO'], $this->feedRows('device_user'));

        $html = $this->actingAs($this->uploader)->get('/import')->assertOk()->getContent();
        $this->assertStringContainsString('on the device, not in Likha HR yet', $html);
        $this->assertStringContainsString('Juan Peña', $html);
        $this->assertStringContainsString('ÑANDO', $html);
        $this->assertStringNotContainsString('Device Name For A', $html);
    }

    #[TestDox('S-012.13 a device user removed from the device leaves the list and never touches the employee')]
    public function test_s_012_13_deleted_device_user(): void
    {
        $this->postUsers([['user_id' => '1', 'name' => 'A'], ['user_id' => '54', 'name' => 'Juan Peña']])->assertOk();
        $this->postUsers([['user_id' => '2', 'name' => 'B']])->assertOk();

        $this->assertSame([], $this->feedRows('device_user'));
        $this->assertNotNull(DB::table('employees')->where('zkteco_id', '1')->first());
        $this->assertSame('Employee A', DB::table('employees')->where('zkteco_id', '1')->value('full_name'));
    }

    #[TestDox('S-012.13 once HR adds the employee, the device user is no longer listed as missing')]
    public function test_s_012_13_listed_until_hr_adds_them(): void
    {
        $this->postUsers([['user_id' => '54', 'name' => 'Juan Peña']])->assertOk();
        \App\Models\Employee::create(['zkteco_id' => '54', 'full_name' => 'Juan Peña', 'status' => 'active']);

        $html = $this->actingAs($this->uploader)->get('/import')->assertOk()->getContent();
        $this->assertStringNotContainsString('Juan Peña', $html);
    }

    #[TestDox('S-012.13 a name is shown escaped; a junk user id or an over-long list is refused')]
    public function test_s_012_13_untrusted_names_and_ids(): void
    {
        $this->postUsers([['user_id' => '56', 'name' => '<script>alert(1)</script>']])->assertOk();
        $html = $this->actingAs($this->uploader)->get('/import')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);

        $this->postUsers([['user_id' => 'abc', 'name' => 'X']])->assertStatus(422);
        $this->postUsers([['user_id' => '57', 'name' => str_repeat('x', 200)]])->assertStatus(422);
        $many = [];
        for ($i = 1; $i <= 5001; $i++) {
            $many[] = ['user_id' => (string) (1000 + $i), 'name' => 'U' . $i];
        }
        $this->postUsers($many)->assertStatus(422);
        $this->assertSame(['56|<script>alert(1)</script>'], $this->feedRows('device_user'));
    }
}
