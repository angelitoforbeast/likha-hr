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
    .cal-cell-late { background: #fff3cd !important; color: #664d03; font-weight: 600; }
    .cal-cell-undertime { background: #ffe5d0 !important; color: #984c0c; font-weight: 600; }
    .cal-cell-late-ut { background: #f8d7da !important; color: #842029; font-weight: 600; }
    .cal-cell-absent { background: #e2e3e5 !important; color: #41464b; font-weight: 600; }
    .cal-cell-dayoff { background: #cfe2ff !important; color: #084298; font-weight: 600; }
    .cal-cell-today { border: 2px solid #0d6efd !important; }
    .cal-legend { display: inline-flex; align-items: center; gap: .25rem; margin-right: 1rem; font-size: .8rem; }
    .cal-legend-box { width: 16px; height: 16px; border-radius: 3px; display: inline-block; }
    .employee-name-col { white-space: nowrap; font-weight: 600; font-size: .8rem; min-width: 140px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; }
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
                <label class="form-label small fw-semibold mb-1">Month</label>
                <input type="month" name="month" class="form-control form-control-sm" value="{{ $month }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> View</button>
            </div>
            <div class="col-auto">
                @php
                    $prevMonth = \Carbon\Carbon::parse($month . '-01')->subMonth()->format('Y-m');
                    $nextMonth = \Carbon\Carbon::parse($month . '-01')->addMonth()->format('Y-m');
                @endphp
                <a href="{{ url('/attendance-calendar') }}?filter_type={{ $filterType }}&department_id={{ $departmentId }}&employee_id={{ $employeeId }}&month={{ $prevMonth }}"
                   class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
                <a href="{{ url('/attendance-calendar') }}?filter_type={{ $filterType }}&department_id={{ $departmentId }}&employee_id={{ $employeeId }}&month={{ $nextMonth }}"
                   class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
            </div>
        </form>
    </div>
</div>

{{-- Legend --}}
<div class="mb-2">
    <span class="cal-legend"><span class="cal-legend-box" style="background:#d1e7dd"></span> Present</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#fff3cd"></span> Late</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#ffe5d0"></span> Undertime</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#f8d7da"></span> Late + Undertime</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#e2e3e5"></span> Absent</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#cfe2ff"></span> Day Off</span>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <h6 class="mb-0">
            {{ \Carbon\Carbon::parse($month . '-01')->format('F Y') }}
            <span class="text-muted small ms-2">{{ count($calendarData) }} employee(s)</span>
        </h6>
    </div>
    <div class="card-body p-0" style="overflow-x: auto;">
        @if(count($calendarData) > 0)
        <table class="table table-bordered cal-table mb-0">
            <thead class="table-light">
                <tr>
                    <th class="text-start" style="min-width:140px">Employee</th>
                    @for($d = 1; $d <= $daysInMonth; $d++)
                        @php
                            $dayDate = $startOfMonth->copy()->day($d);
                            $isToday = $dayDate->isToday();
                            $isSunday = $dayDate->dayOfWeek === 0;
                            $isSaturday = $dayDate->dayOfWeek === 6;
                        @endphp
                        <th class="{{ $isToday ? 'bg-primary text-white' : ($isSunday ? 'text-danger' : ($isSaturday ? 'text-primary' : '')) }}">
                            {{ $d }}<br>
                            <small>{{ $dayDate->format('D') }}</small>
                        </th>
                    @endfor
                    <th title="Present">P</th>
                    <th title="Absent">A</th>
                    <th title="Late">L</th>
                    <th title="Undertime">UT</th>
                </tr>
            </thead>
            <tbody>
                @foreach($calendarData as $empCal)
                @php
                    $countP = 0; $countA = 0; $countL = 0; $countUT = 0;
                @endphp
                <tr>
                    <td class="employee-name-col text-start" title="{{ $empCal['employee']->display_name }}">
                        <a href="{{ route('employees.edit', $empCal['employee']) }}" class="text-decoration-none">
                            {{ $empCal['employee']->display_name }}
                        </a>
                    </td>
                    @for($d = 1; $d <= $daysInMonth; $d++)
                        @php
                            $dayInfo = $empCal['days'][$d];
                            $cellClass = '';
                            $cellText = '';
                            $dayDate = $startOfMonth->copy()->day($d);
                            $isToday = $dayDate->isToday();
                            $att = $dayInfo['attendance'];

                            switch ($dayInfo['status']) {
                                case 'present':
                                    $cellClass = 'cal-cell-present';
                                    $cellText = 'P';
                                    $countP++;
                                    break;
                                case 'late':
                                    $cellClass = 'cal-cell-late';
                                    $cellText = 'L';
                                    $countP++;
                                    $countL++;
                                    break;
                                case 'undertime':
                                    $cellClass = 'cal-cell-undertime';
                                    $cellText = 'UT';
                                    $countP++;
                                    $countUT++;
                                    break;
                                case 'late_ut':
                                    $cellClass = 'cal-cell-late-ut';
                                    $cellText = 'L/UT';
                                    $countP++;
                                    $countL++;
                                    $countUT++;
                                    break;
                                case 'absent':
                                    $cellClass = 'cal-cell-absent';
                                    $cellText = 'A';
                                    $countA++;
                                    break;
                                case 'day_off':
                                    $cellClass = 'cal-cell-dayoff';
                                    $cellText = 'DO';
                                    break;
                            }

                            if ($isToday) $cellClass .= ' cal-cell-today';

                            // Build data attributes for modal
                            $dataAttrs = 'data-date="' . $dayInfo['date'] . '"'
                                . ' data-status="' . $dayInfo['status'] . '"'
                                . ' data-employee="' . $empCal['employee']->display_name . '"';

                            if ($att) {
                                $dataAttrs .= ' data-shift="' . ($att->shift->name ?? 'N/A') . '"'
                                    . ' data-time-in="' . ($att->time_in ? \Carbon\Carbon::parse($att->time_in)->format('h:i A') : '-') . '"'
                                    . ' data-lunch-out="' . ($att->lunch_out ? \Carbon\Carbon::parse($att->lunch_out)->format('h:i A') : '-') . '"'
                                    . ' data-lunch-in="' . ($att->lunch_in ? \Carbon\Carbon::parse($att->lunch_in)->format('h:i A') : '-') . '"'
                                    . ' data-time-out="' . ($att->time_out ? \Carbon\Carbon::parse($att->time_out)->format('h:i A') : '-') . '"'
                                    . ' data-work="' . ($att->computed_work_minutes ?? 0) . '"'
                                    . ' data-late="' . ($att->computed_late_minutes ?? 0) . '"'
                                    . ' data-early="' . ($att->computed_early_minutes ?? 0) . '"'
                                    . ' data-ot="' . ($att->computed_overtime_minutes ?? 0) . '"'
                                    . ' data-notes="' . e($att->notes ?? '') . '"';
                            }
                        @endphp
                        <td class="{{ $cellClass }}"
                            {!! $dataAttrs !!}
                            onclick="openDetail(this)"
                            title="{{ $empCal['employee']->display_name }} — {{ $dayInfo['date'] }}">
                            {{ $cellText }}
                        </td>
                    @endfor
                    <td class="fw-bold text-success">{{ $countP }}</td>
                    <td class="fw-bold text-danger">{{ $countA }}</td>
                    <td class="fw-bold text-warning">{{ $countL }}</td>
                    <td class="fw-bold" style="color:#984c0c">{{ $countUT }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="text-center text-muted py-5">
            <i class="bi bi-calendar-x" style="font-size: 2rem;"></i>
            <p class="mt-2">No employees with attendance records found for this month. Adjust filters above.</p>
        </div>
        @endif
    </div>
</div>

{{-- Detail Modal --}}
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="detailTitle">Attendance Detail</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <div id="detailBody">
                    <table class="table table-sm table-borderless mb-0" style="font-size:.85rem">
                        <tr><td class="text-muted">Employee</td><td class="fw-semibold" id="dEmployee"></td></tr>
                        <tr><td class="text-muted">Date</td><td class="fw-semibold" id="dDate"></td></tr>
                        <tr><td class="text-muted">Status</td><td class="fw-semibold" id="dStatus"></td></tr>
                        <tr><td class="text-muted">Shift</td><td id="dShift"></td></tr>
                        <tr><td colspan="2"><hr class="my-1"></td></tr>
                        <tr><td class="text-muted">Time In</td><td id="dTimeIn"></td></tr>
                        <tr><td class="text-muted">Lunch Out</td><td id="dLunchOut"></td></tr>
                        <tr><td class="text-muted">Lunch In</td><td id="dLunchIn"></td></tr>
                        <tr><td class="text-muted">Time Out</td><td id="dTimeOut"></td></tr>
                        <tr><td colspan="2"><hr class="my-1"></td></tr>
                        <tr><td class="text-muted">Work</td><td id="dWork"></td></tr>
                        <tr><td class="text-muted">Late</td><td id="dLate"></td></tr>
                        <tr><td class="text-muted">Undertime</td><td id="dEarly"></td></tr>
                        <tr><td class="text-muted">Overtime</td><td id="dOT"></td></tr>
                        <tr id="dNotesRow"><td class="text-muted">Notes</td><td id="dNotes"></td></tr>
                    </table>
                </div>
                <div id="detailNoData" class="text-center text-muted py-3" style="display:none">
                    <p class="mb-0" id="dNoDataText"></p>
                </div>
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

    const statusLabels = {
        'present': 'Present',
        'late': 'Late',
        'undertime': 'Undertime',
        'late_ut': 'Late + Undertime',
        'absent': 'Absent',
        'day_off': 'Day Off',
    };

    const statusColors = {
        'present': '#0f5132',
        'late': '#664d03',
        'undertime': '#984c0c',
        'late_ut': '#842029',
        'absent': '#41464b',
        'day_off': '#084298',
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

    function openDetail(td) {
        const status = td.dataset.status;
        const empName = td.dataset.employee;
        const date = td.dataset.date;

        document.getElementById('detailTitle').textContent = date;

        if (status === 'absent' || status === 'day_off') {
            document.getElementById('detailBody').style.display = 'none';
            document.getElementById('detailNoData').style.display = '';
            document.getElementById('dNoDataText').textContent = empName + ' — ' + statusLabels[status];
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
        document.getElementById('dTimeIn').textContent = td.dataset.timeIn || '-';
        document.getElementById('dLunchOut').textContent = td.dataset.lunchOut || '-';
        document.getElementById('dLunchIn').textContent = td.dataset.lunchIn || '-';
        document.getElementById('dTimeOut').textContent = td.dataset.timeOut || '-';
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

        detailModal.show();
    }
</script>
@endpush
