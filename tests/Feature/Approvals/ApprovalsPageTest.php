<?php

namespace Tests\Feature\Approvals;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-09 (calendar) and S-10 (without the P2 filters): what the calendar shows
 * for a pending day and what the CEO sees on the Approvals page and in the nav.
 * These cases are checked on the rendered HTML; no test renders a real screen.
 */
class ApprovalsPageTest extends ApprovalsTestCase
{
    /* ── S-09 The calendar shows pending ── */

    #[TestDox('S-09.1 the cell of a requested day says pending and carries what was asked')]
    public function test_s_09_1_cell_is_marked_pending(): void
    {
        $this->askFourTimes();

        $html = $this->calendar($this->h);
        $cell = $this->cell($html, $this->e1, self::DN);

        $this->assertStringContainsString('cal-cell-pending', $cell);
        $this->assertStringContainsString('data-pending-count="4"', $cell);
        $this->assertStringContainsString('data-status="absent"', $cell, 'the day is still officially absent');
        $this->assertStringNotContainsString('data-att-id', $cell);
        $this->assertStringContainsString('8:00 AM', $cell);
        $this->assertStringContainsString('5:00 PM', $cell);
        $this->assertStringContainsString(self::REASON, $cell);
        $this->assertStringContainsString('Hr One', $cell);
        $this->assertStringContainsString('"asked_at":"', $cell);
        $this->assertStringContainsString('Pending approval', $html);
    }

    #[TestDox('S-09.2 a pending change on a day with a row shows the official values with a marker')]
    public function test_s_09_2_official_values_with_a_marker(): void
    {
        $before = $this->cell($this->calendar($this->h), $this->e1, self::DR);
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);

        $cell = $this->cell($this->calendar($this->h), $this->e1, self::DR);

        $this->assertStringContainsString('data-pending-count="1"', $cell);
        $this->assertStringContainsString('data-time-in="08:03:00"', $cell);
        $this->assertStringContainsString('"requested":"8:00 AM"', $cell);
        $this->assertStringContainsString('"official":"8:03 AM"', $cell);
        foreach (['data-status', 'data-work', 'data-late', 'data-early', 'data-ot'] as $attribute) {
            preg_match('/' . $attribute . '="[^"]*"/', $before, $was);
            $this->assertStringContainsString($was[0], $cell, $attribute . ' must be that of the day row');
        }
    }

    #[TestDox('S-09.3 the present, absent and undertime counts do not change with a pending request')]
    public function test_s_09_3_summaries_count_official_days_only(): void
    {
        $before = $this->rowSummary($this->calendar($this->h), $this->e1);

        $this->askFourTimes();
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00', 'time_out' => '19:00']);

        $this->assertSame($before, $this->rowSummary($this->calendar($this->h), $this->e1));
        $this->assertCount(3, $before);
    }

    #[TestDox('S-09.4 other employees and other dates are not marked')]
    public function test_s_09_4_only_the_requested_day_is_marked(): void
    {
        $this->punchedDay($this->e2, self::DR, ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->askFourTimes();

        $html = $this->calendar($this->h);

        $this->assertSame(1, substr_count($html, 'data-pending-count='));
        $this->assertStringNotContainsString('data-pending-count', $this->cell($html, $this->e2, self::DN));
        $this->assertStringNotContainsString('data-pending-count', $this->cell($html, $this->e1, self::DR));
        $this->assertStringNotContainsString('data-pending-count', $this->cell($html, $this->e1, self::DX));
    }

    #[TestDox('S-09.6 the pending state is in the HTML the server sends')]
    public function test_s_09_6_pending_state_comes_from_the_server(): void
    {
        $this->askFourTimes();

        // Two separate loads, no script run: the marker is there both times.
        $this->assertStringContainsString('data-pending-count="4"', $this->cell($this->calendar($this->h), $this->e1, self::DN));
        $this->assertStringContainsString('data-pending-count="4"', $this->cell($this->calendar($this->m), $this->e1, self::DN));
    }

    #[TestDox('S-09.7 after a decision the marker is gone: approved shows the times, rejected shows the reason')]
    public function test_s_09_7_marker_goes_after_a_decision(): void
    {
        $this->askFourTimes();
        $this->approve($this->c, $this->e1, self::DN);
        $cell = $this->cell($this->calendar($this->h), $this->e1, self::DN);
        $this->assertStringNotContainsString('data-pending-count', $cell);
        $this->assertStringContainsString('data-time-out="17:00:00"', $cell);

        $this->askFourTimes($this->h, $this->e2);
        $this->reject($this->c, $this->e2, self::DN, 'Walang katibayan');
        $cell = $this->cell($this->calendar($this->h), $this->e2, self::DN);
        $this->assertStringNotContainsString('data-pending-count', $cell);
        $this->assertStringContainsString('Walang katibayan', $cell);

        $this->saveAll($this->h, $this->e1, self::DX, ['time_in' => '08:00']);
        $this->actingAs($this->h)->postJson('/attendance-requests/' . $this->anchorId($this->e1, self::DX) . '/withdraw');
        $this->assertStringNotContainsString('data-pending-count', $this->cell($this->calendar($this->h), $this->e1, self::DX));
    }

    #[TestDox('S-09.8 a request made at 23:30 Manila time is on the work date asked for and shows Manila time')]
    public function test_s_09_8_late_evening_request(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-09 23:30:00', 'Asia/Manila'));

        $this->askFourTimes();
        $this->travelBack();

        $row = $this->pending($this->e1, self::DN)['time_in'];
        $this->assertSame(self::DN, $row['work_date']);
        $this->assertSame('2026-10-09 23:30:00', $row['created_at']);
        $cell = $this->cell($this->calendar($this->h), $this->e1, self::DN);
        $this->assertStringContainsString('"asked_at":"Oct 09, 2026 11:30 PM"', $cell);
        $this->assertStringNotContainsString('data-pending-count', $this->cell($this->calendar($this->h), $this->e1, '2026-10-09'));
    }

    #[TestDox('S-09.9 the marker is text in the cell and the modal panel wraps, for a phone width')]
    public function test_s_09_9_marker_for_a_phone(): void
    {
        $this->askFourTimes();

        $html = $this->calendar($this->h);

        $this->assertStringContainsString('name="viewport"', $html);
        preg_match('/<td class="[^"]*"\s+data-date="' . self::DN . '"[^>]*data-employee-id="' . $this->e1->id . '"/', $html, $m, PREG_OFFSET_CAPTURE);
        $start = $m[0][1];
        $inside = substr($html, $start, strpos($html, '</td>', $start) - $start);
        $this->assertStringContainsString('cal-pending-mark', $inside, 'the cell holds a visible marker, not only a colour');
        $this->assertStringContainsString('id="dPendingPanel"', $html);
    }

    /* ── S-10 The CEO sees what is waiting ── */

    #[TestDox('S-10.1 the nav shows the count of entries and the list shows each entry in full')]
    public function test_s_10_1_nav_count_and_list(): void
    {
        $this->threeEntries();

        $page = $this->actingAs($this->c)->get('/approvals')->assertStatus(200);
        $html = $page->getContent();

        $this->assertStringContainsString('id="navApprovalsCount">3<', $html);
        $this->assertSame(3, substr_count($html, 'class="approval-entry'));
        $page->assertSee('Employee One')->assertSee('Employee Two')
            ->assertSee('Oct 06, 2026')->assertSee('Oct 05, 2026')
            ->assertSee('Time In')->assertSee('Time Out')
            ->assertSee('8:00 AM')->assertSee('8:03 AM')
            ->assertSee(self::REASON)->assertSee('Hr One')->assertSee('Admin Tester');
        $this->assertMatchesRegularExpression('/Asked[^<]*Oct \d{2}, 2026/', $html);
        $this->actingAs($this->c)->get('/dashboard')->assertSee('id="navApprovalsCount">3<', false);
    }

    #[TestDox('S-10.2 after a decision the count is one less and the entry is gone from the list')]
    public function test_s_10_2_count_follows_decisions(): void
    {
        $this->threeEntries();

        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);

        $html = $this->actingAs($this->c)->get('/approvals')->getContent();
        $this->assertStringContainsString('id="navApprovalsCount">2<', $html);
        $this->assertSame(2, substr_count($html, 'class="approval-entry'));

        $this->reject($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->assertStringContainsString('id="navApprovalsCount">1<', $this->actingAs($this->c)->get('/approvals')->getContent());
    }

    #[TestDox('S-10.3 HR staff and admins have no Approvals link, no count, and get 403 on the address')]
    public function test_s_10_3_other_roles_see_nothing(): void
    {
        $this->threeEntries();

        foreach ([$this->h, $this->h2, $this->m] as $user) {
            $this->actingAs($user)->get('/approvals')->assertStatus(403);
            $html = $this->calendar($user);
            $this->assertStringNotContainsString('navApprovalsCount', $html);
            $this->assertStringNotContainsString('/approvals', $html);
        }
    }

    #[TestDox('S-10.4 with nothing pending the page says so and the nav has no badge')]
    public function test_s_10_4_nothing_waiting(): void
    {
        $page = $this->actingAs($this->c)->get('/approvals')->assertStatus(200);

        $page->assertSee('Nothing is waiting for approval');
        $page->assertDontSee('navApprovalsCount');
        $page->assertSee('Approvals');
    }

    #[TestDox('S-10.5 a feature permission never opens Approvals to another role')]
    public function test_s_10_5_feature_permissions_do_not_open_approvals(): void
    {
        $this->threeEntries();
        // Every existing permission granted, plus rows named after this page.
        DB::table('feature_permissions')->whereIn('role', ['hr_staff', 'admin'])->update(['can_view' => true, 'can_edit' => true]);
        foreach (['hr_staff', 'admin'] as $role) {
            foreach (['nav_approvals', 'approvals'] as $key) {
                DB::table('feature_permissions')->insert([
                    'feature_key' => $key, 'feature_label' => 'Approvals', 'role' => $role,
                    'can_view' => true, 'can_edit' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach ([$this->h, $this->m] as $user) {
            $this->actingAs($user)->get('/approvals')->assertStatus(403);
            $this->assertStringNotContainsString('/approvals', $this->calendar($user));
        }
    }

    #[TestDox('S-10.6 the count is the total of pending entries, also beyond one page, and flags locked and inactive')]
    public function test_s_10_6_count_is_the_total(): void
    {
        $extra = [];
        for ($i = 0; $i < 52; $i++) {
            $extra[] = $this->employee((string) (1000 + $i), 'Extra ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'active');
        }
        foreach ($extra as $employee) {
            $this->saveAll($this->h, $employee, self::DN, ['time_in' => '08:00']);
        }
        // Not counted: approved, rejected, withdrawn.
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $this->approve($this->c, $this->e1, self::DR);
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $this->reject($this->c, $this->e1, self::DN);
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);
        $this->actingAs($this->h)->postJson('/attendance-requests/' . $this->anchorId($this->e2, self::DN) . '/withdraw');
        // Counted and flagged: a date locked afterwards, an employee deactivated afterwards.
        $this->saveAll($this->h, $this->e2, self::DX, ['time_in' => '08:00']);
        $this->lock(self::DX);
        DB::table('employees')->where('id', $extra[0]->id)->update(['status' => 'inactive']);

        $html = $this->actingAs($this->c)->get('/approvals')->assertStatus(200)->getContent();

        $this->assertStringContainsString('id="navApprovalsCount">53<', $html);
        $this->assertSame(50, substr_count($html, 'class="approval-entry'), 'one page shows 50 entries');
        $this->assertStringContainsString('Inactive employee', $html);
        // The entry on the locked date is the newest, so it is on the second page.
        $second = $this->actingAs($this->c)->get('/approvals?page=2')->getContent();
        $this->assertSame(3, substr_count($second, 'class="approval-entry'));
        $this->assertStringContainsString('Date locked', $second);
        $this->assertStringContainsString('id="navApprovalsCount">53<', $second);
    }

    #[TestDox('S-10.8 names with Ñ and reasons with markup show as plain text and long words wrap')]
    public function test_s_10_8_text_is_escaped(): void
    {
        $employee = $this->employee('950', 'Niño Peñaflor', 'active');
        $reason = '<script>alert(1)</script> ' . str_repeat('W', 300);
        $this->saveAll($this->h, $employee, self::DN, ['time_in' => '08:00'], $reason);

        $html = $this->actingAs($this->c)->get('/approvals')->assertStatus(200)->getContent();

        $this->assertStringContainsString('Niño Peñaflor', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertMatchesRegularExpression('/\.approval-entry[^{]*\{[^}]*overflow-wrap:\s*anywhere/', $html);

        $calendar = $this->calendar($this->h);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $calendar);
    }

    #[TestDox('S-10.9 each entry is a stacked card with its own approve and reject controls')]
    public function test_s_10_9_entries_are_cards_with_controls(): void
    {
        $this->threeEntries();

        $html = $this->actingAs($this->c)->get('/approvals')->getContent();

        $this->assertStringContainsString('name="viewport"', $html);
        $list = substr($html, strpos($html, 'id="approvalList"'));
        $this->assertStringNotContainsString('<table', substr($list, 0, strpos($list, 'id="approvalListEnd"')), 'entries are cards, not a wide table');
        $this->assertSame(3, substr_count($html, 'class="btn btn-success approval-approve'));
        $this->assertSame(3, substr_count($html, 'class="btn btn-outline-danger approval-reject'));
    }

    #[TestDox('S-10.10 loading the page again shows current data')]
    public function test_s_10_10_reload_shows_current_data(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $first = $this->actingAs($this->c)->get('/approvals')->getContent();
        $this->assertStringContainsString('8:00 AM', $first);

        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:45']);
        $second = $this->actingAs($this->c)->get('/approvals');

        $second->assertSee('8:45 AM')->assertDontSee('8:00 AM');
        $second->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $second->headers->get('Cache-Control'));
    }

    #[TestDox('S-10.11 an entry asked by two people shows each field with its own requester')]
    public function test_s_10_11_each_field_with_its_requester(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00'], 'Dahilan ni una');
        $this->saveAll($this->h2, $this->e1, self::DN, ['time_out' => '17:00'], 'Dahilan ni pangalawa');

        $html = $this->actingAs($this->c)->get('/approvals')->getContent();

        $this->assertSame(1, substr_count($html, 'class="approval-entry'));
        $this->assertMatchesRegularExpression('/Time In.*?Dahilan ni una.*?Hr One.*?Time Out.*?Dahilan ni pangalawa.*?Hr Two/s', $html);
    }

    #[TestDox('S-10.7 the list filters by status, employee, requester, date range and kind; decided entries show who decided')]
    public function test_s_10_7_filters(): void
    {
        $this->silSetup($this->e2, 5);
        $this->threeEntries();                                            // E1/Dn times (H), E1/Dr times (H), E2/Dn times (admin)
        $this->applySil($this->h2, $this->e2, self::DX);                   // E2/Dx SIL (H2)
        $this->saveAll($this->h, $this->e2, '2026-10-09', ['time_in' => '08:00']);
        $this->approve($this->c, $this->e2, '2026-10-09');                 // approved
        $this->saveAll($this->h2, $this->e1, '2026-10-09', ['time_in' => '08:00']);
        $this->reject($this->c, $this->e1, '2026-10-09', 'Walang katibayan'); // rejected
        $this->saveAll($this->h, $this->e1, '2026-10-12', ['time_in' => '08:00']);
        $this->actingAs($this->h)->postJson('/attendance-requests/' . $this->anchorId($this->e1, '2026-10-12') . '/withdraw'); // withdrawn

        $count = fn (string $query) => substr_count($this->actingAs($this->c)->get('/approvals' . $query)->assertStatus(200)->getContent(), 'class="approval-entry');

        // Default: pending, oldest first.
        $default = $this->actingAs($this->c)->get('/approvals')->getContent();
        $this->assertSame(4, substr_count($default, 'class="approval-entry'));
        $this->assertLessThan(strpos($default, 'Oct 08, 2026'), strpos($default, 'Oct 06, 2026'), 'the oldest request comes first');

        $this->assertSame(4, $count('?status=pending'));
        $this->assertSame(1, $count('?status=approved'));
        $this->assertSame(1, $count('?status=rejected'));
        $this->assertSame(1, $count('?status=withdrawn'));
        $this->assertSame(2, $count('?employee_id=' . $this->e1->id));
        $this->assertSame(2, $count('?requester_id=' . $this->h->id));
        $this->assertSame(1, $count('?requester_id=' . $this->m->id));
        $this->assertSame(1, $count('?date_from=' . self::DX . '&date_to=' . self::DX));
        $this->assertSame(3, $count('?date_from=' . self::DR . '&date_to=' . self::DN));
        $this->assertSame(1, $count('?kind=sil'));
        $this->assertSame(3, $count('?kind=times'));
        $this->assertSame(0, $count('?kind=overtime'));
        $this->assertSame(1, $count('?kind=times&employee_id=' . $this->e2->id));
        $this->assertSame(4, $count('?kind=not-a-kind&employee_id=abc'), 'an unknown filter value is ignored');

        $this->actingAs($this->c)->get('/approvals?status=approved')
            ->assertSee('Approved by Ceo Tester')->assertSee('Asked by Hr One');
        $this->actingAs($this->c)->get('/approvals?status=rejected')
            ->assertSee('Rejected by Ceo Tester')->assertSee('Walang katibayan')->assertSee('Asked by Hr Two');
        $this->actingAs($this->c)->get('/approvals?status=withdrawn')->assertSee('Withdrawn by the requester');
    }

    #[TestDox('S-13.9 the calendar lists the requester\'s rejected entries until a new request replaces them')]
    public function test_s_13_9_rejected_list_of_the_requester(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $this->saveAll($this->h, $this->e2, self::DX, ['time_in' => '08:00']);
        $this->saveAll($this->h2, $this->e2, self::DN, ['time_in' => '08:00']);
        $this->reject($this->c, $this->e1, self::DN, 'Walang katibayan');
        $this->reject($this->c, $this->e2, self::DX, 'Mali ang petsa');
        $this->reject($this->c, $this->e2, self::DN, 'Hindi kay Hr One ito');

        $list = function ($user) {
            $html = $this->calendar($user);
            $start = strpos($html, 'id="myRejectedList"');
            if ($start === false) {
                return null;
            }

            return html_entity_decode(strip_tags(substr($html, $start, strpos($html, 'id="myRejectedListEnd"', $start) - $start)), ENT_QUOTES);
        };

        $mine = $list($this->h);
        $this->assertNotNull($mine);
        $this->assertSame(2, substr_count($mine, 'Rejected:'));
        $this->assertStringContainsString('Employee One', $mine);
        $this->assertStringContainsString('Oct 06, 2026', $mine);
        $this->assertStringContainsString('Walang katibayan', $mine);
        $this->assertStringContainsString('Mali ang petsa', $mine);
        $this->assertStringNotContainsString('Hindi kay Hr One ito', $mine);
        $this->assertNull($list($this->m), 'a user without rejected requests sees no list');

        // A new request for the same field replaces the notice.
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:05']);
        $mine = $list($this->h);
        $this->assertSame(1, substr_count($mine, 'Rejected:'));
        $this->assertStringNotContainsString('Walang katibayan', $mine);
        $this->assertStringContainsString('Mali ang petsa', $mine);
    }

    /** Three pending entries: E1/Dn (two fields, by H), E1/Dr (by H), E2/Dn (by the admin). */
    private function threeEntries(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00', 'time_out' => '17:00']);
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $this->saveAll($this->m, $this->e2, self::DN, ['time_in' => '08:00']);
    }
}
