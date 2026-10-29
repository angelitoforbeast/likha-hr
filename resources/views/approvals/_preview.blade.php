{{-- The day now and as Approve would save it. Computed by the approval's own code and rolled back. --}}
@php
    $fmtTime = fn ($time) => $time ? \Carbon\Carbon::parse('2000-01-01 ' . $time)->format('g:i A') : 'none';
    $minutes = fn ($value) => \App\Services\AttendanceApprovalService::minutesText($value);
    $yesNo = fn ($value) => $value === null ? '–' : ($value ? 'Yes' : 'No');
    $asked = $entry['rows']->pluck('field');
@endphp
<div class="approval-preview border rounded p-2" data-preview="{{ $preview['error'] ? 'unavailable' : 'ok' }}">
    <div class="small fw-semibold mb-1">Preview</div>
    @if(!$preview['error'] && ($entry['locked'] || !empty($entry['blocks'])))
        <div class="small text-danger mb-1 approval-preview-blocked">Approve is not allowed now. This is what it would save once it is.</div>
    @endif
    @if($preview['error'])
        <div class="small text-muted approval-preview-error">Preview not available: {{ $preview['error'] }}</div>
    @else
        @php
            $now = $preview['now'];
            $after = $preview['after'];
            $lines = [
                ['Time in', $fmtTime($now['time_in']), $fmtTime($after['time_in'])],
                ['Lunch out', $fmtTime($now['lunch_out']), $fmtTime($after['lunch_out'])],
                ['Lunch in', $fmtTime($now['lunch_in']), $fmtTime($after['lunch_in'])],
                ['Time out', $fmtTime($now['time_out']), $fmtTime($after['time_out'])],
            ];
            if ($asked->contains('shift_id') || $now['shift_id'] !== $after['shift_id']) {
                $lines[] = ['Shift', $now['shift_name'] ?? 'none', $after['shift_name'] ?? 'none'];
            }
            $lines[] = ['Lunch', $minutes($now['lunch']), $minutes($after['lunch'])];
            $lines[] = ['Worked', $minutes($now['work']), $minutes($after['work'])];
            $lines[] = ['Late', $minutes($now['late']), $minutes($after['late'])];
            $lines[] = ['Undertime', $minutes($now['undertime']), $minutes($after['undertime'])];
            $lines[] = ['Overtime worked', $minutes($now['overtime']), $minutes($after['overtime'])];
            $approvedOt = fn ($day) => !$day['has_row'] ? '–' : ($day['approved_overtime'] === null ? 'not set' : $minutes($day['approved_overtime']));
            $lines[] = ['Overtime approved', $approvedOt($now), $approvedOt($after)];
            if ($asked->contains('rest_day') || $now['rest_day'] !== $after['rest_day']) {
                $lines[] = ['Rest day', $yesNo($now['rest_day']), $yesNo($after['rest_day'])];
            }
            if ($asked->contains('sil') || $now['sil'] !== $after['sil']) {
                $lines[] = ['SIL', $yesNo($now['sil']), $yesNo($after['sil'])];
            }
            $lines[] = ['Needs review', $yesNo($now['needs_review']), $yesNo($after['needs_review'])];
        @endphp
        {{-- Three narrow columns of a grid, not a table: the entries stay cards that fit a phone. --}}
        <div class="approval-preview-grid mb-1" role="table" aria-label="The day now and if approved">
            <div class="approval-preview-line approval-preview-head" role="row">
                <div role="columnheader"></div><div role="columnheader">Now</div><div role="columnheader">If approved</div>
            </div>
            @foreach($lines as [$label, $nowText, $afterText])
                <div role="row" data-line="{{ $label }}" @class(['approval-preview-line', 'approval-preview-changed' => $nowText !== $afterText])>
                    <div role="rowheader" class="text-muted">{{ $label }}</div>
                    <div role="cell">{{ $nowText }}</div>
                    <div role="cell">{{ $afterText }}</div>
                </div>
            @endforeach
        </div>
        @if(!$now['has_row'] && !$after['has_row'])
            <div class="small text-muted">No attendance record, now or after approval.</div>
        @endif
        @if($now['notes'] === $after['notes'])
            @if($after['notes'])
                <div class="small text-muted">Note: {{ $after['notes'] }}</div>
            @endif
        @else
            <div class="small text-muted">Note now: {{ $now['notes'] ?? 'none' }}</div>
            <div class="small approval-preview-changed-note">Note if approved: {{ $after['notes'] ?? 'none' }}</div>
        @endif
        @if(!empty($preview['warnings']))
            <ul class="small text-danger mb-0 mt-1 ps-3 approval-preview-warnings">
                @foreach($preview['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
