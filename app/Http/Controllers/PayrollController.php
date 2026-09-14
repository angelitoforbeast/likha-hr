<?php

namespace App\Http\Controllers;

use App\Models\CutoffRule;
use App\Models\Department;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollController extends Controller
{
    /**
     * List all payroll runs.
     */
    public function index()
    {
        $runs = PayrollRun::with('creator')
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('payroll.index', compact('runs'));
    }

    /**
     * Show create payroll run form.
     */
    public function create()
    {
        $cutoffRules = CutoffRule::all();
        return view('payroll.create', compact('cutoffRules'));
    }

    /**
     * Store and compute a new payroll run.
     */
    public function store(Request $request)
    {
        $request->validate([
            'cutoff_start' => 'required|date',
            'cutoff_end'   => 'required|date|after_or_equal:cutoff_start',
        ]);

        $run = PayrollRun::create([
            'cutoff_start' => $request->cutoff_start,
            'cutoff_end'   => $request->cutoff_end,
            'created_by'   => Auth::id(),
            'status'       => 'draft',
        ]);

        $service = new PayrollService();
        $service->computePayroll($run);

        return redirect()->route('payroll.show', $run)
            ->with('success', 'Payroll run created and computed successfully.');
    }

    /**
     * Show payroll run details with optional department filter.
     */
    public function show(Request $request, PayrollRun $run)
    {
        $departments = Department::orderBy('name')->get();

        $query = PayrollItem::with(['employee', 'employee.department'])
            ->where('payroll_run_id', $run->id);

        // Department filter
        if ($request->filled('department_id')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('department_id', $request->department_id);
            });
        }

        // Search by employee name
        if ($request->filled('search_name')) {
            $search = $request->search_name;
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('employee_id')->get();

        $totals = [
            'required_mandays'       => $items->sum('required_mandays'),
            'days_worked'            => $items->sum('days_worked'),
            'absent_days'            => $items->sum('absent_days'),
            'total_work_minutes'     => $items->sum('total_work_minutes'),
            'total_days_decimal'     => $items->sum('total_days_decimal'),
            'total_late_minutes'     => $items->sum('total_late_minutes'),
            'total_early_minutes'    => $items->sum('total_early_minutes'),
            'total_overtime_minutes' => $items->sum('total_overtime_minutes'),
            'base_pay'               => $items->sum('base_pay'),
            'late_deduction'         => $items->sum('late_deduction'),
            'early_deduction'        => $items->sum('early_deduction'),
            'absence_deduction'      => $items->sum('absence_deduction'),
            'total_earnings'         => $items->sum('total_earnings'),
            'total_deductions'       => $items->sum('total_deductions'),
            'gross_pay'              => $items->sum('gross_pay'),
            'adjustments'            => $items->sum('adjustments'),
            'final_pay'              => $items->sum('final_pay'),
        ];

        return view('payroll.show', compact('run', 'items', 'totals', 'departments'));
    }

    /**
     * Finalize a payroll run.
     */
    public function finalize(PayrollRun $run)
    {
        if ($run->isFinal()) {
            return back()->with('error', 'This payroll run is already finalized.');
        }

        $run->update(['status' => 'final']);

        // Auto-lock every date in the payroll cutoff so attendance edits are frozen.
        // Anyone opening those dates in the attendance calendar will see them as locked.
        $period = \Carbon\CarbonPeriod::create($run->cutoff_start, $run->cutoff_end);
        $lockedCount = 0;
        foreach ($period as $day) {
            \App\Models\AttendanceDateLock::updateOrCreate(
                ['lock_date' => $day->format('Y-m-d')],
                [
                    'locked_by' => Auth::id(),
                    'reason'    => 'Auto-locked when Payroll Run #' . $run->id . ' was finalized.',
                    'source'    => 'payroll_final',
                    'locked_at' => now(),
                ]
            );
            $lockedCount++;
        }

        return back()->with('success', "Payroll run finalized. Attendance for {$lockedCount} date(s) auto-locked.");
    }

    /**
     * Save an adjustment for a payroll item.
     *
     * Dual-write:
     *   1. Master payroll_adjustments (source of truth, keyed by
     *      employee + cutoff — survives run deletion and recompute)
     *   2. payroll_items snapshot for THIS run (immediate feedback)
     * Other runs' snapshots are left alone; a Recompute is needed for
     * them to pick up the new master value.
     */
    public function saveAdjustment(Request $request, PayrollRun $run)
    {
        if ($run->isFinal()) {
            return response()->json(['success' => false, 'message' => 'Payroll run is finalized. Cannot edit.'], 403);
        }

        $request->validate([
            'payroll_item_id' => 'required|exists:payroll_items,id',
            'adjustments'     => 'required|numeric',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $item = PayrollItem::where('id', $request->payroll_item_id)
            ->where('payroll_run_id', $run->id)
            ->firstOrFail();

        $adjustments = (float) $request->adjustments;
        $notes       = $request->notes;

        // Final Pay = Gross Pay + Earnings - Deductions + Adjustments
        $finalPay = round(
            (float) $item->gross_pay
            + (float) $item->total_earnings
            - (float) $item->total_deductions
            + $adjustments,
            2
        );

        \DB::transaction(function () use ($item, $run, $adjustments, $notes, $finalPay) {
            // 1. Master upsert — one row per (employee, cutoff)
            PayrollAdjustment::updateOrCreate(
                [
                    'employee_id'  => $item->employee_id,
                    'cutoff_start' => $run->cutoff_start->format('Y-m-d'),
                    'cutoff_end'   => $run->cutoff_end->format('Y-m-d'),
                ],
                [
                    'amount' => $adjustments,
                    'notes'  => $notes,
                ]
            );

            // 2. Snapshot this run's payroll_item (other runs untouched)
            $item->update([
                'adjustments' => $adjustments,
                'final_pay'   => max(0, $finalPay),
                'notes'       => $notes,
            ]);
        });

        return response()->json([
            'success'   => true,
            'final_pay' => number_format(max(0, $finalPay), 2),
        ]);
    }

    /**
     * Recompute every payroll item in a draft run.
     *
     * CEO-only. Finalized runs are refused so historical snapshots stay
     * immutable. The master payroll_adjustments table is queried inside
     * PayrollService::computeForEmployee — so this refresh picks up the
     * current master values without the CEO having to click each item.
     */
    public function recompute(PayrollRun $run)
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'ceo') {
            abort(403, 'Only the CEO can recompute a payroll run.');
        }
        if ($run->isFinal()) {
            return back()->with('error', 'Finalized payroll runs cannot be recomputed.');
        }

        (new \App\Services\PayrollService())->computePayroll($run);

        return back()->with('success', 'Payroll run recomputed. Master adjustments were re-applied to each item snapshot.');
    }

    /**
     * Show individual payslip for an employee in a payroll run.
     */
    public function payslip(PayrollRun $run, PayrollItem $item)
    {
        if ($item->payroll_run_id !== $run->id) {
            abort(404);
        }

        $item->load(['employee', 'employee.department']);

        return view('payroll.payslip', compact('run', 'item'));
    }

    /**
     * Export payroll run as CSV.
     */
    public function exportCsv(PayrollRun $run): StreamedResponse
    {
        $items = PayrollItem::with(['employee', 'employee.department'])
            ->where('payroll_run_id', $run->id)
            ->orderBy('employee_id')
            ->get();

        $filename = "payroll_run_{$run->id}_{$run->cutoff_start->format('Ymd')}_{$run->cutoff_end->format('Ymd')}.csv";

        return response()->streamDownload(function () use ($items) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Employee', 'Department', 'Daily Rate',
                'Req. Mandays', 'Days Worked', 'Absent Days',
                'Late Min', 'Early Min',
                'Basic Pay', 'Late Ded.', 'Early Ded.', 'Absence Ded.',
                'Earnings', 'Deductions',
                'Gross Pay', 'Adjustments', 'Final Pay', 'Notes',
            ]);

            foreach ($items as $item) {
                fputcsv($handle, [
                    $item->employee->display_name ?? '',
                    $item->employee->department->name ?? '—',
                    number_format($item->daily_rate, 2),
                    $item->required_mandays,
                    $item->days_worked,
                    $item->absent_days,
                    $item->total_late_minutes,
                    $item->total_early_minutes ?? 0,
                    number_format($item->base_pay, 2),
                    number_format($item->late_deduction ?? 0, 2),
                    number_format($item->early_deduction ?? 0, 2),
                    number_format($item->absence_deduction ?? 0, 2),
                    number_format($item->total_earnings ?? 0, 2),
                    number_format($item->total_deductions ?? 0, 2),
                    number_format($item->gross_pay ?? 0, 2),
                    number_format($item->adjustments, 2),
                    number_format($item->final_pay, 2),
                    $item->notes ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Delete a payroll run and all its items. CEO only.
     */
    public function destroy(PayrollRun $run)
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'ceo') {
            abort(403, 'Only CEO can delete payroll runs.');
        }

        $runId = $run->id;
        $run->items()->delete();
        $run->delete();

        return redirect()->route('payroll.index')
            ->with('success', "Payroll Run #{$runId} has been deleted.");
    }

    /**
     * Export payroll run as a basic PDF (print-friendly HTML).
     */
    public function exportPdf(PayrollRun $run)
    {
        $items = PayrollItem::with(['employee', 'employee.department'])
            ->where('payroll_run_id', $run->id)
            ->orderBy('employee_id')
            ->get();

        $totals = [
            'base_pay'           => $items->sum('base_pay'),
            'absence_deduction'  => $items->sum('absence_deduction'),
            'late_deduction'     => $items->sum('late_deduction'),
            'early_deduction'    => $items->sum('early_deduction'),
            'total_earnings'     => $items->sum('total_earnings'),
            'total_deductions'   => $items->sum('total_deductions'),
            'gross_pay'          => $items->sum('gross_pay'),
            'adjustments'        => $items->sum('adjustments'),
            'final_pay'          => $items->sum('final_pay'),
        ];

        return view('payroll.pdf', compact('run', 'items', 'totals'));
    }
}
