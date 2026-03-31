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

        @php
            $storedGross = $item->base_pay + ($item->absence_deduction ?? 0) + ($item->late_deduction ?? 0) + ($item->early_deduction ?? 0);
            $simpleGross = $item->daily_rate * $item->required_mandays;
            $isMultiRate = abs($storedGross - $simpleGross) > 1;
        @endphp
        <div class="payslip-row">
            @if($isMultiRate)
                <span>Gross Basic ({{ $item->required_mandays }} days, variable rate)</span>
                <span>&#8369;{{ number_format($storedGross, 2) }}</span>
            @else
                <span>Gross Basic ({{ $item->required_mandays }} days &times; &#8369;{{ number_format($item->daily_rate, 2) }})</span>
                <span>&#8369;{{ number_format($simpleGross, 2) }}</span>
            @endif
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
        @php
            $earnings = $item->earnings_breakdown ?? [];
            // Separate holiday guaranteed earnings from regular earnings
            $regularEarnings = array_filter($earnings, fn($e) => ($e['type'] ?? '') !== 'holiday_guaranteed');
            $holidayGuaranteedEarnings = array_filter($earnings, fn($e) => ($e['type'] ?? '') === 'holiday_guaranteed');
        @endphp
        @if(count($earnings) > 0)
        <div class="payslip-divider"></div>
        <div class="payslip-section-title"><i class="bi bi-plus-circle"></i> Earnings</div>

        @foreach($earnings as $earning)
        <div class="payslip-row">
            <span>
                {{ $earning['name'] }}
                @if(($earning['type'] ?? '') === 'per_day' && isset($earning['days']))
                    <span class="text-muted small">({{ $earning['days'] }} days &times; &#8369;{{ number_format($earning['rate'] ?? 0, 2) }})</span>
                @elseif(in_array($earning['type'] ?? '', ['holiday', 'holiday_guaranteed']) && isset($earning['days']))
                    <span class="text-muted small">({{ $earning['days'] }} day{{ $earning['days'] > 1 ? 's' : '' }} &times; &#8369;{{ number_format($earning['rate'] ?? 0, 2) }})</span>
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

        {{-- DAILY BREAKDOWN Section --}}
        @php $breakdown = $item->daily_breakdown ?? []; @endphp
        @if(count($breakdown) > 0)
        <div class="payslip-divider"></div>
        <div class="payslip-section-title"><i class="bi bi-calendar3"></i> Daily Breakdown</div>

        <div style="overflow-x:auto;">
        <table class="table table-sm table-bordered mb-2" style="font-size:0.78rem;">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th class="text-center">In</th>
                    <th class="text-center">L-Out</th>
                    <th class="text-center">L-In</th>
                    <th class="text-center">Out</th>
                    <th class="text-end">Hours</th>
                    <th class="text-end">Late</th>
                    <th class="text-end">UT</th>
                    <th class="text-end">OT</th>
                    <th class="text-end">Rate</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
            @php
                $totalBdHours = 0;
                $totalBdLate = 0;
                $totalBdUT = 0;
                $totalBdOT = 0;
                $totalBdAmount = 0;
            @endphp
            @foreach($breakdown as $bd)
                @php
                    $isNotCounted = ($bd['not_counted'] ?? false);
                    $rowClass = '';
                    $rowStyle = '';
                    if (($bd['type'] ?? '') === 'rest_day') {
                        $rowClass = 'table-secondary';
                    } elseif (($bd['type'] ?? '') === 'rest_day_worked') {
                        // ALARMING: worked on rest day but NOT counted
                        $rowStyle = 'background: #ff4444 !important; color: #fff !important; font-weight: 700;';
                    } elseif (($bd['type'] ?? '') === 'holiday') {
                        $rowClass = 'table-warning';
                    } elseif (($bd['type'] ?? '') === 'absent') {
                        $rowClass = 'table-danger';
                    }
                    $typeLabel = match($bd['type'] ?? 'regular') {
                        'rest_day' => 'Rest Day',
                        'rest_day_worked' => "\u{26A0} RD-P (NOT COUNTED)",
                        'holiday' => '<i class="bi bi-star-fill text-warning"></i> Holiday',
                        'absent' => 'Absent',
                        default => 'Regular',
                    };

                    // Accumulate totals (only for counted days, exclude holiday rows)
                    $isHolidayRow = (($bd['type'] ?? '') === 'holiday');
                    if (!$isNotCounted && !$isHolidayRow) {
                        $totalBdHours += ($bd['hours'] ?? 0);
                        $totalBdLate += ($bd['late'] ?? 0);
                        $totalBdUT += ($bd['undertime'] ?? 0);
                        $totalBdOT += ($bd['ot'] ?? 0);
                        $totalBdAmount += ($bd['amount'] ?? 0);
                    }
                @endphp
                <tr class="{{ $rowClass }}" style="{{ $rowStyle }}">
                    <td>{{ \Carbon\Carbon::parse($bd['date'])->format('M d') }}</td>
                    <td>{!! $typeLabel !!}</td>
                    <td class="text-center">{{ $bd['time_in'] ?? '—' }}</td>
                    <td class="text-center">{{ $bd['lunch_out'] ?? '—' }}</td>
                    <td class="text-center">{{ $bd['lunch_in'] ?? '—' }}</td>
                    <td class="text-center">{{ $bd['time_out'] ?? '—' }}</td>
                    <td class="text-end">{{ $bd['hours'] ?? 0 }}h</td>
                    <td class="text-end {{ ($bd['late'] ?? 0) > 0 ? ($isNotCounted ? '' : 'text-danger') : '' }}">{{ ($bd['late'] ?? 0) > 0 ? $bd['late'] . 'm' : '—' }}</td>
                    <td class="text-end {{ ($bd['undertime'] ?? 0) > 0 ? ($isNotCounted ? '' : 'text-danger') : '' }}">{{ ($bd['undertime'] ?? 0) > 0 ? $bd['undertime'] . 'm' : '—' }}</td>
                    <td class="text-end {{ ($bd['ot'] ?? 0) > 0 ? ($isNotCounted ? '' : 'text-success') : '' }}">{{ ($bd['ot'] ?? 0) > 0 ? $bd['ot'] . 'm' : '—' }}</td>
                    <td class="text-end">&#8369;{{ number_format($bd['rate'] ?? $item->daily_rate, 2) }}</td>
                    <td class="text-end">@if($isNotCounted)<s style="opacity:0.6">&#8369;0.00</s>@else&#8369;{{ number_format($bd['amount'] ?? 0, 2) }}@endif</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-dark fw-bold" style="font-size:0.78rem;">
                <tr>
                    <td colspan="6" class="text-end">TOTALS</td>
                    <td class="text-end">{{ round($totalBdHours, 1) }}h</td>
                    <td class="text-end">{{ $totalBdLate > 0 ? $totalBdLate . 'm' : '—' }}</td>
                    <td class="text-end">{{ $totalBdUT > 0 ? $totalBdUT . 'm' : '—' }}</td>
                    <td class="text-end">{{ $totalBdOT > 0 ? $totalBdOT . 'm' : '—' }}</td>
                    <td></td>
                    <td class="text-end">&#8369;{{ number_format($totalBdAmount, 2) }}</td>
                </tr>
            </tfoot>
        </table>
        </div>
        @endif

        {{-- HOLIDAY EARNINGS DETAIL (separate table) --}}
        @php $holidayDetail = $item->holiday_earnings_detail ?? []; @endphp
        @if(count($holidayDetail) > 0)
        <div class="payslip-divider"></div>
        <div class="payslip-section-title"><i class="bi bi-star-fill text-warning"></i> Holiday Earnings Detail</div>

        <div style="overflow-x:auto;">
        <table class="table table-sm table-bordered mb-2" style="font-size:0.78rem;">
            <thead style="background: #fff3cd;">
                <tr>
                    <th>Date</th>
                    <th>Holiday</th>
                    <th>Type</th>
                    <th class="text-end">Hours Worked</th>
                    <th class="text-end">Guaranteed Pay (100%)</th>
                </tr>
            </thead>
            <tbody>
            @php $totalHolidayGuaranteed = 0; @endphp
            @foreach($holidayDetail as $hd)
                @php $totalHolidayGuaranteed += ($hd['guaranteed'] ?? 0); @endphp
                <tr class="table-warning">
                    <td>{{ \Carbon\Carbon::parse($hd['date'])->format('M d') }}</td>
                    <td>{{ $hd['holiday_name'] }}</td>
                    <td>{{ ucfirst($hd['holiday_type'] ?? 'regular') }}</td>
                    <td class="text-end">{{ $hd['hours_worked'] ?? 0 }}h / {{ $hd['hours_required'] ?? 8 }}h</td>
                    <td class="text-end text-success fw-bold">+&#8369;{{ number_format($hd['guaranteed'] ?? 0, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot style="background: #fff3cd; font-weight: 700; font-size: 0.78rem;">
                <tr>
                    <td colspan="4" class="text-end">Total Holiday Guaranteed Pay</td>
                    <td class="text-end text-success">+&#8369;{{ number_format($totalHolidayGuaranteed, 2) }}</td>
                </tr>
            </tfoot>
        </table>
        </div>
        <div class="small text-muted mb-2">
            <em>Holiday guaranteed pay (100%) is paid regardless of attendance. The pro-rated worked amount is shown in the daily breakdown above.</em>
        </div>
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
