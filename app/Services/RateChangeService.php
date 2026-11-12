<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeRate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Spec 013. Daily rates are saved here, by the CEO directly and by everyone else as a
 * request the CEO approves or rejects.
 *
 * The one rule for every save and every approval: a rate for [start, end] may shorten an
 * approved rate that starts before it and runs into it, to end the day before the new
 * start. That is what always happened to an open-ended rate, and now happens to a rate with
 * a future end date too. Anything else that overlaps is refused: a rate that starts on or
 * after the new start, and a closed rate the new one would cut in two (the new rate ends
 * before that rate does).
 */
class RateChangeService
{
    /** True when this user's rate changes wait for the CEO. The same rule as attendance changes. */
    public static function mustRequest(?User $user): bool
    {
        return AttendanceChangeRequestService::mustRequest($user);
    }

    /**
     * The approved rates a rate for [start, end] shortens, oldest first, as
     * ['rate' => EmployeeRate, 'until' => 'Y-m-d'], or a refusal.
     *
     * @throws RateChangeRefused
     */
    public function plan(int $employeeId, string $start, ?string $end, ?int $ignoreId = null, bool $lock = false): array
    {
        $query = EmployeeRate::where('employee_id', $employeeId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('effective_date', '<=', $end ?? '9999-12-31')
            ->where(function ($q) use ($start) {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', $start);
            })
            ->orderBy('effective_date');
        if ($lock) {
            $query->lockForUpdate();
        }

        $dayBefore = Carbon::parse($start)->subDay()->toDateString();
        $shorten = [];
        foreach ($query->get() as $rate) {
            $from = $rate->effective_date->toDateString();
            $until = $rate->effective_until?->toDateString();
            $overlaps = 'Rate overlaps with existing rate (effective ' . $rate->rangeText() . ').';

            if ($from >= $start) {
                throw new RateChangeRefused($overlaps . ' A new rate can only end a rate that starts before it. Change or remove that rate first.');
            }
            if ($until !== null && $end !== null && $until > $end) {
                throw new RateChangeRefused($overlaps . ' The new rate ends ' . $this->fmt($end) . ', before that rate ends: ending that rate on '
                    . $this->fmt($dayBefore) . ' would leave ' . $this->fmt(Carbon::parse($end)->addDay()->toDateString()) . ' – ' . $this->fmt($until)
                    . ' without a rate. Leave Until empty, set it on or after ' . $this->fmt($until) . ', or change that rate first.');
            }
            $shorten[] = ['rate' => $rate, 'until' => $dayBefore];
        }

        return $shorten;
    }

    /**
     * The CEO's save: a new rate, or a change of $rate, active at once, with the shortening.
     *
     * @return array{rate: EmployeeRate, shortened: array}
     * @throws RateChangeRefused
     */
    public function saveDirect(Employee $employee, array $fields, User $user, ?EmployeeRate $rate = null): array
    {
        $fields = $this->normalize($fields);

        return DB::transaction(function () use ($employee, $fields, $user, $rate) {
            if ($rate && !$rate->isApproved()) {
                throw new RateChangeRefused('This rate is ' . ($rate->isPending() ? 'waiting for approval' : 'rejected') . ': decide it on Rate Approvals, or remove it.');
            }
            $shortened = $this->shorten($employee->id, $fields, $rate?->id);
            $decision = ['requested_by' => $user->id, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => null];

            if ($rate) {
                $rate->fill($fields + $decision)->save();
            } else {
                $rate = $employee->employeeRates()->create($fields + $decision + ['status' => EmployeeRate::STATUS_APPROVED]);
            }

            return ['rate' => $rate, 'shortened' => $shortened];
        });
    }

    /**
     * A request: a new rate, or a change of the approved rate $replaces, kept as a pending
     * row. Nothing is shortened and nothing counts until the CEO approves it. It is refused
     * now if it could not be saved now, so the person who asks knows at once.
     *
     * @throws RateChangeRefused
     */
    public function submit(Employee $employee, array $fields, User $user, ?EmployeeRate $replaces = null): EmployeeRate
    {
        $fields = $this->normalize($fields);

        return DB::transaction(function () use ($employee, $fields, $user, $replaces) {
            if ($replaces) {
                if (!$replaces->isApproved()) {
                    throw new RateChangeRefused('This rate is ' . ($replaces->isPending() ? 'already waiting for approval' : 'rejected') . '. Remove it and add the rate again.');
                }
                $waiting = EmployeeRate::anyStatus()->with('requester')->where('replaces_rate_id', $replaces->id)
                    ->where('status', EmployeeRate::STATUS_PENDING)->first();
                if ($waiting) {
                    throw new RateChangeRefused('A change to this rate is already waiting for the CEO\'s approval (asked by '
                        . ($waiting->requester->name ?? 'someone') . '). Wait for the decision, or withdraw that change first.');
                }
                if ($this->sameAs($replaces, $fields)) {
                    throw new RateChangeRefused('Nothing to change: the rate already has these values.');
                }
            }
            $this->plan($employee->id, $fields['effective_date'], $fields['effective_until'], $replaces?->id);

            $request = $employee->employeeRates()->create($fields + [
                'status'           => EmployeeRate::STATUS_PENDING,
                'replaces_rate_id' => $replaces?->id,
                'requested_by'     => $user->id,
            ]);
            $this->audit('submitted', $request, $user, $this->summary($request) + ['replaces_rate_id' => $replaces?->id]);

            return $request;
        });
    }

    /**
     * The CEO approves a request: the rates are checked again as they are now, the
     * shortening happens, and the rate counts from now on. A change of an approved rate is
     * written onto that rate (same row), and the request row goes.
     *
     * @return array{rate: EmployeeRate, shortened: array}
     * @throws RateChangeRefused
     */
    public function approve(int $requestId, User $user, ?string $note = null): array
    {
        return DB::transaction(function () use ($requestId, $user, $note) {
            $request = $this->pendingRequest($requestId, $user);
            $fields = $this->fieldsOf($request);
            $decision = ['requested_by' => $request->requested_by, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note];

            if ($request->replaces_rate_id) {
                $target = EmployeeRate::lockForUpdate()->find($request->replaces_rate_id);
                if (!$target) {
                    throw new RateChangeRefused('The rate this change was asked for was removed since. It can only be rejected.', 409);
                }
                $shortened = $this->shorten($request->employee_id, $fields, $target->id);
                $target->fill($fields + $decision)->save();
                // The request has become the rate it changes; its own row is not a rate.
                $request->deleteQuietly();
                $active = $target;
            } else {
                $shortened = $this->shorten($request->employee_id, $fields, $request->id);
                $request->fill($decision + ['status' => EmployeeRate::STATUS_APPROVED])->save();
                $active = $request;
            }

            $this->audit('approved', $active, $user, $this->summary($active) + [
                'request_id'   => $requestId,
                'requested_by' => $request->requested_by,
                'note'         => $note,
                'shortened'    => array_map(fn ($s) => ['rate_id' => $s['rate']->id, 'effective_until' => $s['until']], $shortened),
            ]);

            return ['rate' => $active, 'shortened' => $shortened];
        });
    }

    /**
     * The CEO rejects a request: it stays on the employee's list as rejected, with the note,
     * and nothing else changes.
     *
     * @throws RateChangeRefused
     */
    public function reject(int $requestId, User $user, ?string $note = null): EmployeeRate
    {
        return DB::transaction(function () use ($requestId, $user, $note) {
            $request = $this->pendingRequest($requestId, $user);
            $request->fill([
                'status'        => EmployeeRate::STATUS_REJECTED,
                'decided_by'    => $user->id,
                'decided_at'    => now(),
                'decision_note' => $note,
            ])->save();
            $this->audit('rejected', $request, $user, $this->summary($request) + ['requested_by' => $request->requested_by, 'note' => $note]);

            return $request;
        });
    }

    /**
     * What a request would do if approved now, for the Rate Approvals page: the rate it
     * replaces (or the rate in effect on its start date), the rates it would shorten, or
     * why it cannot be approved.
     */
    public function preview(EmployeeRate $request): array
    {
        $fields = $this->fieldsOf($request);
        $target = $request->replaces_rate_id ? EmployeeRate::find($request->replaces_rate_id) : null;
        if ($request->replaces_rate_id && !$target) {
            return ['now' => null, 'shorten' => [], 'error' => 'The rate this change was asked for was removed since. It can only be rejected.'];
        }
        $now = $target ?? EmployeeRate::where('employee_id', $request->employee_id)
            ->where('effective_date', '<=', $fields['effective_date'])
            ->orderByDesc('effective_date')->first();

        try {
            $shorten = $this->plan($request->employee_id, $fields['effective_date'], $fields['effective_until'], $target?->id ?? $request->id);
        } catch (RateChangeRefused $e) {
            return ['now' => $now, 'shorten' => [], 'error' => $e->getMessage()];
        }

        return ['now' => $now, 'shorten' => $shorten, 'error' => null];
    }

    /** "The rate from Jan 01, 2026 now ends Nov 15, 2026." for each shortened rate. */
    public function shortenedText(array $shortened, string $verb = 'now ends'): string
    {
        return implode(' ', array_map(
            fn ($s) => 'The rate from ' . $s['rate']->effective_date->format('M d, Y') . ' ' . $verb . ' ' . $this->fmt($s['until']) . '.',
            $shortened
        ));
    }

    /* ── Internals ── */

    protected function pendingRequest(int $requestId, User $user): EmployeeRate
    {
        if (!AttendanceApprovalService::isCeoNow($user)) {
            throw new RateChangeRefused('Only the CEO can decide rate changes.', 403);
        }
        $request = EmployeeRate::anyStatus()->lockForUpdate()->find($requestId);
        if (!$request) {
            throw new RateChangeRefused('This rate change no longer exists: it was withdrawn.', 404);
        }
        if (!$request->isPending()) {
            throw new RateChangeRefused('This rate change was already ' . $request->status . '.', 409);
        }

        return $request;
    }

    /** Applies the plan: each rate it names ends the day before the new start (audited as an update). */
    protected function shorten(int $employeeId, array $fields, ?int $ignoreId): array
    {
        $shortened = $this->plan($employeeId, $fields['effective_date'], $fields['effective_until'], $ignoreId, true);
        foreach ($shortened as $s) {
            $s['rate']->effective_until = $s['until'];
            $s['rate']->save();
        }

        return $shortened;
    }

    protected function normalize(array $fields): array
    {
        return [
            'daily_rate'      => $fields['daily_rate'],
            'effective_date'  => Carbon::parse($fields['effective_date'])->toDateString(),
            'effective_until' => empty($fields['effective_until']) ? null : Carbon::parse($fields['effective_until'])->toDateString(),
            'remarks'         => $fields['remarks'] ?? null,
        ];
    }

    protected function fieldsOf(EmployeeRate $rate): array
    {
        return [
            'daily_rate'      => $rate->daily_rate,
            'effective_date'  => $rate->effective_date->toDateString(),
            'effective_until' => $rate->effective_until?->toDateString(),
            'remarks'         => $rate->remarks,
        ];
    }

    protected function sameAs(EmployeeRate $rate, array $fields): bool
    {
        $now = $this->fieldsOf($rate);

        return round((float) $now['daily_rate'], 2) === round((float) $fields['daily_rate'], 2)
            && $now['effective_date'] === $fields['effective_date']
            && $now['effective_until'] === $fields['effective_until']
            && (string) $now['remarks'] === (string) $fields['remarks'];
    }

    protected function summary(EmployeeRate $rate): array
    {
        return [
            'employee_id'     => $rate->employee_id,
            'daily_rate'      => number_format((float) $rate->daily_rate, 2, '.', ''),
            'effective_date'  => $rate->effective_date->toDateString(),
            'effective_until' => $rate->effective_until?->toDateString(),
        ];
    }

    /** One line of the audit log for a submission or a decision, beside the created/updated rows. */
    protected function audit(string $action, EmployeeRate $rate, User $user, array $values): void
    {
        $name = $rate->employee->display_name ?? ('#' . $rate->employee_id);
        AuditLog::create([
            'user_id'        => $user->id,
            'action'         => $action,
            'auditable_type' => EmployeeRate::class,
            'auditable_id'   => $rate->id,
            'old_values'     => null,
            'new_values'     => $values,
            'ip_address'     => request()?->ip(),
            'description'    => 'Daily rate ' . $action . ': ' . $name . ' ₱' . number_format((float) $rate->daily_rate, 2) . ' from ' . $rate->rangeText(),
        ]);
    }

    protected function fmt(string $date): string
    {
        return Carbon::parse($date)->format('M d, Y');
    }
}
