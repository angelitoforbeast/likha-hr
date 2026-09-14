<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Copy every existing non-zero adjustment from payroll_items into the
     * new payroll_adjustments master table so nothing is lost when a run
     * is later deleted or recomputed.
     *
     * If two runs happen to share the same (employee, cutoff) — e.g. an
     * old deleted-then-recreated pair — the LATEST run wins because we
     * order by payroll_run_id asc and upsert.
     */
    public function up(): void
    {
        $rows = DB::table('payroll_items as pi')
            ->join('payroll_runs as pr', 'pi.payroll_run_id', '=', 'pr.id')
            ->where(function ($q) {
                $q->where('pi.adjustments', '!=', 0)->orWhereNotNull('pi.notes');
            })
            ->orderBy('pi.payroll_run_id')
            ->select([
                'pi.employee_id',
                'pi.adjustments',
                'pi.notes',
                'pr.cutoff_start',
                'pr.cutoff_end',
            ])
            ->get();

        $now = now();
        $backfilled = 0;
        foreach ($rows as $row) {
            DB::table('payroll_adjustments')->updateOrInsert(
                [
                    'employee_id'  => $row->employee_id,
                    'cutoff_start' => $row->cutoff_start,
                    'cutoff_end'   => $row->cutoff_end,
                ],
                [
                    'amount'     => $row->adjustments,
                    'notes'      => $row->notes,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
            $backfilled++;
        }
        echo "  Backfilled {$backfilled} adjustment(s) into payroll_adjustments.\n";
    }

    public function down(): void
    {
        // Non-destructive: leave the backfilled rows in place. Dropping
        // the table itself (via the create migration's down) removes them.
    }
};