<?php

namespace App\Http\Controllers;

use App\Models\CutoffRule;
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
     * Show payroll run details.
     */
    public function show(PayrollRun $run)
    {
        $items = PayrollItem::with('employee')
            ->where('payroll_run_id', $run->id)
            ->orderBy('employee_id')
            ->get();

        $totals = [
            'total_work_minutes'     => $items->sum('total_work_minutes'),
            'total_days_decimal'     => $items->sum('total_days_decimal'),
            'total_late_minutes'     => $items->sum('total_late_minutes'),
            'total_early_minutes'    => $items->sum('total_early_minutes'),
            'total_overtime_minutes' => $items->sum('total_overtime_minutes'),
            'base_pay'               => $items->sum('base_pay'),
            'late_deduction'         => $items->sum('late_deduction'),
            'early_deduction'        => $items->sum('early_deduction'),
            'ot_pay'                 => $items->sum('ot_pay'),
            'adjustments'            => $items->sum('adjustments'),
            'final_pay'              => $items->sum('final_pay'),
        ];

        return view('payroll.show', compact('run', 'items', 'totals'));
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

        return back()->with('success', 'Payroll run finalized. Edits are now locked.');
    }

    /**
     * Save adjustment for a payroll item.
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

        // Final Pay = Base Pay - Late Deduction - Early Deduction + OT Pay + Adjustments
        $finalPay = round(
            (float) $item->base_pay
            - (float) $item->late_deduction
            - (float) $item->early_deduction
            + (float) $item->ot_pay
            + $adjustments,
            2
        );

        $item->update([
            'adjustments' => $adjustments,
            'final_pay'   => max(0, $finalPay),
            'notes'       => $request->notes,
        ]);

        return response()->json([
            'success'   => true,
            'final_pay' => number_format(max(0, $finalPay), 2),
        ]);
    }

    /**
     * Export payroll run as CSV.
     */
    public function exportCsv(PayrollRun $run): StreamedResponse
    {
        $items = PayrollItem::with('employee')
            ->where('payroll_run_id', $run->id)
            ->orderBy('employee_id')
            ->get();

        $filename = "payroll_run_{$run->id}_{$run->cutoff_start->format('Ymd')}_{$run->cutoff_end->format('Ymd')}.csv";

        return response()->streamDownload(function () use ($items) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Employee', 'Work Minutes', 'Days',
                'Late Min', 'Early Min', 'OT Min',
                'Base Pay', 'Late Deduction', 'Early Deduction', 'OT Pay',
                'Adjustments', 'Final Pay', 'Notes',
            ]);

            foreach ($items as $item) {
                fputcsv($handle, [
                    $item->employee->full_name ?? '',
                    $item->total_work_minutes,
                    number_format($item->total_days_decimal, 4),
                    $item->total_late_minutes,
                    $item->total_early_minutes ?? 0,
                    $item->total_overtime_minutes,
                    number_format($item->base_pay, 2),
                    number_format($item->late_deduction ?? 0, 2),
                    number_format($item->early_deduction ?? 0, 2),
                    number_format($item->ot_pay ?? 0, 2),
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
     * Export payroll run as a basic PDF (print-friendly HTML).
     */
    public function exportPdf(PayrollRun $run)
    {
        $items = PayrollItem::with('employee')
            ->where('payroll_run_id', $run->id)
            ->orderBy('employee_id')
            ->get();

        $totals = [
            'base_pay'        => $items->sum('base_pay'),
            'late_deduction'  => $items->sum('late_deduction'),
            'early_deduction' => $items->sum('early_deduction'),
            'ot_pay'          => $items->sum('ot_pay'),
            'adjustments'     => $items->sum('adjustments'),
            'final_pay'       => $items->sum('final_pay'),
        ];

        return view('payroll.pdf', compact('run', 'items', 'totals'));
    }
}
