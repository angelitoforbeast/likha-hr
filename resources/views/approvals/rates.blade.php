@extends('layouts.app')

@section('title', 'Rate Approvals')
@section('page-title', 'Rate Approvals')

@push('styles')
<style>
    /* Names, remarks and notes are free text: wrap them instead of widening the page. */
    .rate-request { overflow-wrap: anywhere; }
    .rate-value { font-weight: 600; }
</style>
@endpush

@section('content')
@php
    $peso = fn ($amount) => '₱' . number_format((float) $amount, 2);
@endphp

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ url('/approvals/rates') }}" class="btn btn-sm {{ $decided ? 'btn-outline-secondary' : 'btn-primary' }}">Pending</a>
    <a href="{{ url('/approvals/rates?status=decided') }}" class="btn btn-sm {{ $decided ? 'btn-primary' : 'btn-outline-secondary' }}">Decided</a>
    <a href="{{ url('/approvals') }}" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-check2-square"></i> Attendance approvals</a>
</div>

@if(!$decided)
    <p class="small text-muted">Daily rates saved by HR and admins. A rate here counts nowhere (payroll, current rate, reports) until you approve it. Approving checks the employee's rates again as they are now.</p>
@endif

@forelse($items as $item)
    @php
        $rate = $item['rate'];
        $preview = $item['preview'];
        $now = $preview['now'] ?? null;
    @endphp
    <div class="rate-request card border-0 shadow-sm mb-3" data-rate-request="{{ $rate->id }}">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
                <div>
                    <span class="fw-bold">{{ $rate->employee->display_name ?? ('Employee #' . $rate->employee_id) }}</span>
                    <span class="text-muted">&middot;</span>
                    <span class="badge bg-light text-dark border">{{ $rate->replaces_rate_id ? 'Change of a rate' : 'New rate' }}</span>
                    @if($decided)
                        @if($rate->isRejected())
                            <span class="badge bg-danger">Rejected</span>
                        @else
                            <span class="badge bg-success">Approved</span>
                        @endif
                    @endif
                </div>
                @if(($rate->employee->status ?? 'active') !== 'active')
                    <span class="badge bg-warning text-dark">Inactive employee</span>
                @endif
            </div>

            @if($decided)
                <div><span class="rate-value">{{ $peso($rate->daily_rate) }}</span> from {{ $rate->rangeText() }}</div>
                <div class="small text-muted">
                    Asked by {{ $rate->requester->name ?? 'Unknown' }} on {{ $rate->created_at->format('M d, Y g:i A') }};
                    {{ $rate->isRejected() ? 'rejected' : 'approved' }} by {{ $rate->decider->name ?? 'Unknown' }} on {{ $rate->decided_at?->format('M d, Y g:i A') }}.
                    @if($rate->decision_note) Note: {{ $rate->decision_note }} @endif
                </div>
            @else
                <div class="mb-1">
                    @if($now)
                        <span class="rate-value">{{ $peso($now->daily_rate) }}</span>
                        <span class="text-muted small">({{ $now->rangeText() }})</span>
                    @else
                        <span class="text-muted">No rate yet</span>
                    @endif
                    <span class="mx-1">→</span>
                    <span class="rate-value">{{ $peso($rate->daily_rate) }}</span>
                    <span class="small">from {{ $rate->rangeText() }}</span>
                </div>
                @if($rate->remarks)
                    <div class="small">Remarks: {{ $rate->remarks }}</div>
                @endif
                <div class="small text-muted mb-2">Asked by {{ $rate->requester->name ?? 'Unknown' }} on {{ $rate->created_at->format('M d, Y g:i A') }}</div>

                @if($preview['error'])
                    <div class="small text-danger fw-semibold mb-2"><i class="bi bi-exclamation-triangle"></i> Cannot be approved now: {{ $preview['error'] }}</div>
                @else
                    @foreach($preview['shorten'] as $s)
                        <div class="small mb-1"><i class="bi bi-scissors"></i> When approved, the rate from {{ $s['rate']->effective_date->format('M d, Y') }} ({{ $peso($s['rate']->daily_rate) }}{{ $s['rate']->effective_until ? ', until ' . $s['rate']->effective_until->format('M d, Y') : ', ongoing' }}) ends {{ \Carbon\Carbon::parse($s['until'])->format('M d, Y') }}.</div>
                    @endforeach
                @endif

                <div class="d-flex flex-wrap align-items-start gap-2 mt-2">
                    <form method="POST" action="{{ route('approvals.rates.approve', $rate->id) }}" class="d-flex flex-wrap align-items-center gap-2">
                        @csrf
                        <input type="text" name="note" class="form-control form-control-sm" style="max-width: 14rem" maxlength="500" placeholder="Note (optional)" aria-label="Note for approving">
                        <button type="submit" class="btn btn-success btn-sm" @disabled($preview['error'])><i class="bi bi-check-lg"></i> Approve</button>
                    </form>
                    <form method="POST" action="{{ route('approvals.rates.reject', $rate->id) }}" class="d-flex flex-wrap align-items-center gap-2" onsubmit="return confirm('Reject this rate change?')">
                        @csrf
                        <input type="text" name="note" class="form-control form-control-sm" style="max-width: 16rem" maxlength="500" placeholder="Reason for rejecting (optional)" aria-label="Reason for rejecting">
                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg"></i> Reject</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
@empty
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-4">
        {{ $decided ? 'No rate change has been decided yet.' : 'No rate change is waiting for approval.' }}
    </div></div>
@endforelse
@endsection
