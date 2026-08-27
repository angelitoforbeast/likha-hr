@extends('layouts.app')

@section('title', 'Attendance Calendar')
@section('page-title', 'Attendance Calendar')

@section('content')
<style>
    .cal-table { font-size: .8rem; }
    .cal-table th { text-align: center; padding: .35rem .25rem; }
    .cal-table td { text-align: center; padding: .35rem .25rem; cursor: pointer; position: relative; min-width: 32px; }
    .cal-table td:hover { background: #e9ecef; }
    .cal-cell-present { background: #d1e7dd !important; color: #0f5132; font-weight: 600; }
    .cal-cell-undertime { background: #fff3cd !important; color: #664d03; font-weight: 600; }
    .cal-cell-absent { background: #f8d7da !important; color: #842029; font-weight: 600; }
    .cal-cell-dayoff { background: #cfe2ff !important; color: #084298; font-weight: 600; }
    .cal-cell-rdpresent { background: #ff4444 !important; color: #fff !important; font-weight: 700; animation: rdp-blink 1.2s ease-in-out infinite; }
    .cal-cell-sil { background: #a0e7e5 !important; color: #007a72 !important; font-weight: 700; }
    @keyframes rdp-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
    .cal-cell-today { border: 2px solid #0d6efd !important; }
    .cal-cell-edited::after { content: ''; position: absolute; top: 2px; right: 2px; width: 6px; height: 6px; background: #6f42c1; border-radius: 50%; }
    .cal-legend { display: inline-flex; align-items: center; gap: .25rem; margin-right: 1rem; font-size: .8rem; }
    .cal-legend-box { width: 16px; height: 16px; border-radius: 3px; display: inline-block; }
    .employee-name-col { white-space: nowrap; font-weight: 600; font-size: .8rem; min-width: 140px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; }
    .time-editable { cursor: pointer; border-bottom: 1px dashed #999; padding: 1px 3px; border-radius: 2px; }
    .time-editable:hover { background: #e2e6ea; }
    .time-edited { color: #6f42c1 !important; font-weight: 600; background: #fff3cd; border: 1px solid #ffc107; border-radius: 3px; padding: 1px 4px; }
    .time-input.time-input-edited { border-color: #ffc107; box-shadow: 0 0 0 .1rem rgba(255, 193, 7, .25); background: #fff8e1; font-weight: 600; }
    .time-display-value { cursor: pointer; padding: 2px 4px; border-radius: 3px; border-bottom: 1px dashed #999; }
    .time-display-value:hover { background: #e9ecef; }
    .time-display-value.time-display-edited { background: #fff3cd; border: 1px solid #ffc107; color: #6f42c1; font-weight: 600; }
    .override-dot { color: #6f42c1; font-size: .6rem; vertical-align: super; }
    .override-history { font-size: .78rem; background: #f8f9fa; border-radius: 4px; padding: 6px 8px; margin-top: 4px; }
    .override-history .ov-entry { border-bottom: 1px solid #e9ecef; padding: 3px 0; }
    .override-history .ov-entry:last-child { border-bottom: none; }
    .dayoff-action-btn { font-size: .75rem; padding: 2px 8px; }
    /* Shift overlay inside each cell (when Show Shift toggle is on) */
    .cal-shift-line { display: block; font-size: .6rem; font-weight: 500; line-height: 1; margin-top: 2px; opacity: .85; white-space: nowrap; }
    .cal-shift-name { font-weight: 600; }
    .cal-shift-time { font-size: .55rem; opacity: .8; }
    .with-shift td { min-width: 55px; }
    .date-col-header { cursor: pointer; transition: background 0.15s; }
    .date-col-header:hover { background: #d0ebff !important; color: #0d6efd !important; }
    .date-col-header:hover i { opacity: 1 !important; }
</style>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ url('/attendance-calendar') }}" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">View</label>
                <select name="filter_type" id="filterType" class="form-select form-select-sm" onchange="toggleFilters()">
                    <option value="all" {{ $filterType === 'all' ? 'selected' : '' }}>All Employees</option>
                    <option value="department" {{ $filterType === 'department' ? 'selected' : '' }}>By Department</option>
                    <option value="employee" {{ $filterType === 'employee' ? 'selected' : '' }}>By Employee</option>
                </select>
            </div>
            <div class="col-auto" id="deptFilterCol" style="{{ $filterType === 'department' ? '' : 'display:none' }}">
                <label class="form-label small fw-semibold mb-1">Department</label>
                <select name="department_id" class="form-select form-select-sm">
                    <option value="">Select...</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" {{ $departmentId == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto" id="empFilterCol" style="{{ $filterType === 'employee' ? '' : 'display:none' }}">
                <label class="form-label small fw-semibold mb-1">Employee</label>
                <select name="employee_id" class="form-select form-select-sm">
                    <option value="">Select...</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}" {{ $employeeId == $emp->id ? 'selected' : '' }}>{{ $emp->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="{{ $dateFrom }}">
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="{{ $dateTo }}">
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1 d-block">&nbsp;</label>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="show_shift" value="1" id="showShiftToggle" {{ !empty($showShift) ? 'checked' : '' }}>
                    <label class="form-check-label small" for="showShiftToggle">Show Shift</label>
                </div>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> View</button>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-warning" id="computeAttendanceBtn" title="Recompute attendance for the visible date range (respects manual overrides)">
                    <i class="bi bi-calculator"></i> <span id="computeAttendanceLabel">Compute Attendance</span>
                </button>
            </div>
            <div class="col-auto">
                @php
                    $rangeDays = \Carbon\Carbon::parse($dateFrom)->diffInDays(\Carbon\Carbon::parse($dateTo)) + 1;
                    $prevFrom = \Carbon\Carbon::parse($dateFrom)->subDays($rangeDays)->format('Y-m-d');
                    $prevTo = \Carbon\Carbon::parse($dateTo)->subDays($rangeDays)->format('Y-m-d');
                    $nextFrom = \Carbon\Carbon::parse($dateFrom)->addDays($rangeDays)->format('Y-m-d');
                    $nextTo = \Carbon\Carbon::parse($dateTo)->addDays($rangeDays)->format('Y-m-d');
                @endphp
                <a href="{{ url('/attendance-calendar') }}?filter_type={{ $filterType }}&department_id={{ $departmentId }}&employee_id={{ $employeeId }}&date_from={{ $prevFrom }}&date_to={{ $prevTo }}"
                   class="btn btn-sm btn-outline-secondary" title="Previous period"><i class="bi bi-chevron-left"></i></a>
                <a href="{{ url('/attendance-calendar') }}?filter_type={{ $filterType }}&department_id={{ $departmentId }}&employee_id={{ $employeeId }}&date_from={{ $nextFrom }}&date_to={{ $nextTo }}"
                   class="btn btn-sm btn-outline-secondary" title="Next period"><i class="bi bi-chevron-right"></i></a>
            </div>
        </form>
    </div>
</div>

{{-- Legend --}}
<div class="mb-2">
    <span class="cal-legend"><span class="cal-legend-box" style="background:#d1e7dd"></span> Present</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#fff3cd"></span> Undertime</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#f8d7da"></span> Absent</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#cfe2ff"></span> Rest Day</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#ff4444"></span> <strong style="color:#ff4444">RD-P (Rest Day Worked!)</strong></span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#a0e7e5"></span> SIL (Service Incentive Leave)</span>
    <span class="cal-legend"><span style="display:inline-block;width:8px;height:8px;background:#6f42c1;border-radius:50%;margin-right:2px"></span> Edited</span>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <h6 class="mb-0">
            {{ \Carbon\Carbon::parse($dateFrom)->format('M d, Y') }} — {{ \Carbon\Carbon::parse($dateTo)->format('M d, Y') }}
            <span class="text-muted small ms-2">({{ $totalDays }} days, {{ count($calendarData) }} employee(s))</span>
        </h6>
    </div>
    <div class="card-body p-0" style="overflow-x: auto;">
        @if(count($calendarData) > 0)
        <table class="table table-bordered cal-table mb-0 {{ !empty($showShift) ? 'with-shift' : '' }}">
            <thead class="table-light">
                <tr>
                    <th class="text-start" style="min-width:140px; position:sticky; left:0; background:#f8f9fa; z-index:2;">Employee</th>
                    @foreach($dates as $idx => $dayDate)
                        @php
                            $isToday = $dayDate->isToday();
                            $isSunday = $dayDate->dayOfWeek === 0;
                            $isSaturday = $dayDate->dayOfWeek === 6;
                        @endphp
                        <th class="date-col-header {{ $isToday ? 'bg-primary text-white' : ($isSunday ? 'text-danger' : ($isSaturday ? 'text-primary' : '')) }}"
                            role="button"
                            data-date="{{ $dayDate->format('Y-m-d') }}"
                            data-day-label="{{ $dayDate->format('M d, Y (D)') }}"
                            onclick="openBulkActionModal(this)"
                            title="Click to bulk-apply an action to all employees on this date">
                            {{ $dayDate->format('j') }}<br>
                            <small>{{ $dayDate->format('D') }}</small>
                            <i class="bi bi-list-check d-block small" style="font-size:.65rem; opacity:.6"></i>
                        </th>
                    @endforeach
                    <th title="Present">P</th>
                    <th title="Absent">A</th>
                    <th title="Undertime">UT</th>
                </tr>
            </thead>
            <tbody>
                @foreach($calendarData as $empCal)
                @php
                    $countP = 0; $countA = 0; $countUT = 0;
                @endphp
                <tr>
                    <td class="employee-name-col text-start" style="position:sticky; left:0; background:#fff; z-index:1;" title="{{ $empCal['employee']->display_name }}">
                        <a href="{{ route('employees.edit', $empCal['employee']) }}" class="text-decoration-none">
                            {{ $empCal['employee']->display_name }}
                        </a>
                    </td>
                    @foreach($dates as $idx => $dayDate)
                        @php
                            $dayInfo = $empCal['days'][$idx];
                            $cellClass = '';
                            $cellText = '';
                            $isToday = $dayDate->isToday();
                            $att = $dayInfo['attendance'];
                            $hasOverrides = $dayInfo['has_overrides'] ?? false;

                            switch ($dayInfo['status']) {
                                case 'present':
                                    $cellClass = 'cal-cell-present';
                                    $cellText = 'P';
                                    $countP++;
                                    break;
                                case 'undertime':
                                    $cellClass = 'cal-cell-undertime';
                                    $cellText = 'UT';
                                    $countP++;
                                    $countUT++;
                                    break;
                                case 'rd_present':
                                    $cellClass = 'cal-cell-rdpresent';
                                    $cellText = 'RD-P';
                                    break;
                                case 'absent':
                                    $cellClass = 'cal-cell-absent';
                                    $cellText = 'A';
                                    $countA++;
                                    break;
                                case 'day_off':
                                    $cellClass = 'cal-cell-dayoff';
                                    $cellText = 'RD';
                                    break;
                                case 'sil':
                                    $cellClass = 'cal-cell-sil';
                                    $cellText = 'SIL';
                                    break;
                            }

                            if ($isToday) $cellClass .= ' cal-cell-today';
                            if ($hasOverrides) $cellClass .= ' cal-cell-edited';

                            // Build data attributes for modal
                            $dataAttrs = 'data-date="' . $dayInfo['date'] . '"'
                                . ' data-status="' . $dayInfo['status'] . '"'
                                . ' data-employee="' . e($empCal['employee']->display_name) . '"'
                                . ' data-employee-id="' . $empCal['employee']->id . '"';

                            // Shift info for this date (from shift assignment or fallback default)
                            $shiftForCell = $dayInfo['shift'] ?? null;
                            $shiftIdForCell = $dayInfo['shift_id'] ?? null;
                            if ($shiftForCell) {
                                $dataAttrs .= ' data-shift-name="' . e($shiftForCell['name']) . '"'
                                    . ' data-shift-schedule="' . e($shiftForCell['start'] . ' — ' . $shiftForCell['end']) . '"'
                                    . ' data-shift-lunch="' . e($shiftForCell['lunch_start'] . ' — ' . $shiftForCell['lunch_end']) . '"';
                                if ($shiftIdForCell) {
                                    $dataAttrs .= ' data-shift-id="' . $shiftIdForCell . '"';
                                }
                            }

                            // SIL info for this date (if any) — used by the modal to prefill state.
                            $silInfo = $dayInfo['sil'] ?? null;
                            $dataAttrs .= ' data-sil-eligible="' . ($empCal['employee']->sil_eligible ? '1' : '0') . '"';
                            $silBalance = $empCal['employee']->getSilBalance((int) $dayDate->year);
                            $silRemaining = $empCal['employee']->getSilRemainingDays((int) $dayDate->year);
                            $dataAttrs .= ' data-sil-total="' . number_format((float) $silBalance->total_days, 2, '.', '') . '"'
                                . ' data-sil-remaining="' . number_format((float) $silRemaining, 2, '.', '') . '"'
                                . ' data-sil-year="' . (int) $dayDate->year . '"';
                            if ($silInfo) {
                                $dataAttrs .= ' data-sil-applied="1"'
                                    . ' data-sil-reason="' . e($silInfo['reason']) . '"'
                                    . ' data-sil-by="' . e($silInfo['applied_by']) . '"'
                                    . ' data-sil-at="' . e($silInfo['applied_at']) . '"';
                            } else {
                                $dataAttrs .= ' data-sil-applied="0"';
                            }

                            // Edit-history attrs — always emitted so absent/day-off cells with day-off
                            // logs also render their history in the modal.
                            $ovDetailsJson = json_encode($dayInfo['override_details'] ?? []);
                            $editedFields = collect($dayInfo['override_details'] ?? [])
                                ->where('kind', 'time')
                                ->pluck('field')
                                ->unique()
                                ->toArray();
                            $dataAttrs .= ' data-has-overrides="' . ($hasOverrides ? '1' : '0') . '"'
                                . ' data-override-details="' . e($ovDetailsJson) . '"'
                                . ' data-edited-fields="' . e(implode(',', $editedFields)) . '"';

                            if ($att) {
                                $dataAttrs .= ' data-att-id="' . $att->id . '"'
                                    . ' data-shift="' . e($att->shift->name ?? 'N/A') . '"'
                                    . ' data-time-in="' . ($att->time_in ? \Carbon\Carbon::parse($att->time_in)->format('H:i') : '') . '"'
                                    . ' data-lunch-out="' . ($att->lunch_out ? \Carbon\Carbon::parse($att->lunch_out)->format('H:i') : '') . '"'
                                    . ' data-lunch-in="' . ($att->lunch_in ? \Carbon\Carbon::parse($att->lunch_in)->format('H:i') : '') . '"'
                                    . ' data-time-out="' . ($att->time_out ? \Carbon\Carbon::parse($att->time_out)->format('H:i') : '') . '"'
                                    . ' data-time-in-display="' . ($att->time_in ? \Carbon\Carbon::parse($att->time_in)->format('h:i A') : '-') . '"'
                                    . ' data-lunch-out-display="' . ($att->lunch_out ? \Carbon\Carbon::parse($att->lunch_out)->format('h:i A') : '-') . '"'
                                    . ' data-lunch-in-display="' . ($att->lunch_in ? \Carbon\Carbon::parse($att->lunch_in)->format('h:i A') : '-') . '"'
                                    . ' data-time-out-display="' . ($att->time_out ? \Carbon\Carbon::parse($att->time_out)->format('h:i A') : '-') . '"'
                                    . ' data-work="' . ($att->computed_work_minutes ?? 0) . '"'
                                    . ' data-late="' . ($att->computed_late_minutes ?? 0) . '"'
                                    . ' data-early="' . ($att->computed_early_minutes ?? 0) . '"'
                                    . ' data-ot="' . ($att->computed_overtime_minutes ?? 0) . '"'
                                    . ' data-approved-ot="' . ($att->approved_overtime_minutes ?? '') . '"'
                                    . ' data-effective-ot="' . ($att->approved_overtime_minutes ?? $att->computed_overtime_minutes ?? 0) . '"'
                                    . ' data-notes="' . e($att->notes ?? '') . '"';
                            }
                        @endphp
                        <td class="{{ $cellClass }}"
                            {!! $dataAttrs !!}
                            onclick="openDetail(this)"
                            title="{{ $empCal['employee']->display_name }} — {{ $dayInfo['date'] }}@if($shiftForCell) — {{ $shiftForCell['name'] }} ({{ $shiftForCell['start'] }} to {{ $shiftForCell['end'] }})@endif">
                            {{ $cellText }}
                            @if(!empty($showShift) && $shiftForCell)
                                <span class="cal-shift-line">
                                    <span class="cal-shift-name">{{ $shiftForCell['name'] }}</span><br>
                                    <span class="cal-shift-time">{{ $shiftForCell['start_short'] }}-{{ $shiftForCell['end_short'] }}</span>
                                </span>
                            @endif
                        </td>
                    @endforeach
                    <td class="fw-bold text-success">{{ $countP }}</td>
                    <td class="fw-bold text-danger">{{ $countA }}</td>
                    <td class="fw-bold" style="color:#664d03">{{ $countUT }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="text-center text-muted py-5">
            <i class="bi bi-calendar-x" style="font-size: 2rem;"></i>
            <p class="mt-2">No employees with attendance records found for this date range. Adjust filters above.</p>
        </div>
        @endif
    </div>
</div>

{{-- Detail Modal --}}
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:450px;">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="detailTitle">Attendance Detail</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                {{-- Present/Undertime detail --}}
                <div id="detailBody">
                    <table class="table table-sm table-borderless mb-0" style="font-size:.85rem">
                        <tr><td class="text-muted" style="width:100px">Employee</td><td class="fw-semibold" id="dEmployee"></td></tr>
                        <tr><td class="text-muted">Date</td><td class="fw-semibold" id="dDate"></td></tr>
                        <tr><td class="text-muted">Status</td><td class="fw-semibold" id="dStatus"></td></tr>
                        <tr>
                            <td class="text-muted">Shift</td>
                            <td>
                                <select class="form-select form-select-sm" id="dShiftSelect" style="max-width:320px" onchange="onShiftDropdownChange()">
                                    @foreach($shifts as $shift)
                                        @php
                                            $sStart = \Carbon\Carbon::parse($shift->start_time)->format('H:i');
                                            $sEnd   = \Carbon\Carbon::parse($shift->end_time)->format('H:i');
                                            $lStart = \Carbon\Carbon::parse($shift->lunch_start)->format('H:i');
                                            $lEnd   = \Carbon\Carbon::parse($shift->lunch_end)->format('H:i');
                                        @endphp
                                        <option value="{{ $shift->id }}"
                                                data-start="{{ \Carbon\Carbon::parse($shift->start_time)->format('g:i A') }}"
                                                data-end="{{ \Carbon\Carbon::parse($shift->end_time)->format('g:i A') }}"
                                                data-lunch-start="{{ \Carbon\Carbon::parse($shift->lunch_start)->format('g:i A') }}"
                                                data-lunch-end="{{ \Carbon\Carbon::parse($shift->lunch_end)->format('g:i A') }}">{{ $shift->name }} ({{ $sStart }}-{{ $sEnd }}, Lunch {{ $lStart }}-{{ $lEnd }})</option>
                                    @endforeach
                                </select>
                                <div class="small text-muted mt-1" id="dShiftChangedNote" style="display:none">
                                    <i class="bi bi-exclamation-circle text-warning"></i> Shift changed — will save on <strong>Save All Changes</strong>.
                                </div>
                            </td>
                        </tr>
                        <tr id="dScheduleRow"><td class="text-muted">Schedule</td><td id="dSchedule"></td></tr>
                        <tr id="dLunchBreakRow"><td class="text-muted">Lunch Break</td><td id="dLunchBreak" class="text-info"></td></tr>
                        <tr>
                            <td colspan="2" class="pt-2">
                                <button type="button" class="btn btn-sm btn-outline-primary w-100" id="dFillFromShiftBtn" onclick="showFillFromShiftForm()">
                                    <i class="bi bi-calendar-check"></i> Fill Whole Day from Shift Schedule
                                </button>
                                <div id="dFillFromShiftForm" class="mt-2" style="display:none">
                                    <input type="text" class="form-control form-control-sm mb-1"
                                           id="dFillReason"
                                           placeholder="Reason (required, min 3 chars)"
                                           minlength="3" maxlength="500">
                                    <div class="d-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-success flex-fill" onclick="submitFillFromShift()">
                                            <i class="bi bi-check-lg"></i> Apply
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hideFillFromShiftForm()">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <div id="dFillMsg" class="small mt-1" style="display:none"></div>
                                </div>
                            </td>
                        </tr>
                        <tr><td colspan="2"><hr class="my-1"></td></tr>
                        @foreach([['field'=>'time_in','label'=>'Time In'],['field'=>'lunch_out','label'=>'Lunch Out'],['field'=>'lunch_in','label'=>'Lunch In'],['field'=>'time_out','label'=>'Time Out']] as $tf)
                        <tr>
                            <td class="text-muted align-top pt-2">{{ $tf['label'] }}</td>
                            <td>
                                <div class="d-flex gap-1 align-items-center flex-wrap">
                                    <input type="time" step="1" class="form-control form-control-sm time-input"
                                           id="dInput_{{ $tf['field'] }}" data-field="{{ $tf['field'] }}"
                                           oninput="markAllDirty()"
                                           style="max-width:130px">
                                    <select class="form-select form-select-sm punch-picker"
                                            data-target="dInput_{{ $tf['field'] }}"
                                            title="Pick from raw punches"
                                            style="max-width:110px">
                                        <option value="">Punches…</option>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 time-clear-btn"
                                            data-field="{{ $tf['field'] }}" title="Mark for deletion (applies on Save All)"
                                            onclick="markFieldForClear('{{ $tf['field'] }}')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <span class="small text-danger" id="dClearFlag_{{ $tf['field'] }}" style="display:none">
                                        <i class="bi bi-trash-fill"></i> will be cleared
                                        <a href="#" class="ms-1" onclick="unmarkFieldForClear('{{ $tf['field'] }}'); return false;">undo</a>
                                    </span>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                        {{-- Shared reason + Save All button --}}
                        <tr>
                            <td colspan="2" class="pt-3">
                                <label class="fw-semibold small mb-1">Reason (required for any edit, min 3 chars):</label>
                                <input type="text" class="form-control form-control-sm mb-2" id="dSaveAllReason"
                                       placeholder="One reason applied to all changes below"
                                       minlength="3" maxlength="500">
                                <button type="button" class="btn btn-primary btn-sm w-100" id="dSaveAllBtn" onclick="saveAllChanges()">
                                    <i class="bi bi-save"></i> Save All Changes
                                </button>
                                <div id="dSaveAllMsg" class="small mt-1" style="display:none"></div>
                            </td>
                        </tr>
                        <tr class="computed-row"><td colspan="2"><hr class="my-1"></td></tr>
                        <tr class="computed-row"><td class="text-muted">Work</td><td id="dWork"></td></tr>
                        <tr class="computed-row"><td class="text-muted">Late</td><td id="dLate"></td></tr>
                        <tr class="computed-row"><td class="text-muted">Undertime</td><td id="dEarly"></td></tr>
                        <tr class="computed-row">
                            <td class="text-muted align-top pt-2">Overtime</td>
                            <td>
                                <div class="d-flex gap-1 align-items-center flex-wrap">
                                    <input type="number" step="0.25" min="0" max="24" class="form-control form-control-sm"
                                           id="dApprovedOtHours" placeholder="hours (leave blank to keep auto)"
                                           style="max-width:180px">
                                    <span class="small text-muted">hours (approved)</span>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                                            id="dClearApprovedOtBtn" title="Revert to auto-computed OT"
                                            onclick="markApprovedOtForClear()">
                                        <i class="bi bi-arrow-counterclockwise"></i> revert to auto
                                    </button>
                                </div>
                                <div class="small text-muted mt-1">
                                    Effective OT: <span id="dOT" class="fw-semibold">—</span>
                                    <span id="dApprovedOtHint" class="ms-2" style="display:none"></span>
                                </div>
                            </td>
                        </tr>
                        <tr id="dNotesRow" class="computed-row"><td class="text-muted">Notes</td><td id="dNotes"></td></tr>
                    </table>
                    {{-- Override history details --}}
                    <div id="dOverrideHistory" class="override-history mt-2" style="display:none">
                        <div class="fw-semibold mb-1" style="color:#6f42c1"><i class="bi bi-pencil-square"></i> Edit History</div>
                        <div id="dOverrideList"></div>
                    </div>
                    {{-- View Raw Punches button --}}
                    <div class="mt-2 text-center">
                        <button type="button" class="btn btn-sm btn-outline-info" onclick="openPunchesModal()">
                            <i class="bi bi-clock-history"></i> View Raw Punches
                        </button>
                    </div>
                    {{-- Day-off actions (same as day-off-calendar) — available on Present cells too --}}
                    <hr class="my-2">
                    <p class="text-muted small mb-2 text-center">Manage rest day for this date:</p>
                    <input type="text" class="form-control form-control-sm mb-2" id="dDayOffReason"
                           placeholder="Reason (required, min 3 chars) — needed for any rest-day action"
                           minlength="3" maxlength="500">
                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <button class="btn btn-sm btn-outline-primary dayoff-action-btn" onclick="toggleDayOff('add_day_off')">
                            <i class="bi bi-calendar-x"></i> Mark as Rest Day
                        </button>
                        <button class="btn btn-sm btn-outline-warning dayoff-action-btn" onclick="toggleDayOff('cancel_day_off')">
                            <i class="bi bi-calendar-check"></i> Cancel Rest Day
                        </button>
                        <button class="btn btn-sm btn-outline-secondary dayoff-action-btn" onclick="toggleDayOff('remove_override')">
                            <i class="bi bi-arrow-counterclockwise"></i> Remove Override
                        </button>
                    </div>
                    <div id="dayOffMsgPresent" class="small mt-2 text-center" style="display:none"></div>

                    {{-- ============================================================
                         SIL section — apply / remove Service Incentive Leave for this date.
                         Also lets CEO toggle eligibility and adjust the year's total balance.
                         ============================================================ --}}
                    <hr class="my-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="fw-semibold small"><i class="bi bi-cup-hot"></i> Service Incentive Leave</div>
                        <div class="small">
                            <span id="dSilBalanceLabel" class="badge bg-info text-dark">SIL: — remaining</span>
                        </div>
                    </div>
                    <div id="dSilEligibleNote" class="alert alert-warning py-1 px-2 small mb-2" style="display:none">
                        <i class="bi bi-exclamation-triangle"></i> Employee is not SIL-eligible.
                        Enable SIL and set the yearly balance on
                        <a href="#" id="dSilManageLink" target="_blank">the Employee edit page</a>.
                    </div>
                    <div id="dSilAppliedNote" class="alert alert-info py-1 px-2 small mb-2" style="display:none">
                        <i class="bi bi-check-circle"></i> <strong>SIL is applied</strong> for this date.
                        <div class="small text-muted mt-1"><span id="dSilAppliedDetails"></span></div>
                    </div>
                    <input type="text" class="form-control form-control-sm mb-1" id="dSilReason"
                           placeholder="Reason for applying SIL (required, min 3 chars)"
                           minlength="3" maxlength="500">
                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-info flex-fill" id="dApplySilBtn" onclick="applySilForDate()">
                            <i class="bi bi-cup-hot"></i> Apply SIL
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger flex-fill" id="dRemoveSilBtn" onclick="removeSilForDate()" style="display:none">
                            <i class="bi bi-x-circle"></i> Remove SIL
                        </button>
                    </div>
                    <div id="dSilMsg" class="small mt-2 text-center" style="display:none"></div>
                </div>

                {{-- Absent / Day Off detail --}}
                {{-- Legacy container (no longer used; kept as empty stub so JS won't crash on missing element) --}}
                <div id="detailNoData" style="display:none"></div>
            </div>
        </div>
    </div>
</div>

{{-- Edit Time Modal --}}
<div class="modal fade" id="editTimeModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Edit Time</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editAttDayId">
                <input type="hidden" id="editField">
                <div class="mb-2">
                    <label class="form-label small fw-semibold" id="editFieldLabel">Time</label>
                    <input type="time" class="form-control form-control-sm" id="editTimeValue">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Reason <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" id="editReason" placeholder="Reason for override..." minlength="3" maxlength="500">
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-primary flex-fill" onclick="saveOverride()"><i class="bi bi-check-lg"></i> Save</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="clearTimeValue()" title="Clear this time value"><i class="bi bi-x-lg"></i> Clear</button>
                </div>
                <div id="editError" class="text-danger small mt-2" style="display:none"></div>
                <div id="editSuccess" class="text-success small mt-2" style="display:none"></div>
            </div>
        </div>
    </div>
</div>

{{-- Raw Punches Modal --}}
<div class="modal fade" id="punchesModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">
                    <i class="bi bi-clock-history"></i>
                    Raw Punches
                </h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <div class="small text-muted mb-2">
                    <span id="pEmployee" class="fw-semibold"></span> — <span id="pDate"></span>
                </div>
                <div id="punchesLoading" class="text-center text-muted py-3" style="display:none">
                    <div class="spinner-border spinner-border-sm" role="status"></div>
                    <div class="small mt-1">Loading...</div>
                </div>
                <div id="punchesEmpty" class="text-center text-muted py-3" style="display:none">
                    <i class="bi bi-info-circle"></i> No raw punches recorded for this date.
                </div>
                <ol id="punchesList" class="list-group list-group-numbered list-group-flush" style="font-family: monospace;"></ol>
                <div class="small text-muted mt-2"><span id="pCount">0</span> punches total.</div>
            </div>
        </div>
    </div>
</div>

{{-- ============================================================
     Bulk Action Modal — opens when the user clicks a date column header.
     Applies a chosen action to many (employee, date) pairs in one call.
     ============================================================ --}}
<div class="modal fade" id="bulkActionModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">
                    <i class="bi bi-list-check"></i>
                    Bulk Action — <span id="bulkDateLabel"></span>
                </h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="fw-semibold small mb-1">Employees on this date:</label>
                    <div class="d-flex gap-2 mb-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="bulkSelectAll(true)">Select all</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="bulkSelectAll(false)">Clear</button>
                        <span class="small text-muted align-self-center ms-2"><span id="bulkSelectedCount">0</span> selected</span>
                    </div>
                    <div id="bulkEmployeeList" class="border rounded p-2" style="max-height:220px; overflow-y:auto; font-size:.85rem;">
                        <!-- populated by JS -->
                    </div>
                </div>

                <div class="mb-3">
                    <label class="fw-semibold small mb-1">Reason (required, min 3 chars) — applied to all actions below:</label>
                    <input type="text" class="form-control form-control-sm" id="bulkReason"
                           placeholder="e.g., All punched together — device offline"
                           minlength="3" maxlength="500">
                </div>

                {{-- Quick fill: uses each employee's own shift (no manual times needed) --}}
                <div class="mb-3">
                    <button type="button" class="btn btn-outline-primary w-100" id="bulkFillFromShiftBtn" onclick="doBulkFillFromShift()">
                        <i class="bi bi-calendar-check"></i>
                        <strong>Fill Whole Day from each employee's Shift Schedule</strong>
                    </button>
                    <div class="small text-muted mt-1"><i class="bi bi-info-circle"></i> Uses each employee's own shift's start/end/lunch — no typing needed.</div>
                </div>

                {{-- Custom times: type once, applies same to all --}}
                <div class="mb-3 border rounded p-2 bg-light">
                    <div class="fw-semibold small mb-2"><i class="bi bi-stopwatch"></i> Set custom times (same for ALL selected):</div>
                    <div class="row g-2 mb-2">
                        <div class="col-6 col-md-3">
                            <label class="small text-muted">Time In</label>
                            <input type="time" step="1" class="form-control form-control-sm" id="bulkTimeIn">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="small text-muted">Lunch Out</label>
                            <input type="time" step="1" class="form-control form-control-sm" id="bulkLunchOut">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="small text-muted">Lunch In</label>
                            <input type="time" step="1" class="form-control form-control-sm" id="bulkLunchIn">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="small text-muted">Time Out</label>
                            <input type="time" step="1" class="form-control form-control-sm" id="bulkTimeOut">
                        </div>
                    </div>
                    <button type="button" class="btn btn-success btn-sm w-100" onclick="doBulkSetTimes()">
                        <i class="bi bi-check-lg"></i> Save Custom Times
                    </button>
                    <div class="small text-muted mt-1"><i class="bi bi-info-circle"></i> Blank fields are left unchanged.</div>
                </div>

                {{-- Rest-day toggles --}}
                <div class="mb-3">
                    <div class="fw-semibold small mb-2"><i class="bi bi-calendar2-week"></i> Manage rest day for this date:</div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="doBulkDayOffAction('add_day_off')">
                            <i class="bi bi-calendar-x"></i> Mark all as Rest Day
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-warning flex-fill" onclick="doBulkDayOffAction('cancel_day_off')">
                            <i class="bi bi-calendar-check"></i> Cancel Rest Day
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="doBulkDayOffAction('remove_override')">
                            <i class="bi bi-arrow-counterclockwise"></i> Remove Override
                        </button>
                    </div>
                </div>

                <div id="bulkResult" class="small" style="display:none"></div>

                {{-- Hidden mirror of selection so existing submitBulkAction can still read from #bulkActionSelect --}}
                <select id="bulkActionSelect" class="d-none">
                    <option value="fill_from_shift"></option>
                    <option value="set_times"></option>
                    <option value="add_day_off"></option>
                    <option value="cancel_day_off"></option>
                    <option value="remove_override"></option>
                </select>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function toggleFilters() {
        const ft = document.getElementById('filterType').value;
        document.getElementById('deptFilterCol').style.display = ft === 'department' ? '' : 'none';
        document.getElementById('empFilterCol').style.display = ft === 'employee' ? '' : 'none';
    }

    const detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
    const editTimeModal = new bootstrap.Modal(document.getElementById('editTimeModal'));
    const punchesModal = new bootstrap.Modal(document.getElementById('punchesModal'));
    const bulkActionModal = new bootstrap.Modal(document.getElementById('bulkActionModal'));

    // ================= BULK ACTION (click a date column header) =================
    // Track the current bulk-modal date + affected employees so submitBulkAction can send them.
    let bulkCurrentDate = null;

    function openBulkActionModal(th) {
        bulkCurrentDate = th.dataset.date;
        document.getElementById('bulkDateLabel').textContent = th.dataset.dayLabel || bulkCurrentDate;

        // Scan the calendar table to find every cell in this date column and collect
        // each row's employee id/name/status (so the modal can show a checkbox list).
        const rows = document.querySelectorAll('table.cal-table tbody tr');
        const list = document.getElementById('bulkEmployeeList');
        list.innerHTML = '';
        rows.forEach(row => {
            const cell = row.querySelector('td[data-date="' + bulkCurrentDate + '"]');
            if (!cell) return;
            const empId = cell.dataset.employeeId;
            const empName = cell.dataset.employee || 'Unknown';
            const status = cell.dataset.status || '';
            const statusLabel = ({present:'Present', undertime:'UT', absent:'Absent', day_off:'Rest Day', rd_present:'RD-Present'})[status] || status;
            const statusColor = ({present:'#0f5132', undertime:'#664d03', absent:'#842029', day_off:'#084298', rd_present:'#ff4444'})[status] || '#666';
            const div = document.createElement('div');
            div.className = 'form-check';
            div.innerHTML = '<input class="form-check-input bulk-emp-cb" type="checkbox" checked '
                + 'value="' + empId + '" id="bulkCb_' + empId + '">'
                + '<label class="form-check-label" for="bulkCb_' + empId + '">'
                + empName + ' <small style="color:' + statusColor + '">(' + statusLabel + ')</small>'
                + '</label>';
            list.appendChild(div);
        });
        updateBulkSelectedCount();
        list.querySelectorAll('.bulk-emp-cb').forEach(cb => cb.addEventListener('change', function () {
            updateBulkSelectedCount();
            updateBulkTimeSuggestions();
        }));

        // Reset reason + result but let updateBulkTimeSuggestions() populate time inputs
        // from existing cell values (or leave blank + placeholder if none/varies).
        document.getElementById('bulkReason').value = '';
        document.getElementById('bulkResult').style.display = 'none';
        updateBulkTimeSuggestions();

        bulkActionModal.show();
    }

    function bulkSelectAll(checked) {
        document.querySelectorAll('.bulk-emp-cb').forEach(cb => cb.checked = checked);
        updateBulkSelectedCount();
        updateBulkTimeSuggestions();
    }

    function updateBulkSelectedCount() {
        const n = document.querySelectorAll('.bulk-emp-cb:checked').length;
        document.getElementById('bulkSelectedCount').textContent = n;
    }

    // Read the existing time values from the calendar cells for every selected employee.
    // If they all agree on a field, prefill that field so the user isn't re-typing what's already
    // there. If they differ, leave blank and hint via placeholder that values vary.
    function updateBulkTimeSuggestions() {
        const fields = [
            { field: 'time_in',   dataKey: 'timeIn',   inputId: 'bulkTimeIn' },
            { field: 'lunch_out', dataKey: 'lunchOut', inputId: 'bulkLunchOut' },
            { field: 'lunch_in',  dataKey: 'lunchIn',  inputId: 'bulkLunchIn' },
            { field: 'time_out',  dataKey: 'timeOut',  inputId: 'bulkTimeOut' },
        ];
        const selected = Array.from(document.querySelectorAll('.bulk-emp-cb:checked'));

        fields.forEach(f => {
            const input = document.getElementById(f.inputId);
            if (!input) return;
            if (selected.length === 0) {
                input.value = '';
                input.placeholder = '--:--:-- --';
                return;
            }
            const values = new Set();
            selected.forEach(cb => {
                const cell = document.querySelector(
                    'table.cal-table tbody tr td[data-employee-id="' + cb.value + '"][data-date="' + bulkCurrentDate + '"]'
                );
                if (cell) {
                    const v = (cell.dataset[f.dataKey] || '').trim();
                    if (v) values.add(v);
                }
            });
            if (values.size === 1) {
                // All selected employees share the same value — prefill (with seconds).
                const v = Array.from(values)[0];
                const parts = v.split(':');
                while (parts.length < 3) parts.push('00');
                input.value = parts.slice(0, 3).map(p => p.padStart(2, '0')).join(':');
                input.placeholder = 'same for all selected';
            } else if (values.size > 1) {
                // Selected employees have different existing values — leave blank; hint at variance.
                input.value = '';
                input.placeholder = 'varies (' + values.size + ' different) — type to override';
            } else {
                // Nobody has a value yet — blank with default placeholder.
                input.value = '';
                input.placeholder = 'blank — no existing value';
            }
        });
    }

    function onBulkActionChange() { /* no-op — retained for compat with old modal open reset */ }

    // Per-action wrappers — each validates its own inputs then delegates to sendBulkAction.
    function doBulkFillFromShift() {
        sendBulkAction('fill_from_shift', '📅 Fill Whole Day from Shift', {});
    }
    function doBulkSetTimes() {
        const t = {
            time_in:   document.getElementById('bulkTimeIn').value   || null,
            lunch_out: document.getElementById('bulkLunchOut').value || null,
            lunch_in:  document.getElementById('bulkLunchIn').value  || null,
            time_out:  document.getElementById('bulkTimeOut').value  || null,
        };
        if (!t.time_in && !t.lunch_out && !t.lunch_in && !t.time_out) {
            const result = document.getElementById('bulkResult');
            result.textContent = 'Enter at least one time to save.';
            result.className = 'small text-danger';
            result.style.display = '';
            return;
        }
        sendBulkAction('set_times', '⏱ Save Custom Times', t);
    }
    function doBulkDayOffAction(action) {
        const labels = {
            add_day_off:    '🗓 Mark as Rest Day',
            cancel_day_off: '✅ Cancel Rest Day',
            remove_override:'↩ Remove Rest-Day Override',
        };
        sendBulkAction(action, labels[action] || action, {});
    }

    // Shared submission logic — validates reason + selection, confirms, POSTs, shows result.
    function sendBulkAction(action, actionLabel, extras) {
        const reason = (document.getElementById('bulkReason').value || '').trim();
        const empIds = Array.from(document.querySelectorAll('.bulk-emp-cb:checked')).map(cb => parseInt(cb.value, 10));
        const result = document.getElementById('bulkResult');

        result.style.display = 'none';

        if (empIds.length === 0) {
            result.textContent = 'Select at least one employee.';
            result.className = 'small text-danger';
            result.style.display = '';
            return;
        }
        if (reason.length < 3) {
            result.textContent = 'Reason is required (min 3 chars).';
            result.className = 'small text-danger';
            result.style.display = '';
            document.getElementById('bulkReason').focus();
            return;
        }

        if (!confirm('Apply "' + actionLabel + '" to ' + empIds.length + ' employee(s) on ' + bulkCurrentDate + '?')) return;

        const payload = Object.assign({
            date: bulkCurrentDate,
            employee_ids: empIds,
            action: action,
            reason: reason,
        }, extras);

        fetch('{{ url("/attendance-calendar/bulk-action") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        })
        .then(r => r.json())
        .then(data => {
            const s = (data.successes || []).length;
            const f = (data.failures || []).length;
            let html = '<div class="alert alert-' + (f === 0 ? 'success' : 'warning') + ' py-2 mb-0">'
                + '<strong>' + s + ' succeeded</strong>, <strong>' + f + ' failed</strong>. Page will reload…';
            if (f > 0) {
                html += '<ul class="mb-0 mt-1 small">';
                (data.failures || []).forEach(fl => {
                    html += '<li>' + fl.name + ' — ' + (fl.error || 'error') + '</li>';
                });
                html += '</ul>';
            }
            html += '</div>';
            result.innerHTML = html;
            result.className = 'small';
            result.style.display = '';
            setTimeout(() => location.reload(), 1500);
        })
        .catch(() => {
            result.textContent = 'Network error.';
            result.className = 'small text-danger';
            result.style.display = '';
        });
    }
    // ================= /BULK ACTION =================

    let currentTd = null;
    // Set to true whenever a save/delete happens inside the current modal session.
    // We defer the calendar reload until the modal is dismissed so the user can make several
    // edits in a row without the pop-up disappearing after each one.
    let modalHasChanges = false;
    // Tracks whether the user pressed "revert to auto" on the OT input so Save All knows to clear
    // the approved value rather than treat blank as "no change".
    let clearApprovedOt = false;

    function markApprovedOtForClear() {
        clearApprovedOt = true;
        const input = document.getElementById('dApprovedOtHours');
        if (input) { input.value = ''; input.disabled = true; }
        const hint = document.getElementById('dApprovedOtHint');
        if (hint) { hint.textContent = '(will revert to auto on Save All)'; hint.style.display = ''; hint.className = 'ms-2 text-warning'; }
    }
    function unmarkApprovedOtForClear() {
        clearApprovedOt = false;
        const input = document.getElementById('dApprovedOtHours');
        if (input) input.disabled = false;
        const hint = document.getElementById('dApprovedOtHint');
        if (hint) hint.style.display = 'none';
    }

    // Reload the calendar once, when the detail modal is closed, if any edits happened while open.
    document.getElementById('detailModal').addEventListener('hidden.bs.modal', function () {
        if (modalHasChanges) {
            modalHasChanges = false;
            location.reload();
        }
    });

    function openPunchesModal() {
        if (!currentTd) return;
        const employeeId = currentTd.getAttribute('data-employee-id');
        const employeeName = currentTd.getAttribute('data-employee') || '';
        const date = currentTd.getAttribute('data-date');

        document.getElementById('pEmployee').textContent = employeeName;
        document.getElementById('pDate').textContent = date;
        document.getElementById('punchesList').innerHTML = '';
        document.getElementById('punchesEmpty').style.display = 'none';
        document.getElementById('punchesLoading').style.display = '';
        document.getElementById('pCount').textContent = '0';
        punchesModal.show();

        fetch(`{{ route('attendance.punches') }}?employee_id=${encodeURIComponent(employeeId)}&date=${encodeURIComponent(date)}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('punchesLoading').style.display = 'none';
            document.getElementById('pCount').textContent = data.count || 0;
            const list = document.getElementById('punchesList');
            list.innerHTML = '';
            if (!data.punches || data.punches.length === 0) {
                document.getElementById('punchesEmpty').style.display = '';
                return;
            }
            data.punches.forEach(p => {
                const li = document.createElement('li');
                li.className = 'list-group-item py-1 px-2';
                li.textContent = `${p.time}  (${p.time_display})`;
                list.appendChild(li);
            });
        })
        .catch(() => {
            document.getElementById('punchesLoading').style.display = 'none';
            document.getElementById('punchesEmpty').style.display = '';
            document.getElementById('punchesEmpty').textContent = 'Failed to load punches.';
        });
    }

    const statusLabels = {
        'present': 'Present',
        'undertime': 'Undertime',
        'absent': 'Absent',
        'day_off': 'Rest Day',
        'rd_present': 'Rest Day Worked',
        'sil': 'SIL (Service Incentive Leave)',
    };

    const statusColors = {
        'present': '#0f5132',
        'undertime': '#664d03',
        'absent': '#842029',
        'day_off': '#084298',
        'rd_present': '#ff4444',
        'sil': '#007a72',
    };

    const fieldLabels = {
        'time_in': 'Time In',
        'lunch_out': 'Lunch Out',
        'lunch_in': 'Lunch In',
        'time_out': 'Time Out',
    };

    function fmtMin(m) {
        m = parseInt(m) || 0;
        if (m === 0) return '0 min';
        const h = Math.floor(m / 60);
        const r = m % 60;
        if (h > 0 && r > 0) return h + 'h ' + r + 'm';
        if (h > 0) return h + 'h';
        return r + ' min';
    }

    function fmtTime12(hhmm) {
        if (!hhmm) return '-';
        const [h, m] = hhmm.split(':').map(Number);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12 = h % 12 || 12;
        return h12 + ':' + String(m).padStart(2, '0') + ' ' + ampm;
    }

    // Manual "Compute Attendance" button — bulk recompute for the visible date range.
    // Uses the calendar's date_from / date_to inputs. Respects manual overrides.
    document.addEventListener('DOMContentLoaded', function () {
        const btn = document.getElementById('computeAttendanceBtn');
        if (!btn) return;
        btn.addEventListener('click', function () {
            const dateFrom = document.querySelector('input[name="date_from"]')?.value;
            const dateTo   = document.querySelector('input[name="date_to"]')?.value;
            if (!dateFrom || !dateTo) { alert('Please set a date range first.'); return; }

            const label = document.getElementById('computeAttendanceLabel');
            const origText = label.textContent;
            label.textContent = 'Computing...';
            btn.disabled = true;

            fetch('{{ url("/attendance-calendar/compute") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ start_date: dateFrom, end_date: dateTo }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    label.textContent = 'Done — reloading...';
                    setTimeout(() => location.reload(), 500);
                } else {
                    label.textContent = origText;
                    btn.disabled = false;
                    alert('Error: ' + (data.message || 'Unknown'));
                }
            })
            .catch(() => {
                label.textContent = origText;
                btn.disabled = false;
                alert('Network error. Please try again.');
            });
        });
    });

    // Shift dropdown is always visible now; changes are batched into Save All.
    // These two helpers are kept as no-ops so any leftover callers don't crash.
    function showShiftEditor() { onShiftDropdownChange(); }
    function cancelShiftEditor() {
        // Revert dropdown to the original shift for this cell if one is stored
        if (currentTd && currentTd.dataset.shiftId) {
            const sel = document.getElementById('dShiftSelect');
            if (sel) sel.value = currentTd.dataset.shiftId;
            onShiftDropdownChange();
        }
    }
    // Called whenever the shift dropdown changes: refresh the schedule/lunch preview rows and
    // flip the "changed" indicator if the new value differs from the cell's original shift.
    function onShiftDropdownChange() {
        updateShiftPreview();
        const sel = document.getElementById('dShiftSelect');
        const note = document.getElementById('dShiftChangedNote');
        if (!sel || !note) return;
        const originalId = currentTd?.dataset.shiftId || '';
        note.style.display = (originalId && sel.value !== originalId) ? '' : 'none';
        // Refresh the Schedule / Lunch Break rows to reflect the previewed shift immediately.
        const opt = sel.selectedOptions[0];
        if (opt) {
            const sched = (opt.dataset.start || '') + ' — ' + (opt.dataset.end || '');
            const lunch = (opt.dataset.lunchStart || '') + ' — ' + (opt.dataset.lunchEnd || '');
            const s = document.getElementById('dSchedule');
            const l = document.getElementById('dLunchBreak');
            if (s) s.textContent = sched;
            if (l) l.textContent = lunch;
        }
    }
    // Reads data-* attributes from the selected <option> to show the shift's schedule + lunch preview.
    function updateShiftPreview() {
        const sel = document.getElementById('dShiftSelect');
        if (!sel) return;
        const opt = sel.selectedOptions[0];
        if (!opt) return;
        const sched = (opt.dataset.start || '') + ' — ' + (opt.dataset.end || '');
        const lunch = (opt.dataset.lunchStart || '') + ' — ' + (opt.dataset.lunchEnd || '');
        // Legacy preview elements were removed with the old shift editor — guard against missing ids.
        const s = document.getElementById('dShiftPreviewSched');
        const l = document.getElementById('dShiftPreviewLunch');
        if (s) s.textContent = sched;
        if (l) l.textContent = lunch;
    }
    function saveShiftForDay() {
        if (!currentTd) return;
        const empId  = currentTd.dataset.employeeId;
        const date   = currentTd.dataset.date;
        const shiftId = document.getElementById('dShiftSelect').value;
        const msg    = document.getElementById('dShiftEditorMsg');
        msg.style.display = 'none';

        fetch('{{ url("/attendance-calendar/assign-shift") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ employee_id: empId, date: date, shift_id: shiftId }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                msg.textContent = data.message + ' Reloading...';
                msg.className = 'small mt-1 text-success';
                msg.style.display = '';
                setTimeout(() => location.reload(), 700);
            } else {
                msg.textContent = data.message || 'Error.';
                msg.className = 'small mt-1 text-danger';
                msg.style.display = '';
            }
        })
        .catch(() => {
            msg.textContent = 'Network error. Please try again.';
            msg.className = 'small mt-1 text-danger';
            msg.style.display = '';
        });
    }

    // Populate the Schedule and Lunch Break rows in the detail modal from the cell's data-* attributes.
    // Hides the rows entirely when no shift is resolved for the date.
    function setShiftScheduleRows(td) {
        const schedule = td.dataset.shiftSchedule || '';
        const lunch    = td.dataset.shiftLunch || '';
        const schedRow = document.getElementById('dScheduleRow');
        const lunchRow = document.getElementById('dLunchBreakRow');
        if (schedule) {
            document.getElementById('dSchedule').textContent = schedule;
            if (schedRow) schedRow.style.display = '';
        } else if (schedRow) {
            schedRow.style.display = 'none';
        }
        if (lunch) {
            document.getElementById('dLunchBreak').textContent = lunch;
            if (lunchRow) lunchRow.style.display = '';
        } else if (lunchRow) {
            lunchRow.style.display = 'none';
        }
    }

    function openDetail(td) {
        currentTd = td;
        modalHasChanges = false; // fresh session — reload only if edits happen this time around
        const status = td.dataset.status;
        const empName = td.dataset.employee;
        const date = td.dataset.date;

        // Clear any lingering day-off status messages + reason from a previous modal open
        const presentMsg = document.getElementById('dayOffMsgPresent');
        if (presentMsg) presentMsg.style.display = 'none';
        const dayOffReason = document.getElementById('dDayOffReason');
        if (dayOffReason) dayOffReason.value = '';

        // Reset the inline shift editor and preselect the current shift for this cell
        const editorRow = document.getElementById('dShiftEditorRow');
        if (editorRow) editorRow.style.display = 'none';
        const shiftSelect = document.getElementById('dShiftSelect');
        if (shiftSelect && td.dataset.shiftId) {
            shiftSelect.value = td.dataset.shiftId;
        }
        const shiftMsg = document.getElementById('dShiftEditorMsg');
        if (shiftMsg) shiftMsg.style.display = 'none';

        // Reset the "Fill from Shift" inline form
        if (typeof hideFillFromShiftForm === 'function') hideFillFromShiftForm();

        document.getElementById('detailTitle').textContent = empName + ' — ' + date;

        // Always show detailBody — the time editors, shift editor, day-off actions all live there
        // so the modal works uniformly for present / absent / day-off cells.
        document.getElementById('detailBody').style.display = '';
        const nodata = document.getElementById('detailNoData');
        if (nodata) nodata.style.display = 'none';

        // Toggle computed-values and edit-history rows: only meaningful when an attendance_day exists.
        const hasAttendance = !!td.dataset.attId;
        toggleComputedRowsVisibility(hasAttendance);

        document.getElementById('dEmployee').textContent = empName;
        document.getElementById('dDate').textContent = date;

        const statusEl = document.getElementById('dStatus');
        statusEl.textContent = statusLabels[status] || status;
        statusEl.style.color = statusColors[status] || '#000';

        // Shift is now a dropdown — select the current shift (if known) and refresh the
        // Schedule / Lunch Break preview rows from the freshly-picked option's data-* attributes.
        const dShiftEl = document.getElementById('dShift');
        if (dShiftEl) dShiftEl.textContent = td.dataset.shiftName || td.dataset.shift || 'N/A';
        setShiftScheduleRows(td);

        // Populate display value + prefill input for each time field.
        // Editors are collapsed by default; user clicks to reveal.
        const rowConfig = {
            time_in:   { display: td.dataset.timeInDisplay,   raw: td.dataset.timeIn },
            lunch_out: { display: td.dataset.lunchOutDisplay, raw: td.dataset.lunchOut },
            lunch_in:  { display: td.dataset.lunchInDisplay,  raw: td.dataset.lunchIn },
            time_out:  { display: td.dataset.timeOutDisplay,  raw: td.dataset.timeOut },
        };
        const editedFieldsStr = td.dataset.editedFields || '';
        const editedFields = editedFieldsStr ? editedFieldsStr.split(',') : [];

        Object.entries(rowConfig).forEach(([field, cfg]) => {
            // Populate the always-visible time input with the current value.
            setTimeInputValue('dInput_' + field, cfg.raw);
            // Re-enable the input in case it was disabled by a previous "mark for clear".
            const input = document.getElementById('dInput_' + field);
            if (input) input.disabled = false;
            // Reset the clear-flag indicator per field.
            const flag = document.getElementById('dClearFlag_' + field);
            if (flag) flag.style.display = 'none';
            // Visually mark inputs that already carry an override.
            if (input) input.classList.toggle('time-input-edited', editedFields.includes(field));
        });
        // Reset the shared Save-All state for a fresh modal session.
        fieldsToClear.clear();
        const saveAllReason = document.getElementById('dSaveAllReason');
        if (saveAllReason) saveAllReason.value = '';
        const saveAllMsg = document.getElementById('dSaveAllMsg');
        if (saveAllMsg) saveAllMsg.style.display = 'none';
        const shiftNote = document.getElementById('dShiftChangedNote');
        if (shiftNote) shiftNote.style.display = 'none';

        // Fetch raw punches once and populate all 4 punch pickers
        loadPunchesIntoPickers(td.dataset.employeeId, td.dataset.date);

        // Refresh the SIL section (balance, apply/remove state, eligibility banner)
        refreshSilSectionFromCell();

        document.getElementById('dWork').textContent = fmtMin(td.dataset.work);
        document.getElementById('dLate').textContent = fmtMin(td.dataset.late);
        document.getElementById('dEarly').textContent = fmtMin(td.dataset.early);
        // OT: show effective (approved ?? computed), prefill approved-hours input for editing.
        const effectiveOt = parseInt(td.dataset.effectiveOt || td.dataset.ot || '0', 10);
        document.getElementById('dOT').textContent = fmtMin(effectiveOt);
        const approvedOtMin = td.dataset.approvedOt || '';
        const otInput = document.getElementById('dApprovedOtHours');
        if (otInput) {
            otInput.disabled = false;
            otInput.value = approvedOtMin !== '' ? (parseInt(approvedOtMin, 10) / 60).toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1') : '';
        }
        clearApprovedOt = false;
        const otHint = document.getElementById('dApprovedOtHint');
        if (otHint) {
            if (approvedOtMin !== '') {
                otHint.textContent = '(approved override active — auto was ' + fmtMin(parseInt(td.dataset.ot || '0', 10)) + ')';
                otHint.className = 'ms-2 text-info';
                otHint.style.display = '';
            } else {
                otHint.style.display = 'none';
            }
        }

        const notes = td.dataset.notes || '';
        const notesRow = document.getElementById('dNotesRow');
        if (notes) {
            notesRow.style.display = '';
            document.getElementById('dNotes').textContent = notes;
        } else {
            notesRow.style.display = 'none';
        }

        // Override history
        const hasOverrides = td.dataset.hasOverrides === '1';
        const overrideHistoryDiv = document.getElementById('dOverrideHistory');
        const overrideListDiv = document.getElementById('dOverrideList');

        if (hasOverrides) {
            try {
                const overrideDetails = JSON.parse(td.dataset.overrideDetails || '[]');
                let html = '';
                const dayOffActionLabels = {
                    'add_day_off':     '🗓️ Marked as Rest Day',
                    'cancel_day_off':  '✅ Cancelled Rest Day',
                    'remove_override': '↩️ Removed Rest-Day Override',
                };
                overrideDetails.forEach(function(ov) {
                    const isDayOff = ov.kind === 'day_off';
                    let label, body;
                    if (isDayOff) {
                        // Extract action from field e.g. "day_off:add_day_off"
                        const action = (ov.field || '').replace(/^day_off:/, '');
                        label = dayOffActionLabels[action] || action;
                        const oldT = ov.old_value === '(none)' ? '<em>none</em>' : ov.old_value;
                        const newT = ov.new_value === '(none)' ? '<em>none</em>' : ov.new_value;
                        body = '<span class="text-danger text-decoration-line-through">' + oldT + '</span>'
                             + ' &rarr; <span class="text-success fw-semibold">' + newT + '</span>';
                    } else {
                        label = fieldLabels[ov.field] || ov.field;
                        const oldVal = ov.old_value || '(empty)';
                        const newVal = ov.new_value || '(empty)';
                        body = '<span class="text-danger text-decoration-line-through">' + oldVal + '</span>'
                             + ' &rarr; <span class="text-success fw-semibold">' + newVal + '</span>';
                    }
                    html += '<div class="ov-entry">'
                        + '<strong>' + label + '</strong>: '
                        + body
                        + '<br><small class="text-muted">by ' + ov.updater + ' on ' + ov.date + '</small>'
                        + (ov.reason ? '<br><small class="fst-italic text-muted">"' + ov.reason + '"</small>' : '')
                        + '</div>';
                });
                overrideListDiv.innerHTML = html;
                overrideHistoryDiv.style.display = '';
            } catch (e) {
                overrideHistoryDiv.style.display = 'none';
            }
        } else {
            overrideHistoryDiv.style.display = 'none';
        }

        detailModal.show();
    }

    function setTimeDisplay(elId, displayVal, rawVal, isEdited) {
        const el = document.getElementById(elId);
        if (!el) return;
        el.textContent = displayVal || '-';
        el.dataset.rawValue = rawVal || '';
        if (isEdited) {
            el.classList.add('time-edited');
            el.innerHTML = (displayVal || '-') + ' <i class="bi bi-pencil-fill" style="font-size:.6rem;color:#6f42c1"></i>';
        } else {
            el.classList.remove('time-edited');
        }
    }

    // Prefill a time input with H:i:s from an H:i value (adds ':00' seconds if not present).
    function setTimeInputValue(inputId, hhmm) {
        const el = document.getElementById(inputId);
        if (!el) return;
        if (!hhmm) { el.value = ''; return; }
        const parts = hhmm.split(':');
        while (parts.length < 3) parts.push('00');
        el.value = parts.slice(0, 3).map(p => p.padStart(2, '0')).join(':');
    }

    // Fetch raw punches for this employee/date once, populate all 4 punch-picker dropdowns.
    function loadPunchesIntoPickers(employeeId, date) {
        const pickers = document.querySelectorAll('.punch-picker');
        // Reset dropdowns while loading
        pickers.forEach(p => {
            p.innerHTML = '<option value="">Loading…</option>';
            p.disabled = true;
        });
        fetch(`{{ route('attendance.punches') }}?employee_id=${encodeURIComponent(employeeId)}&date=${encodeURIComponent(date)}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            const punches = data.punches || [];
            pickers.forEach(p => {
                let html = '<option value="">Punches…</option>';
                punches.forEach(pu => {
                    html += `<option value="${pu.time}">${pu.time}</option>`;
                });
                p.innerHTML = html;
                p.disabled = false;
            });
        })
        .catch(() => {
            pickers.forEach(p => {
                p.innerHTML = '<option value="">(failed to load)</option>';
                p.disabled = false;
            });
        });
    }

    // "Fill Whole Day from Shift" — show the reason input inline (so user can enter it and hit Apply).
    function showFillFromShiftForm() {
        document.getElementById('dFillFromShiftForm').style.display = '';
        document.getElementById('dFillFromShiftBtn').style.display = 'none';
        document.getElementById('dFillMsg').style.display = 'none';
        document.getElementById('dFillReason').focus();
    }
    function hideFillFromShiftForm() {
        document.getElementById('dFillFromShiftForm').style.display = 'none';
        document.getElementById('dFillFromShiftBtn').style.display = '';
        document.getElementById('dFillReason').value = '';
        document.getElementById('dFillMsg').style.display = 'none';
    }
    function submitFillFromShift() {
        if (!currentTd) return;
        const empId  = currentTd.dataset.employeeId;
        const date   = currentTd.dataset.date;
        const reason = (document.getElementById('dFillReason').value || '').trim();
        const msg    = document.getElementById('dFillMsg');
        msg.style.display = 'none';

        if (reason.length < 3) {
            flashMessage(msg, 'Reason is required (min 3 chars).', false);
            document.getElementById('dFillReason').focus();
            return;
        }

        fetch('{{ url("/attendance-calendar/fill-from-shift") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ employee_id: empId, date: date, reason: reason }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Apply all 4 field values + metrics in one pass so the modal reflects the whole day.
                ['time_in','lunch_out','lunch_in','time_out'].forEach(f => {
                    const v = data.values?.[f];
                    if (v) applyTimeFieldUpdate(f, v.display, v.raw, data.metrics);
                });
                hideFillFromShiftForm();
                // Show fleeting success on the whole-day button area for feedback
                const btn = document.getElementById('dFillFromShiftBtn');
                if (btn) {
                    const oldHtml = btn.innerHTML;
                    btn.innerHTML = '<i class="bi bi-check-lg"></i> Applied — will refresh on close';
                    btn.classList.add('btn-success'); btn.classList.remove('btn-outline-primary');
                    setTimeout(() => {
                        btn.innerHTML = oldHtml;
                        btn.classList.remove('btn-success'); btn.classList.add('btn-outline-primary');
                    }, 2000);
                }
            } else {
                flashMessage(msg, data.message || 'Error.', false);
            }
        })
        .catch(() => flashMessage(msg, 'Network error.', false));
    }

    // After a successful save/delete: reflect the new value in the display + collapse the editor,
    // update the source cell dataset (so the modal shows the fresh value on re-open in the same session),
    // and mark modalHasChanges so we reload once when the modal is dismissed.
    function applyTimeFieldUpdate(field, displayVal, rawVal, metrics) {
        const text = document.getElementById('dText_' + field);
        if (text) {
            text.textContent = displayVal || '-';
            text.classList.add('time-display-edited');
        }
        setTimeInputValue('dInput_' + field, rawVal || '');
        if (currentTd) {
            // Convert snake_case field to camelCase for dataset (data-time-in -> timeIn)
            const camel = field.replace(/_([a-z])/g, (_, c) => c.toUpperCase());
            currentTd.dataset[camel] = rawVal || '';
            currentTd.dataset[camel + 'Display'] = displayVal || '-';
        }
        // Refresh computed metrics (Work / Late / UT / OT) in the modal so the user sees the impact
        // of their edit without waiting for a reload.
        if (metrics) {
            toggleComputedRowsVisibility(true);
            document.getElementById('dWork').textContent  = fmtMin(metrics.work_minutes);
            document.getElementById('dLate').textContent  = fmtMin(metrics.late_minutes);
            document.getElementById('dEarly').textContent = fmtMin(metrics.early_minutes);
            document.getElementById('dOT').textContent    = fmtMin(metrics.overtime_minutes);
            if (currentTd) {
                currentTd.dataset.work = metrics.work_minutes;
                currentTd.dataset.late = metrics.late_minutes;
                currentTd.dataset.early = metrics.early_minutes;
                currentTd.dataset.ot = metrics.overtime_minutes;
            }
        }
        cancelTimeEditor(field);
        modalHasChanges = true;
    }

    // Show a short-lived success/error message and hide it after 2 seconds on success.
    function flashMessage(el, text, isSuccess) {
        if (!el) return;
        el.textContent = text;
        el.className = 'small mt-1 ' + (isSuccess ? 'text-success' : 'text-danger');
        el.style.display = '';
        if (isSuccess) setTimeout(() => { el.style.display = 'none'; }, 2000);
    }

    // ================= SIL (Service Incentive Leave) — modal handlers =================
    // Reads the SIL data-* attributes on the current cell and orchestrates apply / remove /
    // eligibility toggle / balance-adjust against the calendar endpoints.
    function refreshSilSectionFromCell() {
        if (!currentTd) return;
        const eligible = currentTd.dataset.silEligible === '1';
        const applied  = currentTd.dataset.silApplied === '1';
        const remaining = parseFloat(currentTd.dataset.silRemaining || '0');
        const total    = parseFloat(currentTd.dataset.silTotal || '0');
        const balLabel = document.getElementById('dSilBalanceLabel');
        if (balLabel) balLabel.textContent =
            'SIL: ' + remaining.toFixed(2).replace(/\.00$/, '') + ' / ' + total.toFixed(2).replace(/\.00$/, '') + ' remaining';
        const elNote = document.getElementById('dSilEligibleNote');
        if (elNote) elNote.style.display = eligible ? 'none' : '';
        // Link to the Employee edit page (SIL section anchor)
        const manageLink = document.getElementById('dSilManageLink');
        if (manageLink && currentTd) {
            manageLink.href = '{{ url("/employees") }}/' + currentTd.dataset.employeeId + '/edit#sil-section';
        }
        const applyBtn = document.getElementById('dApplySilBtn');
        if (applyBtn) { applyBtn.disabled = !eligible || applied || remaining < 1; applyBtn.style.display = applied ? 'none' : ''; }
        const removeBtn = document.getElementById('dRemoveSilBtn');
        if (removeBtn) removeBtn.style.display = applied ? '' : 'none';
        const appliedNote = document.getElementById('dSilAppliedNote');
        if (appliedNote) appliedNote.style.display = applied ? '' : 'none';
        if (applied) {
            const d = document.getElementById('dSilAppliedDetails');
            if (d) d.textContent = 'By ' + (currentTd.dataset.silBy || 'Unknown')
                + ' on ' + (currentTd.dataset.silAt || '')
                + ' — "' + (currentTd.dataset.silReason || '') + '"';
        }
        const reason = document.getElementById('dSilReason');
        if (reason) reason.value = '';
        const msg = document.getElementById('dSilMsg');
        if (msg) msg.style.display = 'none';
        const editor = document.getElementById('dSilBalanceEditor');
        if (editor) editor.style.display = 'none';
    }
    function silShowMessage(text, isSuccess) {
        const el = document.getElementById('dSilMsg');
        if (!el) return;
        el.textContent = text;
        el.className = 'small mt-2 text-center ' + (isSuccess ? 'text-success' : 'text-danger');
        el.style.display = '';
        if (isSuccess) setTimeout(() => { el.style.display = 'none'; }, 2000);
    }
    function applySilForDate() {
        if (!currentTd) return;
        const reason = (document.getElementById('dSilReason').value || '').trim();
        if (reason.length < 3) { silShowMessage('Reason is required (min 3 chars).', false); return; }
        fetch('{{ url("/attendance-calendar/apply-sil") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
            body: JSON.stringify({ employee_id: currentTd.dataset.employeeId, date: currentTd.dataset.date, reason: reason }),
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { silShowMessage(data.message || 'Error', false); return; }
            currentTd.dataset.silApplied = '1';
            currentTd.dataset.silReason  = reason;
            currentTd.dataset.silRemaining = String(data.remaining);
            refreshSilSectionFromCell();
            silShowMessage('SIL applied. ' + data.remaining + ' remaining.', true);
            modalHasChanges = true;
        })
        .catch(() => silShowMessage('Network error.', false));
    }
    function removeSilForDate() {
        if (!currentTd || !confirm('Remove SIL for this date?')) return;
        fetch('{{ url("/attendance-calendar/remove-sil") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
            body: JSON.stringify({ employee_id: currentTd.dataset.employeeId, date: currentTd.dataset.date }),
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { silShowMessage(data.message || 'Error', false); return; }
            currentTd.dataset.silApplied = '0';
            currentTd.dataset.silRemaining = String(data.remaining);
            refreshSilSectionFromCell();
            silShowMessage('SIL removed.', true);
            modalHasChanges = true;
        })
        .catch(() => silShowMessage('Network error.', false));
    }
    function toggleSilEligibility(eligible) {
        if (!currentTd) return;
        fetch('{{ url("/attendance-calendar/toggle-sil-eligibility") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
            body: JSON.stringify({ employee_id: currentTd.dataset.employeeId, eligible: eligible }),
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { silShowMessage(data.message || 'Error', false); return; }
            currentTd.dataset.silEligible = data.eligible ? '1' : '0';
            refreshSilSectionFromCell();
            silShowMessage(data.message, true);
        })
        .catch(() => silShowMessage('Network error.', false));
    }
    function showSilBalanceEditor() {
        if (!currentTd) return;
        document.getElementById('dSilBalanceYear').textContent = currentTd.dataset.silYear || '';
        document.getElementById('dSilBalanceTotal').value = currentTd.dataset.silTotal || '5.00';
        document.getElementById('dSilBalanceEditor').style.display = '';
    }
    function saveSilBalance() {
        if (!currentTd) return;
        const empId = currentTd.dataset.employeeId;
        const year  = parseInt(currentTd.dataset.silYear || '0', 10);
        const total = parseFloat(document.getElementById('dSilBalanceTotal').value || '0');
        fetch('{{ url("/attendance-calendar/adjust-sil-balance") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
            body: JSON.stringify({ employee_id: empId, year: year, total_days: total }),
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { silShowMessage(data.message || 'Error', false); return; }
            currentTd.dataset.silTotal = String(data.total);
            currentTd.dataset.silRemaining = String(data.remaining);
            refreshSilSectionFromCell();
            silShowMessage(data.message, true);
        })
        .catch(() => silShowMessage('Network error.', false));
    }
    // ================= /SIL =================

    // Hide/show computed metric rows (Work / Late / UT / OT / Notes) and edit history.
    // These are only meaningful when an AttendanceDay exists for the cell.
    function toggleComputedRowsVisibility(hasAttendance) {
        document.querySelectorAll('.computed-row').forEach(el => {
            el.style.display = hasAttendance ? '' : 'none';
        });
        const hist = document.getElementById('dOverrideHistory');
        if (hist) hist.style.display = 'none'; // openDetail will show it again if there are overrides
    }

    // Ensure an AttendanceDay exists for the current cell before saving a time override.
    // Returns a Promise resolving to the attendance_day_id (existing or newly created), or null on failure.
    function ensureAttendanceDay() {
        if (!currentTd) return Promise.resolve(null);
        if (currentTd.dataset.attId) return Promise.resolve(currentTd.dataset.attId);
        const empId = currentTd.dataset.employeeId;
        const date  = currentTd.dataset.date;
        return fetch('{{ url("/attendance-calendar/create-day") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ employee_id: empId, work_date: date }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.attendance_day_id) {
                currentTd.dataset.attId = data.attendance_day_id;
                return data.attendance_day_id;
            }
            return null;
        })
        .catch(() => null);
    }

    // ================ Save-All flow ================
    // Fields marked for clearing (rendered visually — actually cleared only on Save All).
    const fieldsToClear = new Set();

    function markFieldForClear(field) {
        fieldsToClear.add(field);
        const input = document.getElementById('dInput_' + field);
        const flag  = document.getElementById('dClearFlag_' + field);
        if (input) { input.value = ''; input.disabled = true; }
        if (flag)  flag.style.display = '';
        markAllDirty();
    }
    function unmarkFieldForClear(field) {
        fieldsToClear.delete(field);
        const input = document.getElementById('dInput_' + field);
        const flag  = document.getElementById('dClearFlag_' + field);
        if (input) input.disabled = false;
        if (flag)  flag.style.display = 'none';
        // Restore original value from cell dataset
        if (currentTd) {
            const map = { time_in: 'timeIn', lunch_out: 'lunchOut', lunch_in: 'lunchIn', time_out: 'timeOut' };
            setTimeInputValue('dInput_' + field, currentTd.dataset[map[field]] || '');
        }
    }
    function markAllDirty() { /* placeholder — visual indicator could go here */ }

    // Compat: legacy no-ops so any lingering handlers don't blow up.
    function showTimeEditor(field) { /* editor is always visible now */ }
    function cancelTimeEditor(field) { /* nothing to collapse */ }

    // Collect every pending edit and send in one request. Server diffs against current DB values
    // and only writes overrides for real changes.
    function saveAllChanges() {
        if (!currentTd) return;
        const reason = (document.getElementById('dSaveAllReason').value || '').trim();
        const msg    = document.getElementById('dSaveAllMsg');
        const btn    = document.getElementById('dSaveAllBtn');

        msg.style.display = 'none';
        if (reason.length < 3) {
            flashMessage(msg, 'Reason is required (min 3 chars).', false);
            document.getElementById('dSaveAllReason').focus();
            return;
        }

        const payload = {
            employee_id: currentTd.dataset.employeeId,
            date:        currentTd.dataset.date,
            reason:      reason,
            time_in:     document.getElementById('dInput_time_in').value   || null,
            lunch_out:   document.getElementById('dInput_lunch_out').value || null,
            lunch_in:    document.getElementById('dInput_lunch_in').value  || null,
            time_out:    document.getElementById('dInput_time_out').value  || null,
            clear_fields: Array.from(fieldsToClear),
            approved_ot_hours: document.getElementById('dApprovedOtHours').value || null,
            clear_approved_ot: clearApprovedOt,
        };
        // Include shift_id if the dropdown differs from the cell's original shift
        const sel = document.getElementById('dShiftSelect');
        if (sel && currentTd.dataset.shiftId && sel.value !== currentTd.dataset.shiftId) {
            payload.shift_id = parseInt(sel.value, 10);
        }

        btn.disabled = true;
        fetch('{{ url("/attendance-calendar/save-all") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (!data.success) { flashMessage(msg, data.message || 'Error.', false); return; }
            // Apply fresh values from server + reset clear-marks
            fieldsToClear.clear();
            ['time_in','lunch_out','lunch_in','time_out'].forEach(f => {
                const flag = document.getElementById('dClearFlag_' + f);
                if (flag) flag.style.display = 'none';
                const input = document.getElementById('dInput_' + f);
                if (input) input.disabled = false;
            });
            if (data.values) {
                ['time_in','lunch_out','lunch_in','time_out'].forEach(f => {
                    const v = data.values[f];
                    if (v) {
                        setTimeInputValue('dInput_' + f, v.raw);
                        if (currentTd) {
                            const camel = f.replace(/_([a-z])/g, (_, c) => c.toUpperCase());
                            currentTd.dataset[camel] = v.raw;
                            currentTd.dataset[camel + 'Display'] = v.display;
                        }
                    }
                });
            }
            if (data.metrics) {
                toggleComputedRowsVisibility(true);
                document.getElementById('dWork').textContent  = fmtMin(data.metrics.work_minutes);
                document.getElementById('dLate').textContent  = fmtMin(data.metrics.late_minutes);
                document.getElementById('dEarly').textContent = fmtMin(data.metrics.early_minutes);
                document.getElementById('dOT').textContent    = fmtMin(data.metrics.overtime_minutes);
                // Sync approved-OT dataset + input from server response
                if (currentTd) {
                    if (data.metrics.approved_ot_hours !== null && data.metrics.approved_ot_hours !== undefined) {
                        currentTd.dataset.approvedOt = String(Math.round(data.metrics.approved_ot_hours * 60));
                        const otInput = document.getElementById('dApprovedOtHours');
                        if (otInput) { otInput.value = data.metrics.approved_ot_hours; otInput.disabled = false; }
                    } else {
                        currentTd.dataset.approvedOt = '';
                        const otInput = document.getElementById('dApprovedOtHours');
                        if (otInput) { otInput.value = ''; otInput.disabled = false; }
                    }
                    currentTd.dataset.effectiveOt = String(data.metrics.overtime_minutes);
                    clearApprovedOt = false;
                    const otHint = document.getElementById('dApprovedOtHint');
                    if (otHint) otHint.style.display = 'none';
                }
            }
            // If shift was accepted, update the cell dataset + hide the "changed" note.
            if (data.shift_changed && sel && currentTd) {
                currentTd.dataset.shiftId = sel.value;
                const note = document.getElementById('dShiftChangedNote');
                if (note) note.style.display = 'none';
            }
            document.getElementById('dSaveAllReason').value = '';
            flashMessage(msg, data.message || 'Saved.', true);
            modalHasChanges = true;
        })
        .catch(() => {
            btn.disabled = false;
            flashMessage(msg, 'Network error.', false);
        });
    }
    // ================ /Save-All flow ================

    // Wire up the punch pickers and inline save buttons (once, on DOM ready).
    document.addEventListener('DOMContentLoaded', function () {
        // Selecting a raw punch fills the matching time input.
        document.querySelectorAll('.punch-picker').forEach(picker => {
            picker.addEventListener('change', function () {
                if (!this.value) return;
                const target = document.getElementById(this.dataset.target);
                if (target) target.value = this.value;
            });
        });

        // Delete button: clears the time field (sends empty new_value). Requires a reason
        // (same validation as save). Confirms once via a native dialog before firing.
        document.querySelectorAll('.time-delete-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                if (!currentTd) return;
                const field  = this.dataset.field;
                const reason = document.getElementById('dReason_' + field);
                const msg    = document.getElementById('dMsg_'    + field);
                const reasonVal = (reason?.value || '').trim();

                msg.style.display = 'none';

                if (reasonVal.length < 3) {
                    msg.textContent = 'Reason is required (min 3 characters) — even for deletion.';
                    msg.className = 'small mt-1 text-danger';
                    msg.style.display = '';
                    reason?.focus();
                    return;
                }

                if (!confirm('Delete this time value? This will clear it and log the reason as an override.')) return;

                const delBtn = this;
                delBtn.disabled = true;

                // Delete only makes sense on cells that already have an AttendanceDay.
                const attId = currentTd.dataset.attId;
                if (!attId) {
                    delBtn.disabled = false;
                    msg.textContent = 'Nothing to delete — no attendance record yet for this date.';
                    msg.className = 'small mt-1 text-danger';
                    msg.style.display = '';
                    return;
                }

                fetch('{{ url("/attendance-calendar/override-time") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        attendance_day_id: attId,
                        field: field,
                        new_value: '', // empty = clear
                        reason: reasonVal,
                    }),
                })
                .then(r => r.json())
                .then(data => {
                    delBtn.disabled = false;
                    if (data.success) {
                        applyTimeFieldUpdate(field, data.value_display || '-', '', data.metrics);
                        flashMessage(msg, 'Deleted.', true);
                    } else {
                        flashMessage(msg, data.message || 'Error.', false);
                    }
                })
                .catch(() => {
                    delBtn.disabled = false;
                    flashMessage(msg, 'Network error.', false);
                });
            });
        });

        // Inline save button: validates reason, ensures an AttendanceDay exists (auto-creates for
        // absent/day-off cells), POSTs the value for its field, reloads on success.
        document.querySelectorAll('.time-save-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                if (!currentTd) return;
                const field  = this.dataset.field;
                const input  = document.getElementById('dInput_'  + field);
                const reason = document.getElementById('dReason_' + field);
                const msg    = document.getElementById('dMsg_'    + field);
                const newVal = input?.value || '';
                const reasonVal = (reason?.value || '').trim();

                msg.style.display = 'none';

                if (reasonVal.length < 3) {
                    msg.textContent = 'Reason is required (min 3 characters).';
                    msg.className = 'small mt-1 text-danger';
                    msg.style.display = '';
                    reason?.focus();
                    return;
                }

                this.disabled = true;
                const saveBtn = this;

                ensureAttendanceDay().then(attId => {
                    if (!attId) {
                        saveBtn.disabled = false;
                        msg.textContent = 'Could not create attendance record. CEO access may be required.';
                        msg.className = 'small mt-1 text-danger';
                        msg.style.display = '';
                        return;
                    }
                    fetch('{{ url("/attendance-calendar/override-time") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            attendance_day_id: attId,
                            field: field,
                            new_value: newVal,
                            reason: reasonVal,
                        }),
                    })
                    .then(r => r.json())
                    .then(data => {
                        saveBtn.disabled = false;
                        if (data.success) {
                            applyTimeFieldUpdate(field, data.value_display, data.value_raw, data.metrics);
                            flashMessage(msg, 'Saved.', true);
                        } else {
                            flashMessage(msg, data.message || 'Error.', false);
                        }
                    })
                    .catch(() => {
                        saveBtn.disabled = false;
                        flashMessage(msg, 'Network error.', false);
                    });
                });
            });
        });
    });

    function startEdit(span) {
        const field = span.dataset.field;
        const attId = currentTd ? currentTd.dataset.attId : null;

        if (!attId) return;

        document.getElementById('editAttDayId').value = attId;
        document.getElementById('editField').value = field;
        document.getElementById('editFieldLabel').textContent = fieldLabels[field] || field;
        document.getElementById('editTimeValue').value = span.dataset.rawValue || '';
        document.getElementById('editReason').value = '';
        document.getElementById('editError').style.display = 'none';
        document.getElementById('editSuccess').style.display = 'none';

        detailModal.hide();
        setTimeout(() => editTimeModal.show(), 200);
    }

    function clearTimeValue() {
        document.getElementById('editTimeValue').value = '';
        saveOverride();
    }

    function saveOverride() {
        const attDayId = document.getElementById('editAttDayId').value;
        const field = document.getElementById('editField').value;
        const newValue = document.getElementById('editTimeValue').value || null;
        const reason = document.getElementById('editReason').value.trim();

        if (!reason || reason.length < 3) {
            document.getElementById('editError').textContent = 'Please enter a reason (at least 3 characters).';
            document.getElementById('editError').style.display = '';
            return;
        }

        document.getElementById('editError').style.display = 'none';

        fetch('{{ url("/attendance/override") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                attendance_day_id: attDayId,
                field: field,
                new_value: newValue,
                reason: reason,
            }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editSuccess').textContent = 'Saved! Reloading...';
                document.getElementById('editSuccess').style.display = '';
                setTimeout(() => location.reload(), 800);
            } else {
                document.getElementById('editError').textContent = data.message || 'Error saving override.';
                document.getElementById('editError').style.display = '';
            }
        })
        .catch(err => {
            document.getElementById('editError').textContent = 'Network error. Please try again.';
            document.getElementById('editError').style.display = '';
        });
    }

    // Day off toggle — reads the required reason input, POSTs the action, marks the modal changed
    // so the calendar reloads once on close (rest-day changes affect cell color/status).
    function toggleDayOff(action) {
        const empId = currentTd ? currentTd.dataset.employeeId : null;
        const date  = currentTd ? currentTd.dataset.date : null;
        if (!empId || !date) return;

        const msgDiv    = document.getElementById('dayOffMsgPresent');
        const reasonEl  = document.getElementById('dDayOffReason');
        const reasonVal = (reasonEl?.value || '').trim();
        if (msgDiv) msgDiv.style.display = 'none';

        if (reasonVal.length < 3) {
            if (msgDiv) {
                msgDiv.textContent = 'Reason is required (min 3 chars) for any rest-day action.';
                msgDiv.className = 'small mt-2 text-danger text-center';
                msgDiv.style.display = '';
            }
            reasonEl?.focus();
            return;
        }

        fetch('{{ url("/attendance-calendar/toggle-day-off") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                employee_id: empId,
                date: date,
                action: action,
                reason: reasonVal,
            }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (msgDiv) {
                    msgDiv.textContent = data.message + ' Reloading on close…';
                    msgDiv.className = 'small mt-2 text-success text-center';
                    msgDiv.style.display = '';
                }
                if (reasonEl) reasonEl.value = '';
                // Rest-day change alters cell status/color — defer the reload to modal close
                // (consistent with time save/delete behavior).
                modalHasChanges = true;
            } else if (msgDiv) {
                msgDiv.textContent = data.message || 'Error.';
                msgDiv.className = 'small mt-2 text-danger text-center';
                msgDiv.style.display = '';
            }
        })
        .catch(() => {
            if (msgDiv) {
                msgDiv.textContent = 'Network error. Please try again.';
                msgDiv.className = 'small mt-2 text-danger text-center';
                msgDiv.style.display = '';
            }
        });
    }

    // When edit modal is closed, re-open detail modal
    document.getElementById('editTimeModal').addEventListener('hidden.bs.modal', function () {
        // Only re-open if not successfully saved (which triggers reload)
    });
</script>
@endpush
