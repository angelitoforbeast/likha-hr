@extends('layouts.app')

@section('title', 'Payslip — ' . ($item->employee->display_name ?? 'Employee'))
@section('page-title', 'Payslip')

@section('content')
<style>
    @media print {
        .no-print { display: none !important; }
        .payslip-card { box-shadow: none !important; border: 1px solid #ddd !important; }
    }
    .payslip-card { max-width: 700px; margin: 0 auto; }
    .payslip-header { background: linear-gradient(135deg, #1a3c5e 0%, #2c5f2d 100%); color: #fff; }
    .payslip-section-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: #6c757d; border-bottom: 2px solid #e9ecef; padding-bottom: 4px; margin-bottom: 8px; }
    .payslip-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 0.85rem; }
    .payslip-row.indent { padding-left: 16px; color: #6c757d; font-size: 0.82rem; }
    .payslip-row.sub-deduction { padding-left: 16px; color: #dc3545; font-size: 0.82rem; }
    .payslip-row.total { font-weight: 700; border-top: 2px solid #333; padding-top: 6px; margin-top: 4px; font-size: 0.95rem; }
    .payslip-row.net-pay { font-weight: 700; border-top: 3px double #333; padding-top: 8px; margin-top: 8px; font-size: 1.15rem; color: #1a3c5e; }
    .payslip-divider { border-top: 1px dashed #ccc; margin: 12px 0; }
</style>

<div class="no-print mb-3 d-flex justify-content-between align-items-center">
    <a href="{{ route('payroll.show', $run) }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-arrow-left"></i> Back to Payroll Run
    </a>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-printer"></i> Print Payslip
    </button>
</div>

<div class="card border-0 shadow-sm payslip-card">
    {{-- Header --}}
    <div class="payslip-header p-4">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h4 class="mb-1 fw-bold">PAYSLIP</h4>
                <div class="small opacity-75">
                    Cutoff: {{ $run->cutoff_start->format('M d') }} — {{ $run->cutoff_end->format('M d, Y') }}
                </div>
            </div>
            <div class="text-end">
                <span class="badge {{ $run->status === 'final' ? 'bg-success' : 'bg-warning text-dark' }}">
                    {{ strtoupper($run->status) }}
                </span>
            </div>
        </div>
    </div>

    {{-- Employee Info --}}
    <div class="p-4 pb-0">
        <div class="row mb-3">
            <div class="col-6">
                <div class="small text-muted">Employee</div>
                <div class="fw-bold fs-6">{{ $item->employee->display_name ?? '—' }}</div>
            </div>
            <div class="col-3">
                <div class="small text-muted">Department</div>
                <div class="fw-semibold">{{ $item->employee->department->name ?? '—' }}</div>
            </div>
            <div class="col-3 text-end">
                <div class="small text-muted">Daily Rate</div>
                <div class="fw-semibold">&#8369;{{ number_format($item->daily_rate, 2) }}</div>
            </div>
        </div>

        {{-- Attendance Summary --}}
        <div class="row mb-3">
            <div class="col-3 text-center">
                <div class="small text-muted">Required Days</div>
                <div class="fw-bold fs-5">{{ $item->required_mandays }}</div>
            </div>
            <div class="col-3 text-center">
                <div class="small text-muted">Days Worked</div>
                <div class="fw-bold fs-5 text-success">{{ $item->days_worked }}</div>
            </div>
            <div class="col-3 text-center">
                <div class="small text-muted">Absent</div>
                <div class="fw-bold fs-5 {{ $item->absent_days > 0 ? 'text-danger' : '' }}">{{ $item->absent_days }}</div>
            </div>
            <div class="col-3 text-center">
                <div class="small text-muted">Late + UT (min)</div>
                <div class="fw-bold fs-5 {{ ($item->total_late_minutes + ($item->total_early_minutes ?? 0)) > 0 ? 'text-warning' : '' }}">
                    {{ $item->total_late_minutes + ($item->total_early_minutes ?? 0) }}
                </div>
            </div>
        </div>

        <div class="payslip-divider"></div>

        {{-- BASIC PAY Section --}}
        <div class="payslip-section-title"><i class="bi bi-wallet2"></i> Basic Pay</div>

        <div class="payslip-row">
            <span>Gross Basic ({{ $item->required_mandays }} days &times; &#8369;{{ number_format($item->daily_rate, 2) }})</span>
            <span>&#8369;{{ number_format($item->daily_rate * $item->required_mandays, 2) }}</span>
        </div>

        @if($item->absent_days > 0)
        <div class="payslip-row sub-deduction">
            <span>Less: Absences ({{ $item->absent_days }} day{{ $item->absent_days > 1 ? 's' : '' }})</span>
            <span>(&#8369;{{ number_format($item->absence_deduction, 2) }})</span>
        </div>
        @endif

        @if($item->total_late_minutes > 0)
        <div class="payslip-row sub-deduction">
            <span>Less: Tardiness ({{ $item->total_late_minutes }} min)</span>
            <span>(&#8369;{{ number_format($item->late_deduction, 2) }})</span>
        </div>
        @endif

        @if(($item->total_early_minutes ?? 0) > 0)
        <div class="payslip-row sub-deduction">
            <span>Less: Undertime ({{ $item->total_early_minutes }} min)</span>
            <span>(&#8369;{{ number_format($item->early_deduction, 2) }})</span>
        </div>
        @endif

        <div class="payslip-row total">
            <span>BASIC PAY</span>
            <span>&#8369;{{ number_format($item->base_pay, 2) }}</span>
        </div>

        {{-- EARNINGS Section (if any) --}}
        @php $earnings = $item->earnings_breakdown ?? []; @endphp
        @if(count($earnings) > 0)
        <div class="payslip-divider"></div>
        <div class="payslip-section-title"><i class="bi bi-plus-circle"></i> Earnings</div>

        @foreach($earnings as $earning)
        <div class="payslip-row">
            <span>
                {{ $earning['name'] }}
                @if(($earning['type'] ?? '') === 'per_day' && isset($earning['days']))
                    <span class="text-muted small">({{ $earning['days'] }} days &times; &#8369;{{ number_format($earning['rate'] ?? 0, 2) }})</span>
                @endif
            </span>
            <span class="text-success">+&#8369;{{ number_format($earning['amount'], 2) }}</span>
        </div>
        @endforeach

        <div class="payslip-row" style="font-weight:600; border-top:1px solid #e9ecef; padding-top:4px; margin-top:4px;">
            <span>Total Earnings</span>
            <span class="text-success">+&#8369;{{ number_format($item->total_earnings, 2) }}</span>
        </div>
        @endif

        {{-- DEDUCTIONS Section (if any) --}}
        @php $deductions = $item->deductions_breakdown ?? []; @endphp
        @if(count($deductions) > 0)
        <div class="payslip-divider"></div>
        <div class="payslip-section-title"><i class="bi bi-dash-circle"></i> Deductions</div>

        @foreach($deductions as $deduction)
        <div class="payslip-row">
            <span>{{ $deduction['name'] }}</span>
            <span class="text-danger">(&#8369;{{ number_format($deduction['amount'], 2) }})</span>
        </div>
        @endforeach

        <div class="payslip-row" style="font-weight:600; border-top:1px solid #e9ecef; padding-top:4px; margin-top:4px;">
            <span>Total Deductions</span>
            <span class="text-danger">(&#8369;{{ number_format($item->total_deductions, 2) }})</span>
        </div>
        @endif

        {{-- ADJUSTMENTS (if any) --}}
        @if(($item->adjustments ?? 0) != 0)
        <div class="payslip-divider"></div>
        <div class="payslip-section-title"><i class="bi bi-sliders"></i> Adjustments</div>
        <div class="payslip-row">
            <span>Manual Adjustment</span>
            <span class="{{ $item->adjustments >= 0 ? 'text-success' : 'text-danger' }}">
                {{ $item->adjustments >= 0 ? '+' : '' }}&#8369;{{ number_format($item->adjustments, 2) }}
            </span>
        </div>
        @if($item->notes)
        <div class="small text-muted ps-2 mb-1"><em>{{ $item->notes }}</em></div>
        @endif
        @endif

        {{-- NET PAY --}}
        <div class="payslip-divider"></div>
        <div class="payslip-row net-pay">
            <span>NET PAY</span>
            <span>&#8369;{{ number_format($item->final_pay, 2) }}</span>
        </div>

        <div class="mt-3 mb-3 small text-muted text-center">
            Generated on {{ now()->format('M d, Y h:i A') }} &bull; Payroll Run #{{ $run->id }}
        </div>
    </div>
</div>
@endsection
