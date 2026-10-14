<?php

namespace Tests\Feature\Approvals;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The pages show what was saved without being loaded again, and the controls this
 * slice added ask and answer in the page, not in the browser's own dialogs.
 * No case number: these pin how S-05, S-09, S-10 and S-14 feel in a browser.
 */
class PageUpdatesTest extends ApprovalsTestCase
{
    /** confirm(, alert( and prompt( in a text, counted. */
    private function nativeDialogs(string $source): array
    {
        return [
            'alert'   => preg_match_all('/\balert\(/', $source),
            'confirm' => preg_match_all('/\bconfirm\(/', $source),
            'prompt'  => preg_match_all('/\bprompt\(/', $source),
        ];
    }

    private function calendarScript(string $from, string $to): string
    {
        $source = file_get_contents(resource_path('views/attendance-calendar/index.blade.php'));
        $start = strpos($source, $from);
        $end = strpos($source, $to);
        $this->assertNotFalse($start, "marker missing: {$from}");
        $this->assertNotFalse($end, "marker missing: {$to}");

        return substr($source, $start, $end - $start);
    }

    #[TestDox('the screens and scripts this slice added use no confirm(), alert() or prompt()')]
    public function test_new_controls_use_no_native_dialogs(): void
    {
        $none = ['alert' => 0, 'confirm' => 0, 'prompt' => 0];

        // The Approvals page is new as a whole: Approve, Reject, the "selected" and the batch actions.
        $this->assertSame($none, $this->nativeDialogs(file_get_contents(resource_path('views/approvals/index.blade.php'))));

        // The request script of the calendar: the pending panel, Withdraw, Change.
        $this->assertSame($none, $this->nativeDialogs(
            $this->calendarScript('// ===== requests: pure part =====', '// ===== /requests: page part =====')
        ));

        // The views that existed before keep exactly the dialogs of their old controls, no more:
        // the calendar's Lock, Unlock, Lock range, Revert OT, Remove SIL, Delete time and Bulk apply;
        // the older Attendance page's compute and force-recompute; the payroll page's Recompute and Finalize.
        $this->assertSame(
            ['alert' => 15, 'confirm' => 8, 'prompt' => 2],
            $this->nativeDialogs(file_get_contents(resource_path('views/attendance-calendar/index.blade.php'))),
            'the calendar view has the native dialogs it had before this slice, and none added'
        );
        $this->assertSame(
            ['alert' => 2, 'confirm' => 2, 'prompt' => 0],
            $this->nativeDialogs(file_get_contents(resource_path('views/attendance/index.blade.php'))),
            'the older Attendance page has the native dialogs it had before this slice, and none added'
        );
        $this->assertSame(
            ['alert' => 0, 'confirm' => 2, 'prompt' => 0],
            $this->nativeDialogs(file_get_contents(resource_path('views/payroll/show.blade.php')))
        );

        // And the page as the CEO gets it, with entries and a batch on it.
        $this->actingAs($this->h)->postJson('/attendance-calendar/bulk-action', [
            'date' => self::DN, 'employee_ids' => [$this->e1->id, $this->e2->id], 'action' => 'fill_from_shift', 'reason' => self::REASON,
        ])->assertStatus(200);
        $html = $this->actingAs($this->c)->get('/approvals')->assertStatus(200)->getContent();
        $this->assertSame($none, $this->nativeDialogs($html));
        $this->assertStringContainsString('data-confirm="Approve the selected entries?"', $html);
        $this->assertStringContainsString('data-confirm="Approve every entry of this bulk action shown on this page?"', $html);
        $this->assertStringContainsString('id="approvalMsg"', $html);
        $this->assertSame(2, substr_count($html, 'class="approval-entry card border-0 shadow-sm mb-3" data-anchor="'));
    }

    #[TestDox('the reply to a save stored as a request lists the day\'s requests for the page to show at once')]
    public function test_request_replies_carry_the_day(): void
    {
        $saved = $this->askFourTimes();
        $items = $saved->json('day_requests');
        $this->assertCount(4, $items);
        $this->assertSame(
            ['status' => 'pending', 'field' => 'time_in', 'label' => 'Time In', 'requested' => '8:00 AM', 'mine' => true, 'official' => 'none', 'changeable' => true],
            array_intersect_key($items[0], array_flip(['status', 'field', 'label', 'requested', 'official', 'mine', 'changeable']))
        );
        $this->assertSame(self::REASON, $items[0]['reason']);
        $this->assertSame('Hr One', $items[0]['requester']);

        // It is the same list the cell carries after a reload.
        $cell = $this->cell($this->calendar($this->h), $this->e1, self::DN);
        $this->assertStringContainsString(json_encode($items[0]['asked_at']), $cell);
        foreach ($items as $item) {
            $this->assertStringContainsString('"id":' . $item['id'] . ',', $cell);
        }

        // Every other way of asking answers with the day as well.
        $day = $this->day($this->e1, self::DR);
        $byField = $this->actingAs($this->h)->postJson('/attendance-calendar/override-time', [
            'attendance_day_id' => $day['id'], 'field' => 'time_out', 'new_value' => '17:30', 'reason' => self::REASON,
        ]);
        $this->assertSame(['5:30 PM'], array_column($byField->json('day_requests'), 'requested'));
        $this->assertSame(['5:00 PM'], array_column($byField->json('day_requests'), 'official'));
        $restDay = $this->toggleDayOff($this->h, $this->e1, self::DR, 'add_day_off');
        $this->assertCount(2, $restDay->json('day_requests'));
        $fill = $this->actingAs($this->h)->postJson('/attendance-calendar/fill-from-shift', [
            'employee_id' => $this->e2->id, 'date' => self::DN, 'reason' => self::REASON,
        ]);
        $this->assertCount(4, $fill->json('day_requests'));

        // Someone else looking at the same day is not offered Withdraw or Change.
        $other = $this->saveAll($this->h2, $this->e1, self::DR, ['time_in' => '08:00']);
        $mine = array_column($other->json('day_requests'), 'mine', 'field');
        $this->assertSame(['time_out' => false, 'rest_day' => false, 'time_in' => true], $mine);
    }

    #[TestDox('the reply to Change carries the new value, and the reply to Withdraw the day without the request')]
    public function test_change_and_withdraw_replies_carry_the_day(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00', 'time_out' => '17:00']);
        $pending = $this->pending($this->e1, self::DN);

        $changed = $this->actingAs($this->h)->postJson("/attendance-requests/{$pending['time_in']['id']}/change", [
            'new_value' => '08:20', 'reason' => 'Itinama ang oras',
        ])->assertStatus(200);
        $byField = array_column($changed->json('day_requests'), null, 'field');
        $this->assertSame('8:20 AM', $byField['time_in']['requested']);
        $this->assertSame('Itinama ang oras', $byField['time_in']['reason']);
        $this->assertSame('5:00 PM', $byField['time_out']['requested']);

        $withdrawn = $this->actingAs($this->h)->postJson("/attendance-requests/{$pending['time_in']['id']}/withdraw")->assertStatus(200);
        $this->assertSame(['time_out'], array_column($withdrawn->json('day_requests'), 'field'));

        $last = $this->actingAs($this->h)->postJson("/attendance-requests/{$pending['time_out']['id']}/withdraw")->assertStatus(200);
        $this->assertSame([], $last->json('day_requests'), 'nothing is left: the cell goes back to its official state');

        // A rejected request stays on the day as a notice until it is asked again.
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $this->reject($this->c, $this->e1, self::DN, 'Walang katibayan');
        $again = $this->saveAll($this->h, $this->e1, self::DN, ['time_out' => '17:00']);
        $this->assertSame(['pending', 'rejected'], array_column($again->json('day_requests'), 'status'));
        $this->assertSame('Walang katibayan', $again->json('day_requests')[1]['reason']);
    }

    #[TestDox('the replies to approve, reject and the "selected" actions tell the page which entries left and the new count')]
    public function test_decision_replies_carry_the_count_and_the_entries(): void
    {
        foreach ([$this->e1, $this->e2] as $employee) {
            $this->saveAll($this->h, $employee, self::DN, ['time_in' => '08:00']);
            $this->saveAll($this->h, $employee, self::DX, ['time_in' => '08:00']);
        }
        $this->lock(self::DX);

        $one = $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->assertSame(3, $one->json('pending_entries'));
        $rejected = $this->reject($this->c, $this->e2, self::DN)->assertStatus(200);
        $this->assertSame(2, $rejected->json('pending_entries'));

        $first = $this->anchorId($this->e1, self::DX);
        $second = $this->anchorId($this->e2, self::DX);
        $several = $this->actingAs($this->c)->postJson('/approvals/approve-several', ['entries' => [
            ['id' => $first, 'seen' => $this->seen($this->e1, self::DX)],
            ['id' => $second, 'seen' => $this->seen($this->e2, self::DX)],
        ]])->assertStatus(200);
        $this->assertSame(2, $several->json('pending_entries'));
        $results = $several->json('results');
        $this->assertSame([$first, $second], array_column($results, 'id'));
        $this->assertSame([false, false], array_column($results, 'ok'));
        $this->assertStringContainsString('This date is locked', $results[0]['message']);

        $this->unlock(self::DX);
        $several = $this->actingAs($this->c)->postJson('/approvals/reject-several', ['reason' => 'Kulang ang patunay', 'entries' => [
            ['id' => $first, 'seen' => $this->seen($this->e1, self::DX)],
            ['id' => $second, 'seen' => $this->seen($this->e2, self::DX)],
        ]])->assertStatus(200);
        $this->assertSame([['id' => $first, 'ok' => true], ['id' => $second, 'ok' => true]], $several->json('results'));
        $this->assertSame(0, $several->json('pending_entries'));

        // A refused rejection says why, for the page to write beside the reason field.
        $this->saveAll($this->h, $this->e1, '2026-10-09', ['time_in' => '08:00']);
        $refused = $this->reject($this->c, $this->e1, '2026-10-09', 'ab');
        $refused->assertStatus(422);
        $this->assertNotEmpty($refused->json('errors.reason.0'));
    }

    #[TestDox('the calendar script puts a saved request on the cell and in the panel, and takes a withdrawn one off again')]
    public function test_request_script_updates_the_cell_and_the_panel(): void
    {
        exec('node --version 2>&1', $version, $missing);
        if ($missing !== 0) {
            $this->markTestSkipped('node is not installed on this machine; the script is not run.');
        }

        $script = $this->calendarScript('// ===== requests: pure part =====', '// ===== /requests: page part =====');
        $harness = <<<'JS'
const assert = require('assert');

// The few parts of a page the script touches.
class El {
    constructor(className) {
        this.dataset = {}; this.style = {}; this.children = []; this.className = className || '';
        this.textContent = ''; this.innerHTML = ''; this.title = ''; this.parent = null;
        const el = this;
        this.classList = {
            add(c) { if (!el.className.split(' ').includes(c)) el.className = (el.className + ' ' + c).trim(); },
            remove(c) { el.className = el.className.split(' ').filter(x => x !== c).join(' '); },
            contains(c) { return el.className.split(' ').includes(c); },
        };
    }
    querySelector(selector) { return this.children.find(c => ('.' + c.className) === selector) || null; }
    appendChild(child) { child.parent = this; this.children.push(child); return child; }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter(c => c !== this); }
    scrollIntoView() {}
    focus() {}
}
const byId = {
    dPendingPanel: new El(), dPendingList: new El(), dPendingTitle: new El(), dPendingMsg: new El(),
    dSaveAllMsg: new El(), dSaveAllReason: new El(), dInput_time_in: new El(),
};
byId.dPendingPanel.style.display = 'none';
global.document = {
    getElementById: id => byId[id] || null,
    createElement: () => new El(),
    querySelector: () => ({ content: 'token' }),
};
let posted = [];
let nextReply = {};
global.fetch = (url, options) => { posted.push([url, JSON.parse(options.body)]); return Promise.resolve({ json: () => Promise.resolve(nextReply) }); };
let flashed = [];
function flashMessage(el, text, ok) { flashed.push([text, ok]); }
let modalHasChanges = false;
let currentTd = new El('cal-cell-absent');
currentTd.dataset.locked = '0';

/*SCRIPT*/

const pending = { id: 5, status: 'pending', field: 'time_in', label: 'Time In', requested: '8:00 AM', official: 'none',
    reason: '<b>late</b>', requester: 'Hr One', asked_at: 'Oct 14, 2026 9:00 AM', mine: true, changeable: true };
const rejected = { id: 4, status: 'rejected', field: 'time_out', label: 'Time Out', requested: '5:00 PM',
    reason: 'Walang katibayan', requester: 'Hr One', by: 'Ceo Tester', mine: true };

// Pure part.
assert.deepStrictEqual(summarizeDayRequests([pending, rejected], false), { pendingCount: 1, markText: 'Pending' });
assert.strictEqual(summarizeDayRequests([pending], true).markText, 'Pending (date locked)');
assert.deepStrictEqual(summarizeDayRequests([rejected], false), { pendingCount: 0, markText: '' });
let view = requestsPanel([pending, rejected], false, null);
assert.strictEqual(view.title, 'Pending approval');
assert(view.html.includes('askWithdrawRequest(5)') && view.html.includes("changeRequest(5, 'time_in')"));
assert(!view.html.includes('<b>late</b>') && view.html.includes('&lt;b&gt;late&lt;/b&gt;'), 'a reason is text');
assert(view.html.includes('Rejected: Walang katibayan'));
view = requestsPanel([pending], false, 5);
assert(view.html.includes('Yes, withdraw') && view.html.includes('withdrawRequest(5)') && view.html.includes('cancelWithdrawRequest()'));
assert(!view.html.includes('askWithdrawRequest(5)'), 'the question replaces the buttons');
view = requestsPanel([pending], true, null);
assert(!view.html.includes('changeRequest(') && view.html.includes('askWithdrawRequest(5)'), 'on a locked date only Withdraw');
assert.strictEqual(view.title, 'Pending approval (date locked)');
view = requestsPanel([Object.assign({}, pending, { mine: false })], false, null);
assert(!view.html.includes('Withdraw'), "another person's request has no buttons");
assert.strictEqual(requestsPanel([rejected], false, null).title, 'Request not approved');

// After "Sent for approval": the marker on the cell, the request in the panel, the message at the top.
showRequestSaved({ message: 'Sent for approval.', day_requests: [pending] });
assert.strictEqual(currentTd.dataset.pendingCount, '1');
assert(currentTd.classList.contains('cal-cell-pending') && currentTd.classList.contains('cal-cell-absent'));
assert.strictEqual(currentTd.querySelector('.cal-pending-mark').textContent, 'Pending');
assert.strictEqual(byId.dPendingPanel.style.display, '');
assert(byId.dPendingList.innerHTML.includes('8:00 AM'));
assert.strictEqual(byId.dPendingMsg.textContent, 'Sent for approval.');
assert.deepStrictEqual(JSON.parse(currentTd.dataset.requests), [pending]);

// A second save does not add a second marker.
showRequestSaved({ message: 'Already pending approval.', day_requests: [pending] });
assert.strictEqual(currentTd.children.length, 1);

(async () => {
    // Change: the new value is in the panel at once.
    byId.dInput_time_in.value = '08:20';
    byId.dSaveAllReason.value = 'Itinama ang oras';
    nextReply = { success: true, message: 'Request changed. Pending approval.', day_requests: [Object.assign({}, pending, { requested: '8:20 AM' })] };
    changeRequest(5, 'time_in');
    await new Promise(r => setTimeout(r, 0));
    assert.deepStrictEqual(posted.pop(), ['{{ url("/attendance-requests") }}/5/change', { new_value: '08:20', reason: 'Itinama ang oras' }]);
    assert(byId.dPendingList.innerHTML.includes('8:20 AM') && !byId.dPendingList.innerHTML.includes('8:00 AM'));
    assert.strictEqual(byId.dPendingMsg.textContent, 'Request changed. Pending approval.');

    // Withdraw asks in the page first and sends nothing yet.
    askWithdrawRequest(5);
    assert(byId.dPendingList.innerHTML.includes('Yes, withdraw'));
    assert.strictEqual(posted.length, 0);
    cancelWithdrawRequest();
    assert(!byId.dPendingList.innerHTML.includes('Yes, withdraw'));

    // "Yes, withdraw": the cell and the panel go back to the official state.
    askWithdrawRequest(5);
    nextReply = { success: true, message: 'Request withdrawn.', day_requests: [] };
    withdrawRequest(5);
    await new Promise(r => setTimeout(r, 0));
    assert.strictEqual(posted.pop()[0], '{{ url("/attendance-requests") }}/5/withdraw');
    assert.strictEqual(currentTd.dataset.pendingCount, undefined);
    assert(!currentTd.classList.contains('cal-cell-pending') && currentTd.classList.contains('cal-cell-absent'));
    assert.strictEqual(currentTd.querySelector('.cal-pending-mark'), null);
    assert.strictEqual(byId.dPendingPanel.style.display, 'none');
    assert.deepStrictEqual(flashed.pop(), ['Request withdrawn.', true]);
    assert.strictEqual(modalHasChanges, true);

    // A refused withdrawal leaves the request where it is and says why.
    showRequestSaved({ message: 'Sent for approval.', day_requests: [pending] });
    nextReply = { success: false, message: 'This request was already decided (approved).' };
    withdrawRequest(5);
    await new Promise(r => setTimeout(r, 0));
    assert.strictEqual(currentTd.dataset.pendingCount, '1');
    assert.strictEqual(byId.dPendingMsg.textContent, 'This request was already decided (approved).');
    console.log('script ok');
})().catch(e => { console.error(e); process.exit(1); });
JS;
        $file = tempnam(sys_get_temp_dir(), 'req') . '.js';
        file_put_contents($file, str_replace('/*SCRIPT*/', $script, $harness));
        exec('node ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        @unlink($file);

        $this->assertSame(0, $code, "the request script failed under node:\n" . implode("\n", $output));
        $this->assertSame('script ok', trim(end($output)));
    }

    #[TestDox('the scripts of the three pages, as the server sends them, are valid JavaScript')]
    public function test_page_scripts_parse(): void
    {
        exec('node --version 2>&1', $version, $missing);
        if ($missing !== 0) {
            $this->markTestSkipped('node is not installed on this machine; the scripts are not parsed.');
        }

        $this->askFourTimes();
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $range = '?start_date=' . self::PERIOD_START . '&end_date=' . self::PERIOD_END;
        $pages = [
            'calendar as HR staff' => $this->calendar($this->h),
            'calendar as the CEO'  => $this->calendar($this->c),
            'approvals'            => $this->actingAs($this->c)->get('/approvals')->assertStatus(200)->getContent(),
            'older Attendance page' => $this->actingAs($this->h)->get('/attendance' . $range)->assertStatus(200)->getContent(),
        ];

        foreach ($pages as $name => $html) {
            preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/s', $html, $blocks);
            $this->assertNotEmpty($blocks[1], "{$name}: no inline script found");
            foreach ($blocks[1] as $index => $code) {
                $file = tempnam(sys_get_temp_dir(), 'page') . '.js';
                file_put_contents($file, $code);
                $output = [];
                exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
                @unlink($file);
                $this->assertSame(0, $exit, "{$name}, script block {$index} does not parse:\n" . implode("\n", array_slice($output, 0, 8)));
            }
        }
    }
}
