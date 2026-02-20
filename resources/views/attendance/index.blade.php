@extends('layouts.app')

@section('title', 'Attendance Viewer')
@section('page-title', 'Attendance Viewer')

@section('content')
{{-- Compute Trigger --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="POST" action="{{ route('attendance.compute') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-auto">
                <label class="form-label small">Compute From</label>
                <input type="date" name="start_date" class="form-control form-control-sm"
                       value="{{ request('start_date', $startDate) }}" required>
            </div>
            <div class="col-auto">
                <label class="form-label small">To</label>
                <input type="date" name="end_date" class="form-control form-control-sm"
                       value="{{ request('end_date', $endDate) }}" required>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-warning">
                    <i class="bi bi-calculator"></i> Compute Attendance
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Filters --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">Cutoff Rule</label>
                <select name="cutoff_rule_id" class="form-select form-select-sm">
                    <option value="">Manual Range</option>
                    @foreach($cutoffRules as $rule)
                        <option value="{{ $rule->id }}" {{ request('cutoff_rule_id') == $rule->id ? 'selected' : '' }}>
                            {{ $rule->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Cutoff Month</label>
                <input type="month" name="cutoff_month" class="form-control form-control-sm"
                       value="{{ request('cutoff_month', now()->format('Y-m')) }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Start Date</label>
                <input type="date" name="start_date" class="form-control form-control-sm"
                       value="{{ request('start_date', $startDate) }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small">End Date</label>
                <input type="date" name="end_date" class="form-control form-control-sm"
                       value="{{ request('end_date', $endDate) }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Employee</label>
                <select name="employee_id" class="form-select form-select-sm">
                    <option value="">All Employees</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}" {{ request('employee_id') == $emp->id ? 'selected' : '' }}>
                            {{ $emp->full_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small">Shift</label>
                <select name="shift_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach($shifts as $shift)
                        <option value="{{ $shift->id }}" {{ request('shift_id') == $shift->id ? 'selected' : '' }}>
                            {{ $shift->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small">Review</label>
                <select name="needs_review" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="1" {{ request('needs_review') === '1' ? 'selected' : '' }}>Yes</option>
                    <option value="0" {{ request('needs_review') === '0' ? 'selected' : '' }}>No</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

{{-- Export Buttons --}}
<div class="d-flex gap-2 mb-3 no-print">
    <a href="{{ route('attendance.export-csv', request()->query()) }}" class="btn btn-sm btn-outline-success">
        <i class="bi bi-filetype-csv"></i> Export CSV
    </a>
    <a href="{{ route('attendance.print', request()->query()) }}" class="btn btn-sm btn-outline-secondary" target="_blank">
        <i class="bi bi-printer"></i> Print View
    </a>
</div>

{{-- Attendance Table --}}
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" style="font-size: 0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Date</th>
                        <th>Shift</th>
                        <th class="text-center editable-col">Time In</th>
                        <th class="text-center editable-col">Lunch Out</th>
                        <th class="text-center editable-col">Lunch In</th>
                        <th class="text-center editable-col">Time Out</th>
                        <th class="text-center">Work</th>
                        <th class="text-center">Late</th>
                        <th class="text-center">Early</th>
                        <th class="text-center">OT</th>
                        <th class="text-center">Payable</th>
                        <th class="text-center">Review</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($days as $day)
                    <tr class="{{ $day->needs_review ? 'table-warning' : '' }}">
                        <td>{{ $day->employee->full_name ?? '—' }}</td>
                        <td>{{ $day->work_date->format('M d, Y') }}</td>
                        <td>
                            <select class="form-select form-select-sm shift-select"
                                    data-day-id="{{ $day->id }}"
                                    data-original="{{ $day->shift_id }}"
                                    style="width:140px; font-size:0.8rem;">
                                <option value="">—</option>
                                @foreach($shifts as $shift)
                                    <option value="{{ $shift->id }}" {{ $day->shift_id == $shift->id ? 'selected' : '' }}>
                                        {{ $shift->name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td class="text-center">
                            <span class="time-cell" role="button"
                                  data-day-id="{{ $day->id }}" data-field="time_in"
                                  data-value="{{ $day->time_in ? \Carbon\Carbon::parse($day->time_in)->format('H:i') : '' }}">
                                {{ $day->time_in ? \Carbon\Carbon::parse($day->time_in)->format('H:i') : '—' }}
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="time-cell" role="button"
                                  data-day-id="{{ $day->id }}" data-field="lunch_out"
                                  data-value="{{ $day->lunch_out ? \Carbon\Carbon::parse($day->lunch_out)->format('H:i') : '' }}">
                                {{ $day->lunch_out ? \Carbon\Carbon::parse($day->lunch_out)->format('H:i') : '—' }}
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="time-cell" role="button"
                                  data-day-id="{{ $day->id }}" data-field="lunch_in"
                                  data-value="{{ $day->lunch_in ? \Carbon\Carbon::parse($day->lunch_in)->format('H:i') : '' }}">
                                {{ $day->lunch_in ? \Carbon\Carbon::parse($day->lunch_in)->format('H:i') : '—' }}
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="time-cell" role="button"
                                  data-day-id="{{ $day->id }}" data-field="time_out"
                                  data-value="{{ $day->time_out ? \Carbon\Carbon::parse($day->time_out)->format('H:i') : '' }}">
                                {{ $day->time_out ? \Carbon\Carbon::parse($day->time_out)->format('H:i') : '—' }}
                            </span>
                        </td>
                        <td class="text-center">{{ $day->computed_work_minutes }}</td>
                        <td class="text-center {{ $day->computed_late_minutes > 0 ? 'text-danger fw-bold' : '' }}">
                            {{ $day->computed_late_minutes }}
                        </td>
                        <td class="text-center {{ $day->computed_early_minutes > 0 ? 'text-warning fw-bold' : '' }}">
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
                    @empty
                    <tr>
                        <td colspan="13" class="text-center text-muted py-4">
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
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    // Time cell click -> open modal
    document.querySelectorAll('.time-cell').forEach(cell => {
        cell.addEventListener('click', function() {
            const dayId = this.dataset.dayId;
            const field = this.dataset.field;
            const value = this.dataset.value;

            document.getElementById('override-day-id').value = dayId;
            document.getElementById('override-field').value = field;
            document.getElementById('override-field-display').value = field.replace('_', ' ').toUpperCase();
            document.getElementById('override-new-value').value = value;
            document.getElementById('override-reason').value = '';
            document.getElementById('override-clear').checked = false;
            document.getElementById('override-error').classList.add('d-none');

            new bootstrap.Modal(document.getElementById('overrideModal')).show();
        });
    });

    // Save override
    document.getElementById('override-save').addEventListener('click', function() {
        const dayId = document.getElementById('override-day-id').value;
        const field = document.getElementById('override-field').value;
        const clearVal = document.getElementById('override-clear').checked;
        const newValue = clearVal ? '' : document.getElementById('override-new-value').value;
        const reason = document.getElementById('override-reason').value.trim();

        if (!reason || reason.length < 3) {
            document.getElementById('override-error').textContent = 'Reason is required (min 3 characters).';
            document.getElementById('override-error').classList.remove('d-none');
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
    });

    // Shift dropdown change
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

    // Save shift change
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
});
</script>
@endpush
