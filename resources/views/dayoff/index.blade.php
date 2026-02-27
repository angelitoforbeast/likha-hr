@extends('layouts.app')

@section('title', 'Day Off Calendar')
@section('page-title', 'Day Off Calendar')

@section('content')
<style>
    .cal-table { font-size: .8rem; }
    .cal-table th { text-align: center; padding: .35rem .25rem; }
    .cal-table td { text-align: center; padding: .35rem .25rem; cursor: pointer; position: relative; min-width: 32px; }
    .cal-table td:hover { background: #e9ecef; }
    .cal-cell-rest { background: #cfe2ff !important; color: #084298; font-weight: 600; }
    .cal-cell-dayoff { background: #f8d7da !important; color: #842029; font-weight: 600; }
    .cal-cell-work { background: #d1e7dd !important; color: #0f5132; }
    .cal-cell-cancelled { background: #fff3cd !important; color: #664d03; font-weight: 600; }
    .cal-cell-today { border: 2px solid #0d6efd !important; }
    .cal-legend { display: inline-flex; align-items: center; gap: .25rem; margin-right: 1rem; font-size: .8rem; }
    .cal-legend-box { width: 16px; height: 16px; border-radius: 3px; display: inline-block; }
    .employee-name-col { white-space: nowrap; font-weight: 600; font-size: .8rem; min-width: 140px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; }
</style>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ url('/day-off-calendar') }}" class="row g-2 align-items-end">
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
                <a href="{{ url('/day-off-calendar') }}?filter_type={{ $filterType }}&department_id={{ $departmentId }}&employee_id={{ $employeeId }}&month={{ $prevMonth }}"
                   class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
                <a href="{{ url('/day-off-calendar') }}?filter_type={{ $filterType }}&department_id={{ $departmentId }}&employee_id={{ $employeeId }}&month={{ $nextMonth }}"
                   class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
            </div>
        </form>
    </div>
</div>

{{-- Legend --}}
<div class="mb-2">
    <span class="cal-legend"><span class="cal-legend-box" style="background:#d1e7dd"></span> Work Day</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#cfe2ff"></span> Rest Day (Pattern)</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#f8d7da"></span> Extra Day Off</span>
    <span class="cal-legend"><span class="cal-legend-box" style="background:#fff3cd"></span> Cancelled Rest Day (Must Work)</span>
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
                    <th>Off</th>
                </tr>
            </thead>
            <tbody>
                @foreach($calendarData as $empCal)
                @php $offCount = 0; @endphp
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

                            if ($dayInfo['status'] === 'rest_day') {
                                $cellClass = 'cal-cell-rest';
                                $cellText = 'RD';
                                $offCount++;
                            } elseif ($dayInfo['status'] === 'day_off') {
                                $cellClass = 'cal-cell-dayoff';
                                $cellText = 'OFF';
                                $offCount++;
                            } elseif ($dayInfo['status'] === 'work' && $dayInfo['has_override']) {
                                $cellClass = 'cal-cell-cancelled';
                                $cellText = 'W*';
                            } else {
                                $cellText = '·';
                            }

                            if ($isToday) $cellClass .= ' cal-cell-today';
                        @endphp
                        <td class="{{ $cellClass }}"
                            data-employee="{{ $empCal['employee']->id }}"
                            data-date="{{ $dayInfo['date'] }}"
                            data-status="{{ $dayInfo['status'] }}"
                            data-override="{{ $dayInfo['has_override'] ? '1' : '0' }}"
                            onclick="openDayMenu(this)"
                            title="{{ $empCal['employee']->display_name }} — {{ $dayInfo['date'] }}">
                            {{ $cellText }}
                        </td>
                    @endfor
                    <td class="fw-bold text-danger">{{ $offCount }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="text-center text-muted py-5">
            <i class="bi bi-calendar-x" style="font-size: 2rem;"></i>
            <p class="mt-2">No employees found. Adjust filters above.</p>
        </div>
        @endif
    </div>
</div>

{{-- Context Menu Modal --}}
<div class="modal fade" id="dayActionModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="dayActionTitle">Day Action</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <p class="small mb-2" id="dayActionInfo"></p>
                <div class="d-grid gap-1">
                    <button class="btn btn-sm btn-outline-danger" onclick="toggleDay('add_day_off')">
                        <i class="bi bi-calendar-x"></i> Mark as Day Off
                    </button>
                    <button class="btn btn-sm btn-outline-warning" onclick="toggleDay('cancel_day_off')">
                        <i class="bi bi-calendar-check"></i> Cancel Day Off (Must Work)
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="toggleDay('remove_override')">
                        <i class="bi bi-arrow-counterclockwise"></i> Remove Override (Use Pattern)
                    </button>
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

    let selectedCell = null;
    const dayModal = new bootstrap.Modal(document.getElementById('dayActionModal'));

    function openDayMenu(td) {
        selectedCell = td;
        const empId = td.dataset.employee;
        const date = td.dataset.date;
        const status = td.dataset.status;
        const hasOverride = td.dataset.override === '1';

        document.getElementById('dayActionTitle').textContent = date;
        let info = 'Current: ';
        if (status === 'rest_day') info += 'Rest Day (from pattern)';
        else if (status === 'day_off') info += 'Extra Day Off (override)';
        else if (status === 'work' && hasOverride) info += 'Work Day (cancelled rest day)';
        else info += 'Work Day';
        document.getElementById('dayActionInfo').textContent = info;

        dayModal.show();
    }

    function toggleDay(action) {
        if (!selectedCell) return;
        const empId = selectedCell.dataset.employee;
        const date = selectedCell.dataset.date;

        fetch('{{ url("/day-off-calendar/toggle") }}', {
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
                // Reload page to reflect changes
                dayModal.hide();
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            alert('Network error');
            console.error(err);
        });
    }
</script>
@endpush
