<?php

namespace Tests\Feature\Approvals;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-19: only the CEO approves, checked on the server at every call.
 */
class ApprovalSecurityTest extends ApprovalsTestCase
{
    #[TestDox('S-19.1 HR staff posting approve, reject or approve-several is refused and nothing changes')]
    public function test_s_19_1_hr_staff_cannot_approve(): void
    {
        $this->askFourTimes()->assertStatus(200);
        $officialBefore = $this->official();
        $id = $this->anchorId($this->e1, self::DN);
        $seen = $this->seen($this->e1, self::DN);

        $this->actingAs($this->h)->postJson("/approvals/{$id}/approve", ['seen' => $seen])->assertStatus(403);
        $this->actingAs($this->h)->postJson("/approvals/{$id}/reject", ['seen' => $seen, 'reason' => 'Hindi tama'])->assertStatus(403);
        $this->actingAs($this->h)->postJson('/approvals/approve-several', ['entries' => [['id' => $id, 'seen' => $seen]]])->assertStatus(403);
        $this->actingAs($this->h2)->postJson("/approvals/{$id}/approve", ['seen' => $seen])->assertStatus(403);

        $this->assertCount(4, $this->pending($this->e1, self::DN));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-19.2 an admin posting approve or reject is refused and nothing changes')]
    public function test_s_19_2_admin_cannot_approve(): void
    {
        $this->askFourTimes();
        $officialBefore = $this->official();

        $this->approve($this->m, $this->e1, self::DN)->assertStatus(403);
        $this->reject($this->m, $this->e1, self::DN)->assertStatus(403);
        $this->actingAs($this->m)->postJson('/approvals/approve-several', [
            'entries' => [['id' => $this->anchorId($this->e1, self::DN), 'seen' => $this->seen($this->e1, self::DN)]],
        ])->assertStatus(403);

        $this->assertCount(4, $this->pending($this->e1, self::DN));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-19.3 a signed-out caller is sent to sign in and nothing changes')]
    public function test_s_19_3_signed_out_caller(): void
    {
        $this->askFourTimes();
        $officialBefore = $this->official();
        $id = $this->anchorId($this->e1, self::DN);
        $seen = $this->seen($this->e1, self::DN);
        auth()->logout();

        $this->postJson("/approvals/{$id}/approve", ['seen' => $seen])->assertStatus(401);
        $this->post("/approvals/{$id}/approve", ['seen' => $seen])->assertRedirect('/login');
        $this->postJson("/approvals/{$id}/reject", ['seen' => $seen, 'reason' => 'Hindi tama'])->assertStatus(401);
        $this->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(401);
        $this->get('/approvals')->assertRedirect('/login');

        $this->assertCount(4, $this->pending($this->e1, self::DN));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-19.5 a requester who became CEO cannot approve an entry holding their own request')]
    public function test_s_19_5_own_request_cannot_be_approved(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $this->saveAll($this->h2, $this->e1, self::DN, ['time_out' => '17:00']);
        DB::table('users')->where('id', $this->h->id)->update(['role' => 'ceo']);
        $promoted = User::find($this->h->id);
        $officialBefore = $this->official();

        $response = $this->approve($promoted, $this->e1, self::DN);

        $response->assertStatus(403);
        $this->assertStringContainsString('cannot approve your own request', $response->json('message'));
        $this->assertCount(2, $this->pending($this->e1, self::DN));
        $this->assertSame($officialBefore, $this->official());

        // Their own edits are direct now (S-08.4), and another CEO can approve the entry.
        $this->saveAll($promoted, $this->e2, self::DN, ['time_in' => '08:00'])->assertJsonMissing(['pending' => true]);
        $this->assertNotNull($this->day($this->e2, self::DN));
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->assertSame(2, $this->requestCount('approved'));
    }

    #[TestDox('S-19.6 an id that does not exist is never an error 500: 403 for HR staff as for a real id, 404 for the CEO')]
    public function test_s_19_6_unknown_id(): void
    {
        // HR staff get the same answer as for an id that exists, so the address tells them nothing.
        $this->actingAs($this->h)->postJson('/approvals/999999/approve', ['seen' => '999999:1'])->assertStatus(403);
        $this->actingAs($this->h)->postJson('/approvals/999999/reject', ['seen' => '999999:1', 'reason' => 'Hindi tama'])->assertStatus(403);
        $this->actingAs($this->c)->postJson('/approvals/999999/approve', ['seen' => '999999:1'])->assertStatus(404);
        $this->actingAs($this->c)->postJson('/approvals/999999/reject', ['seen' => '999999:1', 'reason' => 'Hindi tama'])->assertStatus(404);

        foreach ([$this->h, $this->c] as $user) {
            $this->actingAs($user)->postJson('/attendance-requests/999999/withdraw')->assertStatus(404);
            $this->actingAs($user)->postJson('/attendance-requests/999999/change', ['new_value' => '08:00', 'reason' => 'Hindi tama'])->assertStatus(404);
        }
        $this->actingAs($this->c)->postJson('/approvals/not-a-number/approve', ['seen' => 'x'])->assertStatus(404);
    }

    #[TestDox('S-19.7 the role is read at the call: a demoted or removed user is refused')]
    public function test_s_19_7_role_is_read_at_each_call(): void
    {
        $this->askFourTimes();
        $officialBefore = $this->official();
        $second = User::factory()->create(['role' => 'ceo', 'name' => 'Second Ceo']);

        // Demoted after the page was loaded. The user object still says "ceo", as a
        // page loaded earlier would; the stored role is what counts.
        DB::table('users')->where('id', $second->id)->update(['role' => 'hr_staff']);
        $this->approve($second, $this->e1, self::DN)->assertStatus(403);
        $this->reject($second, $this->e1, self::DN)->assertStatus(403);
        $this->actingAs($second)->get('/approvals')->assertStatus(403);

        // Removed: the session no longer names a user.
        $id = $this->anchorId($this->e1, self::DN);
        $seen = $this->seen($this->e1, self::DN);
        auth()->logout();
        DB::table('users')->where('id', $second->id)->delete();
        $this->postJson("/approvals/{$id}/approve", ['seen' => $seen])->assertStatus(401);

        $this->assertCount(4, $this->pending($this->e1, self::DN));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-19.8 a post without the CSRF token answers 419 and changes nothing')]
    public function test_s_19_8_missing_csrf_token(): void
    {
        $this->askFourTimes();
        $officialBefore = $this->official();
        $id = $this->anchorId($this->e1, self::DN);
        $seen = $this->seen($this->e1, self::DN);

        // The framework skips the token check while tests run; switch it back on.
        $this->app['env'] = 'production';
        try {
            $this->actingAs($this->c)->post("/approvals/{$id}/approve", ['seen' => $seen])->assertStatus(419);
            $this->actingAs($this->c)->post("/approvals/{$id}/reject", ['seen' => $seen, 'reason' => 'Hindi tama'])->assertStatus(419);
            $this->actingAs($this->h)->post("/attendance-requests/{$id}/withdraw")->assertStatus(419);
            $this->actingAs($this->h)->post('/attendance-calendar/save-all', [
                'employee_id' => $this->e2->id, 'date' => self::DN, 'reason' => self::REASON, 'time_in' => '08:00',
            ])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertCount(4, $this->pending($this->e1, self::DN));
        $this->assertSame(4, $this->requestCount());
        $this->assertSame($officialBefore, $this->official());
    }
}
