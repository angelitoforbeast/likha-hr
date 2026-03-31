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
    .override-dot { color: #6f42c1; font-size: .6rem; vertical-align: super; }
    .override-history { font-size: .78rem; background: #f8f9fa; border-radius: 4px; padding: 6px 8px; margin-top: 4px; }
    .override-history .ov-entry { border-bottom: 1px solid #e9ecef; padding: 3px 0; }
    .override-history .ov-entry:last-child { border-bottom: none; }
    .dayoff-action-btn { font-size: .75rem; padding: 2px 8px; }
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
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> View</button>
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
        <table class="table table-bordered cal-table mb-0">
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
                            title="{{ $empCal['employee']->display_name }} — {{ $dayInfo['date'] }}">
                            {{ $cellText }}
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
                        <tr><td class="text-muted">Shift</td><td id="dShift"></td></tr>
                        <tr><td colspan="2"><hr class="my-1"></td></tr>
                        <tr>
                            <td class="text-muted">Time In</td>
                            <td><span class="time-editable" id="dTimeIn" data-field="time_in" onclick="startEdit(this)"></span></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Lunch Out</td>
                            <td><span class="time-editable" id="dLunchOut" data-field="lunch_out" onclick="startEdit(this)"></span></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Lunch In</td>
                            <td><span class="time-editable" id="dLunchIn" data-field="lunch_in" onclick="startEdit(this)"></span></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Time Out</td>
                            <td><span class="time-editable" id="dTimeOut" data-field="time_out" onclick="startEdit(this)"></span></td>
                        </tr>
                        <tr><td colspan="2"><hr class="my-1"></td></tr>
                        <tr><td class="text-muted">Work</td><td id="dWork"></td></tr>
                        <tr><td class="text-muted">Late</td><td id="dLate"></td></tr>
                        <tr><td class="text-muted">Undertime</td><td id="dEarly"></td></tr>
                        <tr><td class="text-muted">Overtime</td><td id="dOT"></td></tr>
                        <tr id="dNotesRow"><td class="text-muted">Notes</td><td id="dNotes"></td></tr>
                    </table>
                    {{-- Override history details --}}
                    <div id="dOverrideHistory" class="override-history mt-2" style="display:none">
                        <div class="fw-semibold mb-1" style="color:#6f42c1"><i class="bi bi-pencil-square"></i> Edit History</div>
                        <div id="dOverrideList"></div>
                    </div>
                </div>

                {{-- Absent / Day Off detail --}}
                <div id="detailNoData" class="text-center py-3" style="display:none">
                    <p class="mb-2 fw-semibold" id="dNoDataText"></p>
                    <div id="dayOffActions" style="display:none">
                        <hr class="my-2">
                        <p class="text-muted small mb-2">Manage rest day for this date:</p>
                        <div class="d-flex gap-2 justify-content-center flex-wrap">
                            <button class="btn btn-sm btn-outline-primary dayoff-action-btn" onclick="toggleDayOff('add_day_off')">
                                <i class="bi bi-plus-circle"></i> Mark as Rest Day
                            </button>
                            <button class="btn btn-sm btn-outline-warning dayoff-action-btn" onclick="toggleDayOff('cancel_day_off')">
                                <i class="bi bi-x-circle"></i> Cancel Rest Day
                            </button>
                            <button class="btn btn-sm btn-outline-danger dayoff-action-btn" onclick="toggleDayOff('remove_override')">
                                <i class="bi bi-trash"></i> Remove Override
                            </button>
                        </div>
                        <div id="dayOffMsg" class="small mt-2" style="display:none"></div>
                    </div>
                </div>
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

    let currentTd = null;

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

    function openDetail(td) {
        currentTd = td;
        const status = td.dataset.status;
        const empName = td.dataset.employee;
        const date = td.dataset.date;

        document.getElementById('detailTitle').textContent = empName + ' — ' + date;

        if (status === 'absent' || status === 'day_off') {
            document.getElementById('detailBody').style.display = 'none';
            document.getElementById('detailNoData').style.display = '';
            document.getElementById('dNoDataText').textContent = empName + ' — ' + statusLabels[status];
            document.getElementById('dNoDataText').style.color = statusColors[status];

            // Show day off action buttons
            document.getElementById('dayOffActions').style.display = '';
            document.getElementById('dayOffMsg').style.display = 'none';

            detailModal.show();
            return;
        }

        document.getElementById('detailBody').style.display = '';
        document.getElementById('detailNoData').style.display = 'none';

        document.getElementById('dEmployee').textContent = empName;
        document.getElementById('dDate').textContent = date;

        const statusEl = document.getElementById('dStatus');
        statusEl.textContent = statusLabels[status] || status;
        statusEl.style.color = statusColors[status] || '#000';

        document.getElementById('dShift').textContent = td.dataset.shift || 'N/A';

        // Set time values with edited field indicators
        const editedFieldsStr = td.dataset.editedFields || '';
        const editedFields = editedFieldsStr ? editedFieldsStr.split(',') : [];

        setTimeDisplay('dTimeIn', td.dataset.timeInDisplay, td.dataset.timeIn, editedFields.includes('time_in'));
        setTimeDisplay('dLunchOut', td.dataset.lunchOutDisplay, td.dataset.lunchOut, editedFields.includes('lunch_out'));
        setTimeDisplay('dLunchIn', td.dataset.lunchInDisplay, td.dataset.lunchIn, editedFields.includes('lunch_in'));
        setTimeDisplay('dTimeOut', td.dataset.timeOutDisplay, td.dataset.timeOut, editedFields.includes('time_out'));

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
        el.textContent = displayVal || '-';
        el.dataset.rawValue = rawVal || '';
        if (isEdited) {
            el.classList.add('time-edited');
            el.innerHTML = (displayVal || '-') + ' <i class="bi bi-pencil-fill" style="font-size:.6rem;color:#6f42c1"></i>';
        } else {
            el.classList.remove('time-edited');
        }
    }

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

    // Day off toggle
    function toggleDayOff(action) {
        const empId = currentTd ? currentTd.dataset.employeeId : null;
        const date = currentTd ? currentTd.dataset.date : null;

        if (!empId || !date) return;

        const msgDiv = document.getElementById('dayOffMsg');
        msgDiv.style.display = 'none';

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
            if (data.success) {
                msgDiv.textContent = data.message + ' Reloading...';
                msgDiv.className = 'small mt-2 text-success';
                msgDiv.style.display = '';
                setTimeout(() => location.reload(), 800);
            } else {
                msgDiv.textContent = data.message || 'Error.';
                msgDiv.className = 'small mt-2 text-danger';
                msgDiv.style.display = '';
            }
        })
        .catch(err => {
            msgDiv.textContent = 'Network error. Please try again.';
            msgDiv.className = 'small mt-2 text-danger';
            msgDiv.style.display = '';
        });
    }

    // When edit modal is closed, re-open detail modal
    document.getElementById('editTimeModal').addEventListener('hidden.bs.modal', function () {
        // Only re-open if not successfully saved (which triggers reload)
    });
</script>
@endpush
