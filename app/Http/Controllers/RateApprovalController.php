<?php

namespace App\Http\Controllers;

use App\Models\EmployeeRate;
use App\Services\AttendanceApprovalService;
use App\Services\RateChangeRefused;
use App\Services\RateChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Rate Approvals (spec 013): the daily rates asked by anyone but the CEO, in one list for the
 * CEO, each approved or rejected on its own.
 */
class RateApprovalController extends Controller
{
    public function __construct(protected RateChangeService $rates)
    {
    }

    /** CEO only, on the role read from the database now, like Approvals: no feature permission opens it. */
    protected function assertCeo(): void
    {
        if (!AttendanceApprovalService::isCeoNow(Auth::user())) {
            abort(403, 'Only the CEO can open Rate Approvals.');
        }
    }

    public function index(Request $request)
    {
        $this->assertCeo();

        $decided = $request->input('status') === 'decided';
        if ($decided) {
            // Asked by someone else and decided by the CEO; the CEO's own direct saves are not requests.
            $rows = EmployeeRate::anyStatus()->with(['employee', 'requester', 'decider'])
                ->whereNotNull('decided_at')
                ->whereColumn('decided_by', '!=', 'requested_by')
                ->orderByDesc('decided_at')->orderByDesc('id')
                ->limit(100)->get();
            $items = $rows->map(fn ($r) => ['rate' => $r, 'preview' => null]);
        } else {
            $rows = EmployeeRate::anyStatus()->with(['employee', 'requester'])
                ->where('status', EmployeeRate::STATUS_PENDING)
                ->orderBy('created_at')->orderBy('id')->get();
            $items = $rows->map(fn ($r) => ['rate' => $r, 'preview' => $this->rates->preview($r)]);
        }

        return view('approvals.rates', compact('items', 'decided'));
    }

    public function approve(Request $request, int $rate)
    {
        $this->assertCeo();
        $note = $this->note($request);

        try {
            $done = $this->rates->approve($rate, Auth::user(), $note);
        } catch (RateChangeRefused $e) {
            return redirect()->route('approvals.rates')->with('error', $e->getMessage());
        }

        $name = $done['rate']->employee->display_name ?? 'the employee';

        return redirect()->route('approvals.rates')->with('success', trim(
            "Approved: {$name} ₱" . number_format((float) $done['rate']->daily_rate, 2) . ' from ' . $done['rate']->rangeText() . '. '
            . $this->rates->shortenedText($done['shortened'])
        ));
    }

    public function reject(Request $request, int $rate)
    {
        $this->assertCeo();
        $note = $this->note($request);

        try {
            $rejected = $this->rates->reject($rate, Auth::user(), $note);
        } catch (RateChangeRefused $e) {
            return redirect()->route('approvals.rates')->with('error', $e->getMessage());
        }

        return redirect()->route('approvals.rates')->with('success',
            'Rejected: ' . ($rejected->employee->display_name ?? 'the employee') . ' ₱' . number_format((float) $rejected->daily_rate, 2) . '. Nothing changed.');
    }

    protected function note(Request $request): ?string
    {
        $note = trim((string) ($request->validate(['note' => 'nullable|string|max:500'])['note'] ?? ''));

        return $note === '' ? null : $note;
    }
}
