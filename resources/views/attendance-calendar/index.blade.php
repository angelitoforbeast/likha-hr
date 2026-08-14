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
                        <th class="{{ $isToday ? 'bg-primary text-white' : ($isSunday ? 'text-danger' : ($isSaturday ? 'text-primary' : '')) }}">
                            {{ $dayDate->format('j') }}<br>
                            <small>{{ $dayDate->format('D') }}</small>
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

                            if ($att) {
                                // Build override details JSON
                                $ovDetailsJson = json_encode($dayInfo['override_details'] ?? []);

                                // Check which fields have been edited
                                $editedFields = collect($dayInfo['override_details'] ?? [])->pluck('field')->unique()->toArray();

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
                                    . ' data-has-overrides="' . ($hasOverrides ? '1' : '0') . '"'
                                    . ' data-notes="' . e($att->notes ?? '') . '"'
                                    . ' data-override-details="' . e($ovDetailsJson) . '"'
                                    . ' data-edited-fields="' . e(implode(',', $editedFields)) . '"';
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
                                <span id="dShift"></span>
                                <button type="button" class="btn btn-sm btn-link p-0 ms-1" style="font-size:.75rem" onclick="showShiftEditor()" title="Change shift for this date">
                                    <i class="bi bi-pencil-square"></i> change
                                </button>
                            </td>
                        </tr>
                        <tr id="dShiftEditorRow" style="display:none">
                            <td class="text-muted">New Shift</td>
                            <td>
                                <div class="d-flex gap-1 align-items-center">
                                    <select class="form-select form-select-sm" id="dShiftSelect" style="max-width:280px" onchange="updateShiftPreview()">
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
                                    <button type="button" class="btn btn-sm btn-success py-0 px-2" onclick="saveShiftForDay()" title="Save">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="cancelShiftEditor()" title="Cancel">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                                <div id="dShiftPreview" class="small text-muted mt-1" style="line-height:1.3">
                                    <div>Schedule: <span id="dShiftPreviewSched"></span></div>
                                    <div class="text-info">Lunch Break: <span id="dShiftPreviewLunch"></span></div>
                                </div>
                                <div id="dShiftEditorMsg" class="small mt-1" style="display:none"></div>
                            </td>
                        </tr>
                        <tr id="dScheduleRow"><td class="text-muted">Schedule</td><td id="dSchedule"></td></tr>
                        <tr id="dLunchBreakRow"><td class="text-muted">Lunch Break</td><td id="dLunchBreak" class="text-info"></td></tr>
                        <tr><td colspan="2"><hr class="my-1"></td></tr>
                        @foreach([['field'=>'time_in','label'=>'Time In'],['field'=>'lunch_out','label'=>'Lunch Out'],['field'=>'lunch_in','label'=>'Lunch In'],['field'=>'time_out','label'=>'Time Out']] as $tf)
                        <tr>
                            <td class="text-muted align-top pt-2">{{ $tf['label'] }}</td>
                            <td>
                                {{-- Display mode (shown by default) --}}
                                <div id="dDisplay_{{ $tf['field'] }}">
                                    <span id="dText_{{ $tf['field'] }}" class="time-display-value" data-field="{{ $tf['field'] }}"
                                          onclick="showTimeEditor('{{ $tf['field'] }}')" role="button"
                                          title="Click to edit">-</span>
                                    <button type="button" class="btn btn-sm btn-link p-0 ms-1"
                                            onclick="showTimeEditor('{{ $tf['field'] }}')" title="Edit">
                                        <i class="bi bi-pencil-square" style="font-size:.75rem"></i>
                                    </button>
                                </div>
                                {{-- Edit mode (hidden by default, appears on click) --}}
                                <div id="dEditor_{{ $tf['field'] }}" style="display:none">
                                    <div class="d-flex gap-1 align-items-center flex-wrap">
                                        <input type="time" step="1" class="form-control form-control-sm time-input"
                                               id="dInput_{{ $tf['field'] }}" data-field="{{ $tf['field'] }}"
                                               style="max-width:130px">
                                        <select class="form-select form-select-sm punch-picker"
                                                data-target="dInput_{{ $tf['field'] }}"
                                                title="Pick from raw punches"
                                                style="max-width:110px">
                                            <option value="">Punches…</option>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-success py-0 px-2 time-save-btn"
                                                data-field="{{ $tf['field'] }}" title="Save">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 time-delete-btn"
                                                data-field="{{ $tf['field'] }}" title="Delete (clear this time)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                                                onclick="cancelTimeEditor('{{ $tf['field'] }}')" title="Cancel">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <input type="text" class="form-control form-control-sm mt-1 time-reason"
                                           id="dReason_{{ $tf['field'] }}"
                                           placeholder="Reason (required, min 3 chars) — needed for both save and delete"
                                           minlength="3" maxlength="500">
                                </div>
                                <div id="dMsg_{{ $tf['field'] }}" class="small mt-1" style="display:none"></div>
                            </td>
                        </tr>
                        @endforeach
                        <tr class="computed-row"><td colspan="2"><hr class="my-1"></td></tr>
                        <tr class="computed-row"><td class="text-muted">Work</td><td id="dWork"></td></tr>
                        <tr class="computed-row"><td class="text-muted">Late</td><td id="dLate"></td></tr>
                        <tr class="computed-row"><td class="text-muted">Undertime</td><td id="dEarly"></td></tr>
                        <tr class="computed-row"><td class="text-muted">Overtime</td><td id="dOT"></td></tr>
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

    let currentTd = null;

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
    };

    const statusColors = {
        'present': '#0f5132',
        'undertime': '#664d03',
        'absent': '#842029',
        'day_off': '#084298',
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

    // Per-cell shift editor — show/hide the inline dropdown and save a single-day override.
    function showShiftEditor() {
        document.getElementById('dShiftEditorRow').style.display = '';
        document.getElementById('dShiftEditorMsg').style.display = 'none';
        updateShiftPreview();
    }
    function cancelShiftEditor() {
        document.getElementById('dShiftEditorRow').style.display = 'none';
        document.getElementById('dShiftEditorMsg').style.display = 'none';
    }
    // Reads data-* attributes from the selected <option> to show the shift's schedule + lunch preview.
    function updateShiftPreview() {
        const sel = document.getElementById('dShiftSelect');
        if (!sel) return;
        const opt = sel.selectedOptions[0];
        if (!opt) return;
        const sched = (opt.dataset.start || '') + ' — ' + (opt.dataset.end || '');
        const lunch = (opt.dataset.lunchStart || '') + ' — ' + (opt.dataset.lunchEnd || '');
        document.getElementById('dShiftPreviewSched').textContent = sched;
        document.getElementById('dShiftPreviewLunch').textContent = lunch;
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
        const status = td.dataset.status;
        const empName = td.dataset.employee;
        const date = td.dataset.date;

        // Clear any lingering day-off status messages from a previous modal open
        const presentMsg = document.getElementById('dayOffMsgPresent');
        if (presentMsg) presentMsg.style.display = 'none';

        // Reset the inline shift editor and preselect the current shift for this cell
        const editorRow = document.getElementById('dShiftEditorRow');
        if (editorRow) editorRow.style.display = 'none';
        const shiftSelect = document.getElementById('dShiftSelect');
        if (shiftSelect && td.dataset.shiftId) {
            shiftSelect.value = td.dataset.shiftId;
        }
        const shiftMsg = document.getElementById('dShiftEditorMsg');
        if (shiftMsg) shiftMsg.style.display = 'none';

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

        // Shift: prefer the resolved shift-name (from date-based lookup), fall back to the AttendanceDay's shift
        document.getElementById('dShift').textContent = td.dataset.shiftName || td.dataset.shift || 'N/A';
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
            const text = document.getElementById('dText_' + field);
            if (text) {
                text.textContent = cfg.display || '-';
                text.classList.toggle('time-display-edited', editedFields.includes(field));
            }
            setTimeInputValue('dInput_' + field, cfg.raw);
            // Reset editor visibility, reason, and messages for each open
            const editor = document.getElementById('dEditor_' + field);
            if (editor) editor.style.display = 'none';
            const disp = document.getElementById('dDisplay_' + field);
            if (disp) disp.style.display = '';
            const reason = document.getElementById('dReason_' + field);
            if (reason) reason.value = '';
            const msg = document.getElementById('dMsg_' + field);
            if (msg) msg.style.display = 'none';
        });

        // Fetch raw punches once and populate all 4 punch pickers
        loadPunchesIntoPickers(td.dataset.employeeId, td.dataset.date);

        document.getElementById('dWork').textContent = fmtMin(td.dataset.work);
        document.getElementById('dLate').textContent = fmtMin(td.dataset.late);
        document.getElementById('dEarly').textContent = fmtMin(td.dataset.early);
        document.getElementById('dOT').textContent = fmtMin(td.dataset.ot);

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
                overrideDetails.forEach(function(ov) {
                    const oldVal = ov.old_value || '(empty)';
                    const newVal = ov.new_value || '(empty)';
                    html += '<div class="ov-entry">'
                        + '<strong>' + (fieldLabels[ov.field] || ov.field) + '</strong>: '
                        + '<span class="text-danger text-decoration-line-through">' + oldVal + '</span>'
                        + ' &rarr; <span class="text-success fw-semibold">' + newVal + '</span>'
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

    // Reveal the inline editor for a time field (hides the read-only display).
    function showTimeEditor(field) {
        const disp = document.getElementById('dDisplay_' + field);
        const editor = document.getElementById('dEditor_' + field);
        const msg = document.getElementById('dMsg_' + field);
        if (disp) disp.style.display = 'none';
        if (editor) editor.style.display = '';
        if (msg) msg.style.display = 'none';
        const reason = document.getElementById('dReason_' + field);
        if (reason) reason.focus();
    }

    // Collapse the editor back to display mode without saving.
    function cancelTimeEditor(field) {
        const disp = document.getElementById('dDisplay_' + field);
        const editor = document.getElementById('dEditor_' + field);
        const msg = document.getElementById('dMsg_' + field);
        const reason = document.getElementById('dReason_' + field);
        if (editor) editor.style.display = 'none';
        if (disp) disp.style.display = '';
        if (msg) msg.style.display = 'none';
        if (reason) reason.value = '';
    }

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
                        msg.textContent = 'Deleted. Reloading…';
                        msg.className = 'small mt-1 text-success';
                        msg.style.display = '';
                        setTimeout(() => location.reload(), 500);
                    } else {
                        msg.textContent = data.message || 'Error.';
                        msg.className = 'small mt-1 text-danger';
                        msg.style.display = '';
                    }
                })
                .catch(() => {
                    delBtn.disabled = false;
                    msg.textContent = 'Network error.';
                    msg.className = 'small mt-1 text-danger';
                    msg.style.display = '';
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
                            msg.textContent = 'Saved. Reloading…';
                            msg.className = 'small mt-1 text-success';
                            msg.style.display = '';
                            setTimeout(() => location.reload(), 500);
                        } else {
                            msg.textContent = data.message || 'Error.';
                            msg.className = 'small mt-1 text-danger';
                            msg.style.display = '';
                        }
                    })
                    .catch(() => {
                        saveBtn.disabled = false;
                        msg.textContent = 'Network error.';
                        msg.className = 'small mt-1 text-danger';
                        msg.style.display = '';
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

    // Day off toggle — works from both the Present modal and the Absent/Day-Off modal.
    // Picks whichever status message container is currently in the DOM.
    function toggleDayOff(action) {
        const empId = currentTd ? currentTd.dataset.employeeId : null;
        const date = currentTd ? currentTd.dataset.date : null;

        if (!empId || !date) return;

        // Prefer the message div in the Present body if it is currently visible,
        // otherwise fall back to the Absent/Day-Off container.
        const detailBodyVisible = document.getElementById('detailBody').style.display !== 'none';
        const msgDiv = detailBodyVisible
            ? document.getElementById('dayOffMsgPresent')
            : document.getElementById('dayOffMsg');
        if (msgDiv) msgDiv.style.display = 'none';

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
            }),
        })
        .then(r => r.json())
        .then(data => {
            if (!msgDiv) { if (data.success) location.reload(); return; }
            if (data.success) {
                msgDiv.textContent = data.message + ' Reloading...';
                msgDiv.className = 'small mt-2 text-success text-center';
                msgDiv.style.display = '';
                setTimeout(() => location.reload(), 800);
            } else {
                msgDiv.textContent = data.message || 'Error.';
                msgDiv.className = 'small mt-2 text-danger text-center';
                msgDiv.style.display = '';
            }
        })
        .catch(err => {
            if (!msgDiv) { alert('Network error. Please try again.'); return; }
            msgDiv.textContent = 'Network error. Please try again.';
            msgDiv.className = 'small mt-2 text-danger text-center';
            msgDiv.style.display = '';
        });
    }

    // When edit modal is closed, re-open detail modal
    document.getElementById('editTimeModal').addEventListener('hidden.bs.modal', function () {
        // Only re-open if not successfully saved (which triggers reload)
    });
</script>
@endpush
