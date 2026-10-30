{{-- Worked, late and undertime summed over the days of a card: now and if approved. --}}
@php
    $min = fn ($v) => \App\Services\AttendanceApprovalService::minutesText($v);
@endphp
@if($totals['days'] > 0)
    <div class="approval-preview-grid approval-totals border rounded p-1 mb-2" role="table" aria-label="Totals of the change, now and if approved">
        <div class="approval-preview-line approval-preview-head" role="row">
            <div role="columnheader">{{ $totals['days'] }} {{ $totals['days'] === 1 ? 'day' : 'days' }}</div><div role="columnheader">Now</div><div role="columnheader">If approved</div>
        </div>
        @foreach(['work' => 'Worked', 'late' => 'Late', 'undertime' => 'Undertime'] as $key => $label)
            <div role="row" data-total="{{ $key }}" @class(['approval-preview-line', 'approval-preview-changed' => $totals['now'][$key] !== $totals['after'][$key]])>
                <div role="rowheader" class="text-muted">{{ $label }}</div>
                <div role="cell">{{ $min($totals['now'][$key]) }}</div>
                <div role="cell">{{ $min($totals['after'][$key]) }}</div>
            </div>
        @endforeach
    </div>
@endif
@if($totals['missing'] > 0)
    <div class="small text-muted mb-2">{{ $totals['missing'] }} {{ $totals['missing'] === 1 ? 'day has' : 'days have' }} no preview or no attendance record and {{ $totals['missing'] === 1 ? 'is' : 'are' }} not in the totals.</div>
@endif
