<?php

namespace Tests\Feature\Rates;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Approvals\ApprovalsTestCase;

/**
 * Fixtures of spec 013, on top of the Approvals fixtures (C the CEO, H and H2 HR staff,
 * M an admin; E1 and E2 with a rate of 520.00 from 2026-01-01; a draft payroll run for
 * Oct 1–15, 2026 with a computed day for E1 on Oct 5).
 *
 * E1's rate is made a "For Probi" rate with a future end date, like the three rates of
 * 2026-11-12: 520.00 from Jan 01, 2026 until Jun 30, 2027. Every role may edit
 * daily_rates, as the live HR accounts can.
 */
abstract class RatesTestCase extends ApprovalsTestCase
{
    protected const PROBI_UNTIL = '2027-06-30';
    protected const RAISE_FROM = '2026-11-16';
    protected const NOTE = 'Hintayin ang evaluation';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ceo', 'hr_staff', 'admin'] as $role) {
            foreach (['daily_rates' => 'Daily Rates', 'nav_employees' => 'Employees', 'nav_payroll' => 'Payroll'] as $key => $label) {
                DB::table('feature_permissions')->updateOrInsert(
                    ['role' => $role, 'feature_key' => $key],
                    ['feature_label' => $label, 'can_view' => true, 'can_edit' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        // Like the other date columns of the test database: a date written by a model keeps
        // only its date, as in the MySQL DATE column of production.
        foreach (['INSERT', 'UPDATE'] as $event) {
            DB::statement(
                'CREATE TRIGGER IF NOT EXISTS date_only_employee_rates_' . strtolower($event)
                . " AFTER {$event} ON employee_rates WHEN length(NEW.effective_date) > 10 OR length(NEW.effective_until) > 10"
                . ' BEGIN UPDATE employee_rates SET effective_date = substr(effective_date, 1, 10), effective_until = substr(effective_until, 1, 10) WHERE id = NEW.id; END'
            );
        }

        DB::table('employee_rates')->where('employee_id', $this->e1->id)->update(['effective_until' => self::PROBI_UNTIL, 'remarks' => 'For Probi']);
    }

    /* ── Actions ── */

    protected function addRate(User $user, Employee $employee, array $fields = []): TestResponse
    {
        return $this->actingAs($user)->post('/employees/' . $employee->id . '/add-rate', array_merge([
            'daily_rate' => '600', 'effective_date' => self::RAISE_FROM, 'remarks' => 'Regular na',
        ], $fields));
    }

    protected function editRate(User $user, Employee $employee, int $rateId, array $fields): TestResponse
    {
        return $this->actingAs($user)->put('/employees/' . $employee->id . '/rate/' . $rateId, $fields);
    }

    protected function approveRate(User $user, int $rateId, array $fields = []): TestResponse
    {
        return $this->actingAs($user)->post('/approvals/rates/' . $rateId . '/approve', $fields);
    }

    protected function rejectRate(User $user, int $rateId, array $fields = []): TestResponse
    {
        return $this->actingAs($user)->post('/approvals/rates/' . $rateId . '/reject', $fields);
    }

    /* ── Reads ── */

    /** Every rate row of an employee, any status, oldest start first, as plain arrays. */
    protected function rates(Employee $employee): array
    {
        return DB::table('employee_rates')->where('employee_id', $employee->id)
            ->orderBy('effective_date')->orderBy('id')->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** The rows that count: [effective_date, effective_until, daily_rate] of each approved rate. */
    protected function approvedRanges(Employee $employee): array
    {
        return DB::table('employee_rates')->where('employee_id', $employee->id)->where('status', 'approved')
            ->orderBy('effective_date')->get()
            ->map(fn ($r) => [substr($r->effective_date, 0, 10), $r->effective_until ? substr($r->effective_until, 0, 10) : null, number_format((float) $r->daily_rate, 2, '.', '')])
            ->all();
    }

    protected function lastRate(Employee $employee): array
    {
        return (array) DB::table('employee_rates')->where('employee_id', $employee->id)->orderByDesc('id')->first();
    }

    protected function probiRateId(): int
    {
        return (int) DB::table('employee_rates')->where('employee_id', $this->e1->id)->where('effective_date', '2026-01-01')->value('id');
    }

    protected function auditRows(string $action, ?int $rateId = null): array
    {
        return DB::table('audit_logs')->where('auditable_type', \App\Models\EmployeeRate::class)->where('action', $action)
            ->when($rateId, fn ($q) => $q->where('auditable_id', $rateId))
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }
}
