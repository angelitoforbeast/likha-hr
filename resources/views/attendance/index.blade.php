@extends('layouts.app')

@section('title', 'Attendance Viewer')
@section('page-title', 'Attendance Viewer')

@section('content')
{{-- Compute Trigger --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="POST" action="{{ route('attendance.compute') }}" class="row g-2 align-items-end" id="computeForm">
            @csrf
            <div class="col-auto">
                <label class="form-label small">Compute From</label>
                <input type="date" name="start_date" class="form-control form-control-sm"
                       value="{{ $startDate }}" required>
            </div>
            <div class="col-auto">
                <label class="form-label small">To</label>
                <input type="date" name="end_date" class="form-control form-control-sm"
                       value="{{ $endDate }}" required>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-warning">
                    <i class="bi bi-calculator"></i> Compute Attendance
                </button>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-danger" id="forceRecomputeBtn">
                    <i class="bi bi-arrow-repeat"></i> Force Recompute
                </button>
            </div>
        </form>

        {{-- Hidden Force Recompute Form --}}
        <form method="POST" action="{{ route('attendance.force-compute') }}" id="forceRecomputeForm" class="d-none">
            @csrf
            <input type="hidden" name="start_date" id="forceStartDate">
            <input type="hidden" name="end_date" id="forceEndDate">
        </form>
    </div>
</div>

{{-- Filters --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Date Range</label>
                <input type="text" id="dateRangePicker" class="form-control form-control-sm"
                       placeholder="Select date range..." readonly>
                <input type="hidden" name="start_date" id="filterStartDate" value="{{ $startDate }}">
                <input type="hidden" name="end_date" id="filterEndDate" value="{{ $endDate }}">
            </div>
            <div class="col-auto">
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-primary" id="btnLastCutoff" title="Last completed cutoff">
                        <i class="bi bi-skip-backward"></i> Last Cutoff
                    </button>
                    <button type="button" class="btn btn-outline-info" id="btnThisCutoff" title="Current ongoing cutoff">
                        <i class="bi bi-calendar-event"></i> This Cutoff
                    </button>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Employee</label>
                <select name="employee_id" class="form-select form-select-sm" id="filterEmployee">
                    <option value="">All Employees</option>
                    @foreach($employeesInRange as $emp)
                        <option value="{{ $emp->id }}" {{ request('employee_id') == $emp->id ? 'selected' : '' }}>
                            {{ $emp->display_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Department</label>
                <select name="department_id" class="form-select form-select-sm" id="filterDepartment">
                    <option value="">All Departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>
                            {{ $dept->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small fw-semibold">Review</label>
                <select name="needs_review" class="form-select form-select-sm" id="filterReview">
                    <option value="">All</option>
                    <option value="1" {{ request('needs_review') === '1' ? 'selected' : '' }}>Yes</option>
                    <option value="0" {{ request('needs_review') === '0' ? 'selected' : '' }}>No</option>
                </select>
            </div>
        </form>
    </div>
</div>

{{-- Export & Copy Buttons --}}
<div class="d-flex gap-2 mb-3 no-print">
    <a href="{{ route('attendance.export-csv', request()->query()) }}" class="btn btn-sm btn-outline-success" id="exportCsvLink">
        <i class="bi bi-filetype-csv"></i> Export CSV
    </a>
    <a href="{{ route('attendance.print', request()->query()) }}" class="btn btn-sm btn-outline-secondary" target="_blank" id="printLink">
        <i class="bi bi-printer"></i> Print View
    </a>
    <button type="button" class="btn btn-sm btn-outline-info" id="copyTableBtn">
        <i class="bi bi-clipboard"></i> Copy Table
    </button>
</div>

{{-- Attendance Table --}}
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" id="attendanceTable" style="font-size: 0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th class="sortable" data-col="0">Employee <i class="bi bi-arrow-down-up small"></i></th>
                        <th class="sortable" data-col="1">Dept <i class="bi bi-arrow-down-up small"></i></th>
                        <th class="sortable" data-col="2">Date <i class="bi bi-arrow-down-up small"></i></th>
                        <th>Status</th>
                        <th>Shift</th>
                        <th class="text-center editable-col">Time In</th>
                        <th class="text-center editable-col">Lunch Out</th>
                        <th class="text-center editable-col">Lunch In</th>
                        <th class="text-center editable-col">Time Out</th>
                        <th class="text-center sortable" data-col="9">Work <i class="bi bi-arrow-down-up small"></i></th>
                        <th class="text-center sortable" data-col="10">Late <i class="bi bi-arrow-down-up small"></i></th>
                        <th class="text-center sortable" data-col="11">Early <i class="bi bi-arrow-down-up small"></i></th>
                        <th class="text-center sortable" data-col="12">OT <i class="bi bi-arrow-down-up small"></i></th>
                        <th class="text-center sortable" data-col="13">Payable <i class="bi bi-arrow-down-up small"></i></th>
                        <th class="text-center">Review</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($days as $row)
                    @if($row->type === 'present')
                    @php
                        $day = $row->attendance_day;
                        $dayOverrides = $overrides[$day->id] ?? collect();
                        $editedFields = $dayOverrides->pluck('field')->unique()->toArray();
                    @endphp
                    <tr class="{{ $day->needs_review ? 'table-warning' : '' }}">
                        <td>{{ $row->employee->display_name ?? '—' }}</td>
                        <td>
                            @if($row->employee->department ?? null)
                                <span class="badge bg-info text-dark" style="font-size:0.75rem;">{{ $row->employee->department->name }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $row->work_date->format('M d, Y') }}</td>
                        <td><span class="badge bg-success" style="font-size:0.7rem;">Present</span></td>
                        <td>
                            <select class="form-select form-select-sm shift-select"
                                    data-day-id="{{ $day->id }}"
                                    data-original="{{ $day->shift_id }}"
                                    style="width:120px; font-size:0.8rem;">
                                <option value="">—</option>
                                @foreach($shifts as $shift)
                                    <option value="{{ $shift->id }}" {{ $day->shift_id == $shift->id ? 'selected' : '' }}>
                                        {{ $shift->name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        @foreach(['time_in', 'lunch_out', 'lunch_in', 'time_out'] as $timeField)
                        @php
                            $isEdited = in_array($timeField, $editedFields);
                            $fieldOverrides = $dayOverrides->where('field', $timeField);
                            $timeVal = $day->{$timeField} ? \Carbon\Carbon::parse($day->{$timeField})->format('H:i') : '';
                        @endphp
                        <td class="text-center">
                            <span class="time-cell {{ $isEdited ? 'edited-cell' : '' }}" role="button"
                                  data-day-id="{{ $day->id }}" data-field="{{ $timeField }}"
                                  data-value="{{ $timeVal }}"
                                  @if($isEdited)
                                  data-bs-toggle="popover"
                                  data-bs-trigger="hover"
                                  data-bs-html="true"
                                  data-bs-placement="top"
                                  data-bs-content="@foreach($fieldOverrides as $ov)<div class='small mb-1'><strong>{{ $ov->updater->name ?? 'Unknown' }}</strong> on {{ $ov->created_at->format('M d, Y g:i A') }}<br>{{ $ov->old_value ?? '(empty)' }} &rarr; {{ $ov->new_value ?? '(empty)' }}<br><em>{{ $ov->reason }}</em></div>@endforeach"
                                  @endif
                            >
                                {{ $timeVal ?: '—' }}
                                @if($isEdited)
                                    <i class="bi bi-pencil-fill text-primary" style="font-size:0.65rem;"></i>
                                @elseif(!$timeVal)
                                    <i class="bi bi-pencil text-muted" style="font-size:0.6rem; opacity:0.5;"></i>
                                @endif
                            </span>
                        </td>
                        @endforeach
                        <td class="text-center">{{ $day->computed_work_minutes }}</td>
                        <td class="text-center {{ $day->computed_late_minutes > 0 ? 'text-danger fw-bold' : '' }}">
                            {{ $day->computed_late_minutes }}
                        </td>
                        <td class="text-center {{ $day->computed_early_minutes > 0 ? 'text-danger fw-bold' : '' }}">
                            {{ $day->computed_early_minutes }}
                        </td>
                        <td class="text-center {{ $day->computed_overtime_minutes > 0 ? 'text-success fw-bold' : '' }}">
                            {{ $day->computed_overtime_minutes }}
                        </td>
                        <td class="text-center fw-bold">{{ $day->payable_work_minutes }}</td>
                        <td class="text-center">
                            @if($day->needs_review)
                                <span class="badge bg-warning text-dark">YES</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                    @elseif($row->type === 'day_off')
                    <tr class="table-light" style="opacity: 0.75;">
                        <td>{{ $row->employee->display_name ?? '—' }}</td>
                        <td>
                            @if($row->employee->department ?? null)
                                <span class="badge bg-info text-dark" style="font-size:0.75rem;">{{ $row->employee->department->name }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $row->work_date->format('M d, Y') }}</td>
                        <td><span class="badge bg-primary" style="font-size:0.7rem;">Day Off</span></td>
                        <td class="text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                    </tr>
                    @elseif($row->type === 'absent')
                    <tr class="table-danger" style="opacity: 0.85;">
                        <td>{{ $row->employee->display_name ?? '—' }}</td>
                        <td>
                            @if($row->employee->department ?? null)
                                <span class="badge bg-info text-dark" style="font-size:0.75rem;">{{ $row->employee->department->name }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $row->work_date->format('M d, Y') }}</td>
                        <td><span class="badge bg-danger" style="font-size:0.7rem;">Absent</span></td>
                        @if(auth()->user()->role === 'ceo')
                        <td>
                            <select class="form-select form-select-sm absent-shift-select"
                                    data-employee-id="{{ $row->employee->id }}"
                                    data-work-date="{{ $row->work_date->format('Y-m-d') }}"
                                    style="width:120px; font-size:0.8rem;">
                                <option value="">—</option>
                                @foreach($shifts as $shift)
                                    <option value="{{ $shift->id }}">{{ $shift->name }}</option>
                                @endforeach
                            </select>
                        </td>
                        @foreach(['time_in', 'lunch_out', 'lunch_in', 'time_out'] as $absentField)
                        <td class="text-center">
                            <span class="absent-time-cell" role="button"
                                  data-employee-id="{{ $row->employee->id }}"
                                  data-work-date="{{ $row->work_date->format('Y-m-d') }}"
                                  data-field="{{ $absentField }}"
                            >
                                —
                                <i class="bi bi-plus-circle text-danger" style="font-size:0.6rem;"></i>
                            </span>
                        </td>
                        @endforeach
                        @else
                        <td class="text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        <td class="text-center text-muted">—</td>
                        @endif
                        <td class="text-center">0</td>
                        <td class="text-center">0</td>
                        <td class="text-center">0</td>
                        <td class="text-center">0</td>
                        <td class="text-center">0</td>
                        <td class="text-center text-muted">—</td>
                    </tr>
                    @endif
                    @empty
                    <tr>
                        <td colspan="15" class="text-center text-muted py-4">
                            No attendance records found. Try adjusting filters or compute attendance first.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($days->hasPages())
    <div class="card-footer bg-white">
        {{ $days->links() }}
    </div>
    @endif
</div>

{{-- Override Modal --}}
<div class="modal fade" id="overrideModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Time</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="override-day-id">
                <input type="hidden" id="override-field">
                <div class="mb-3">
                    <label class="form-label">Field</label>
                    <input type="text" class="form-control" id="override-field-display" disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label">New Time (HH:MM)</label>
                    <input type="time" class="form-control" id="override-new-value">
                </div>
                <div class="mb-3">
                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="override-reason" rows="2" required
                              placeholder="Explain why this time is being changed..."></textarea>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="override-clear">
                    <label class="form-check-label" for="override-clear">Clear this value (set to empty)</label>
                </div>
                <div id="override-error" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="override-save">Save Override</button>
            </div>
        </div>
    </div>
</div>

{{-- Shift Override Reason Modal --}}
<div class="modal fade" id="shiftReasonModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Shift - Reason Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="shift-day-id">
                <input type="hidden" id="shift-new-value">
                <div class="mb-3">
                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="shift-reason" rows="2" required
                              placeholder="Explain why the shift is being changed..."></textarea>
                </div>
                <div id="shift-error" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="shift-save">Save Shift Change</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
    .time-cell {
        cursor: pointer;
        padding: 2px 8px;
        border-radius: 4px;
        transition: background 0.2s;
    }
    .time-cell:hover {
        background: #e3f2fd;
        text-decoration: underline;
    }
    .edited-cell {
        background: #fff3cd !important;
        border: 1px solid #ffc107;
        border-radius: 4px;
        font-weight: 600;
    }
    .edited-cell:hover {
        background: #ffe69c !important;
    }
    .sortable {
        cursor: pointer;
        user-select: none;
    }
    .sortable:hover {
        background: #e9ecef;
    }
    .sort-asc .bi-arrow-down-up::before { content: "\F127"; }
    .sort-desc .bi-arrow-down-up::before { content: "\F128"; }
    .flatpickr-input {
        background-color: #fff !important;
    }
    .absent-time-cell {
        cursor: pointer;
        padding: 2px 8px;
        border-radius: 4px;
        transition: background 0.2s;
    }
    .absent-time-cell:hover {
        background: #ffcdd2;
        text-decoration: underline;
    }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    // ========== FLATPICKR DATE RANGE ==========
    const fp = flatpickr('#dateRangePicker', {
        mode: 'range',
        dateFormat: 'Y-m-d',
        defaultDate: ['{{ $startDate }}', '{{ $endDate }}'],
        onChange: function(selectedDates) {
            if (selectedDates.length === 2) {
                const start = selectedDates[0].toISOString().split('T')[0];
                const end = selectedDates[1].toISOString().split('T')[0];
                document.getElementById('filterStartDate').value = start;
                document.getElementById('filterEndDate').value = end;
                submitFilter();
            }
        }
    });

    // ========== CUTOFF QUICK BUTTONS ==========
    let cutoffData = null;

    fetch('{{ route("attendance.cutoff-dates") }}', {
        headers: { 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        cutoffData = data;
        document.getElementById('btnLastCutoff').title =
            'Last Cutoff: ' + data.last_cutoff.start + ' to ' + data.last_cutoff.end;
        document.getElementById('btnThisCutoff').title =
            'This Cutoff: ' + data.this_cutoff.start + ' to ' + data.this_cutoff.end;
    });

    document.getElementById('btnLastCutoff').addEventListener('click', function() {
        if (cutoffData) {
            fp.setDate([cutoffData.last_cutoff.start, cutoffData.last_cutoff.end]);
            document.getElementById('filterStartDate').value = cutoffData.last_cutoff.start;
            document.getElementById('filterEndDate').value = cutoffData.last_cutoff.end;
            submitFilter();
        }
    });

    document.getElementById('btnThisCutoff').addEventListener('click', function() {
        if (cutoffData) {
            fp.setDate([cutoffData.this_cutoff.start, cutoffData.this_cutoff.end]);
            document.getElementById('filterStartDate').value = cutoffData.this_cutoff.start;
            document.getElementById('filterEndDate').value = cutoffData.this_cutoff.end;
            submitFilter();
        }
    });

    // ========== AUTO-SEARCH ON FILTER CHANGE ==========
    ['filterEmployee', 'filterDepartment', 'filterReview'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', function() {
            submitFilter();
        });
    });

    function submitFilter() {
        document.getElementById('filterForm').submit();
    }

    // ========== POPOVERS ==========
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (el) {
        return new bootstrap.Popover(el, { container: 'body' });
    });

    // ========== TIME CELL CLICK -> OVERRIDE MODAL ==========
    document.querySelectorAll('.time-cell').forEach(cell => {
        cell.addEventListener('click', function(e) {
            const dayId = this.dataset.dayId;
            const field = this.dataset.field;
            const value = this.dataset.value;

            document.getElementById('override-day-id').value = dayId;
            document.getElementById('override-day-id').dataset.isAbsent = 'false';
            document.getElementById('override-field').value = field;
            document.getElementById('override-field-display').value = field.replace(/_/g, ' ').toUpperCase();
            document.getElementById('override-new-value').value = value;
            document.getElementById('override-reason').value = '';
            document.getElementById('override-clear').checked = false;
            document.getElementById('override-error').classList.add('d-none');

            new bootstrap.Modal(document.getElementById('overrideModal')).show();
        });
    });

    // ========== SAVE OVERRIDE ==========
    document.getElementById('override-save').addEventListener('click', function() {
        const dayIdEl = document.getElementById('override-day-id');
        let dayId = dayIdEl.value;
        const isAbsent = dayIdEl.dataset.isAbsent === 'true';
        const field = document.getElementById('override-field').value;
        const clearVal = document.getElementById('override-clear').checked;
        const newValue = clearVal ? '' : document.getElementById('override-new-value').value;
        const reason = document.getElementById('override-reason').value.trim();

        if (!reason || reason.length < 3) {
            document.getElementById('override-error').textContent = 'Reason is required (min 3 characters).';
            document.getElementById('override-error').classList.remove('d-none');
            return;
        }

        if (!newValue && !clearVal) {
            document.getElementById('override-error').textContent = 'Please enter a time value.';
            document.getElementById('override-error').classList.remove('d-none');
            return;
        }

        // If absent row, first create attendance day, then override
        if (isAbsent && !dayId) {
            const employeeId = dayIdEl.dataset.absentEmployeeId;
            const workDate = dayIdEl.dataset.absentWorkDate;

            fetch('{{ route("attendance.create-day") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    employee_id: employeeId,
                    work_date: workDate,
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.attendance_day_id) {
                    // Now save the override with the new day ID
                    dayIdEl.value = data.attendance_day_id;
                    dayIdEl.dataset.isAbsent = 'false';
                    saveOverride(data.attendance_day_id, field, newValue, reason);
                } else {
                    document.getElementById('override-error').textContent = data.message || 'Error creating attendance day.';
                    document.getElementById('override-error').classList.remove('d-none');
                }
            })
            .catch(err => {
                document.getElementById('override-error').textContent = 'Network error creating attendance day.';
                document.getElementById('override-error').classList.remove('d-none');
            });
        } else {
            saveOverride(dayId, field, newValue, reason);
        }
    });

    function saveOverride(dayId, field, newValue, reason) {
        fetch('{{ route("attendance.override") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                attendance_day_id: dayId,
                field: field,
                new_value: newValue || null,
                reason: reason,
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('overrideModal')).hide();
                location.reload();
            } else {
                document.getElementById('override-error').textContent = data.message || 'Error saving override.';
                document.getElementById('override-error').classList.remove('d-none');
            }
        })
        .catch(err => {
            document.getElementById('override-error').textContent = 'Network error. Please try again.';
            document.getElementById('override-error').classList.remove('d-none');
        });
    }

    // ========== SHIFT DROPDOWN CHANGE ==========
    document.querySelectorAll('.shift-select').forEach(select => {
        select.addEventListener('change', function() {
            const dayId = this.dataset.dayId;
            const original = this.dataset.original;
            const newVal = this.value;

            if (newVal === original) return;

            document.getElementById('shift-day-id').value = dayId;
            document.getElementById('shift-new-value').value = newVal;
            document.getElementById('shift-reason').value = '';
            document.getElementById('shift-error').classList.add('d-none');

            new bootstrap.Modal(document.getElementById('shiftReasonModal')).show();
        });
    });

    // ========== SAVE SHIFT CHANGE ==========
    document.getElementById('shift-save').addEventListener('click', function() {
        const dayId = document.getElementById('shift-day-id').value;
        const newValue = document.getElementById('shift-new-value').value;
        const reason = document.getElementById('shift-reason').value.trim();

        if (!reason || reason.length < 3) {
            document.getElementById('shift-error').textContent = 'Reason is required (min 3 characters).';
            document.getElementById('shift-error').classList.remove('d-none');
            return;
        }

        fetch('{{ route("attendance.override") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                attendance_day_id: dayId,
                field: 'shift_id',
                new_value: newValue || null,
                reason: reason,
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('shiftReasonModal')).hide();
                location.reload();
            } else {
                document.getElementById('shift-error').textContent = data.message || 'Error saving shift change.';
                document.getElementById('shift-error').classList.remove('d-none');
            }
        })
        .catch(err => {
            document.getElementById('shift-error').textContent = 'Network error. Please try again.';
            document.getElementById('shift-error').classList.remove('d-none');
        });
    });

    // ========== COPY TABLE ==========
    document.getElementById('copyTableBtn').addEventListener('click', function() {
        const table = document.getElementById('attendanceTable');
        let text = '';
        const rows = table.querySelectorAll('tr');
        rows.forEach(row => {
            const cells = row.querySelectorAll('th, td');
            const rowData = [];
            cells.forEach(cell => {
                let cellText = '';
                const select = cell.querySelector('select');
                if (select) {
                    cellText = select.options[select.selectedIndex]?.text?.trim() || '';
                } else {
                    cellText = cell.innerText.trim().replace(/[\n\r]+/g, ' ');
                }
                rowData.push(cellText);
            });
            text += rowData.join('\t') + '\n';
        });

        navigator.clipboard.writeText(text).then(() => {
            const btn = document.getElementById('copyTableBtn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Copied!';
            btn.classList.remove('btn-outline-info');
            btn.classList.add('btn-success');
            setTimeout(() => {
                btn.innerHTML = originalHTML;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-info');
            }, 2000);
        }).catch(err => {
            alert('Failed to copy. Please try again.');
        });
    });

    // ========== FORCE RECOMPUTE ==========
    document.getElementById('forceRecomputeBtn')?.addEventListener('click', function() {
        const computeForm = document.getElementById('computeForm');
        const startDate = computeForm.querySelector('input[name="start_date"]').value;
        const endDate = computeForm.querySelector('input[name="end_date"]').value;

        if (!startDate || !endDate) {
            alert('Please set the date range first.');
            return;
        }

        fetch(`{{ route('attendance.count-overrides') }}?start_date=${startDate}&end_date=${endDate}`, {
            headers: { 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            let msg = `Are you sure you want to FORCE RECOMPUTE?\n\nThis will recompute attendance from ${startDate} to ${endDate} using raw logs ONLY.`;
            if (data.count > 0) {
                msg += `\n\n⚠️ WARNING: ${data.count} manual edit(s) will be permanently DISCARDED.`;
            } else {
                msg += `\n\nNo manual edits found in this range.`;
            }
            msg += `\n\nClick OK to proceed.`;

            if (confirm(msg)) {
                document.getElementById('forceStartDate').value = startDate;
                document.getElementById('forceEndDate').value = endDate;
                document.getElementById('forceRecomputeForm').submit();
            }
        })
        .catch(() => {
            if (confirm('Could not check for existing edits. Force recompute anyway?')) {
                document.getElementById('forceStartDate').value = startDate;
                document.getElementById('forceEndDate').value = endDate;
                document.getElementById('forceRecomputeForm').submit();
            }
        });
    });

    // ========== ABSENT ROW CLICK -> CREATE DAY & OVERRIDE ==========
    document.querySelectorAll('.absent-time-cell').forEach(cell => {
        cell.addEventListener('click', function(e) {
            const employeeId = this.dataset.employeeId;
            const workDate = this.dataset.workDate;
            const field = this.dataset.field;

            // First, create the attendance day record, then open override modal
            document.getElementById('override-field').value = field;
            document.getElementById('override-field-display').value = field.replace(/_/g, ' ').toUpperCase();
            document.getElementById('override-new-value').value = '';
            document.getElementById('override-reason').value = '';
            document.getElementById('override-clear').checked = false;
            document.getElementById('override-error').classList.add('d-none');

            // Store absent context for the save handler
            document.getElementById('override-day-id').value = '';
            document.getElementById('override-day-id').dataset.absentEmployeeId = employeeId;
            document.getElementById('override-day-id').dataset.absentWorkDate = workDate;
            document.getElementById('override-day-id').dataset.isAbsent = 'true';

            new bootstrap.Modal(document.getElementById('overrideModal')).show();
        });
    });

    // ========== COLUMN SORTING ==========
    let currentSort = { col: null, dir: 'asc' };
    document.querySelectorAll('.sortable').forEach(th => {
        th.addEventListener('click', function() {
            const col = parseInt(this.dataset.col);
            const tbody = document.querySelector('#attendanceTable tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            if (rows.length <= 1) return;

            if (currentSort.col === col) {
                currentSort.dir = currentSort.dir === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.col = col;
                currentSort.dir = 'asc';
            }

            document.querySelectorAll('.sortable').forEach(h => {
                h.classList.remove('sort-asc', 'sort-desc');
            });
            this.classList.add(currentSort.dir === 'asc' ? 'sort-asc' : 'sort-desc');

            rows.sort((a, b) => {
                const cellA = a.cells[col]?.innerText?.trim() || '';
                const cellB = b.cells[col]?.innerText?.trim() || '';

                const numA = parseFloat(cellA);
                const numB = parseFloat(cellB);
                if (!isNaN(numA) && !isNaN(numB)) {
                    return currentSort.dir === 'asc' ? numA - numB : numB - numA;
                }

                const cmp = cellA.localeCompare(cellB);
                return currentSort.dir === 'asc' ? cmp : -cmp;
            });

            rows.forEach(row => tbody.appendChild(row));
        });
    });
});
</script>
@endpush
