@extends('layouts.app')
@section('title', 'Department: ' . $department->name)
@section('page-title', 'Department: ' . $department->name)

@section('content')
<div class="mb-3">
    <a href="{{ route('departments.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Departments
    </a>
    <a href="{{ route('departments.edit', $department) }}" class="btn btn-sm btn-outline-primary ms-1">
        <i class="bi bi-pencil"></i> Edit Department
    </a>
</div>

<div class="row">
    {{-- Left: Department Info --}}
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-diagram-3"></i> Department Info</h6></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <th style="width:120px;">Name</th>
                        <td><strong>{{ $department->name }}</strong></td>
                    </tr>
                    <tr>
                        <th>Description</th>
                        <td>{{ $department->description ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th>Employees</th>
                        <td><span class="badge bg-secondary">{{ $department->employees->count() }}</span></td>
                    </tr>
                    <tr>
                        <th>Current Shift</th>
                        <td>
                            @if($currentShift)
                                <strong>{{ $currentShift->name }}</strong><br>
                                <small class="text-muted">
                                    {{ \Carbon\Carbon::parse($currentShift->start_time)->format('g:i A') }}
                                    — {{ \Carbon\Carbon::parse($currentShift->end_time)->format('g:i A') }}
                                </small><br>
                                <small class="text-info">
                                    <i class="bi bi-cup-hot"></i>
                                    Lunch: {{ \Carbon\Carbon::parse($currentShift->lunch_start)->format('g:i A') }}
                                    — {{ \Carbon\Carbon::parse($currentShift->lunch_end)->format('g:i A') }}
                                </small>
                            @else
                                <span class="text-muted">No shift assigned</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        {{-- Add Employee --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-person-plus"></i> Add Employee</h6></div>
            <div class="card-body">
                @if($availableEmployees->count() > 0)
                <form method="POST" action="{{ route('departments.add-employee', $department) }}">
                    @csrf
                    <div class="mb-2">
                        <select name="employee_id" class="form-select form-select-sm" required>
                            <option value="">Select employee...</option>
                            @foreach($availableEmployees as $emp)
                                <option value="{{ $emp->id }}">
                                    {{ $emp->actual_name ?: $emp->full_name }}
                                    (ID: {{ $emp->zkteco_id }})
                                    @if($emp->department)
                                        — {{ $emp->department->name }}
                                    @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-success w-100">
                        <i class="bi bi-plus-lg"></i> Add to Department
                    </button>
                </form>
                @else
                <p class="text-muted small mb-0">All employees are already assigned to departments.</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Right: Shift Assignments & Employees --}}
    <div class="col-lg-8">
        {{-- Department Shift Assignments --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-clock"></i> Shift Assignments</h6>
                <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#addShiftForm">
                    <i class="bi bi-plus-lg"></i> Assign Shift
                </button>
            </div>
            {{-- Add Shift Form (collapsible) --}}
            <div class="collapse {{ $errors->any() ? 'show' : '' }}" id="addShiftForm">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('departments.assign-shift', $department) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Shift</label>
                                <select name="shift_id" class="form-select form-select-sm" required id="deptShiftSelect">
                                    <option value="">Select shift...</option>
                                    @foreach($shifts as $shift)
                                        <option value="{{ $shift->id }}"
                                                data-start="{{ \Carbon\Carbon::parse($shift->start_time)->format('g:i A') }}"
                                                data-end="{{ \Carbon\Carbon::parse($shift->end_time)->format('g:i A') }}"
                                                data-lunch-start="{{ \Carbon\Carbon::parse($shift->lunch_start)->format('g:i A') }}"
                                                data-lunch-end="{{ \Carbon\Carbon::parse($shift->lunch_end)->format('g:i A') }}"
                                                {{ old('shift_id') == $shift->id ? 'selected' : '' }}>
                                            {{ $shift->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="text-muted" id="shiftPreview"></small>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Effective Date</label>
                                <input type="date" name="effective_date"
                                       class="form-control form-control-sm @error('effective_date') is-invalid @enderror"
                                       value="{{ old('effective_date', date('Y-m-d')) }}" required>
                                @error('effective_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Remarks (optional)</label>
                                <input type="text" name="remarks" class="form-control form-control-sm"
                                       value="{{ old('remarks') }}" placeholder="e.g. Schedule change">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-success w-100">
                                    <i class="bi bi-check"></i> Save
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Effective Date</th>
                            <th>Shift</th>
                            <th>Schedule</th>
                            <th>Lunch Break</th>
                            <th>Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($department->shiftAssignments as $assignment)
                        <tr>
                            <td><span class="fw-semibold">{{ $assignment->effective_date->format('M d, Y') }}</span></td>
                            <td>{{ $assignment->shift->name }}</td>
                            <td>
                                {{ \Carbon\Carbon::parse($assignment->shift->start_time)->format('g:i A') }}
                                — {{ \Carbon\Carbon::parse($assignment->shift->end_time)->format('g:i A') }}
                            </td>
                            <td>
                                <small class="text-info">
                                    {{ \Carbon\Carbon::parse($assignment->shift->lunch_start)->format('g:i A') }}
                                    — {{ \Carbon\Carbon::parse($assignment->shift->lunch_end)->format('g:i A') }}
                                </small>
                            </td>
                            <td class="small">{{ $assignment->remarks ?? '—' }}</td>
                            <td>
                                <form method="POST"
                                      action="{{ route('departments.delete-shift-assignment', [$department, $assignment]) }}"
                                      onsubmit="return confirm('Remove this shift assignment? This will NOT remove employee shift assignments.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-3">No shift assignments yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Employees in Department --}}
        <div class="card shadow-sm">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-people"></i> Employees ({{ $department->employees->count() }})</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ZKTeco ID</th>
                            <th>ZKTeco Name</th>
                            <th>Actual Name</th>
                            <th>Schedule Mode</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($department->employees as $emp)
                        <tr>
                            <td>{{ $emp->zkteco_id }}</td>
                            <td class="text-muted small">{{ $emp->full_name }}</td>
                            <td>
                                <strong>{{ $emp->actual_name ?: '—' }}</strong>
                            </td>
                            <td>
                                @if($emp->isDepartmentMode())
                                    <span class="badge bg-info text-dark"><i class="bi bi-diagram-3"></i> Department</span>
                                @else
                                    <span class="badge bg-warning text-dark"><i class="bi bi-pencil"></i> Manual</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $emp->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                                    {{ ucfirst($emp->status) }}
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="{{ route('employees.edit', $emp) }}" class="btn btn-outline-primary" title="Edit Employee">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST"
                                          action="{{ route('departments.remove-employee', [$department, $emp]) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('Remove {{ $emp->display_name }} from this department?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger" title="Remove from Department">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-3">No employees in this department.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Shift preview when selecting
    document.getElementById('deptShiftSelect')?.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const preview = document.getElementById('shiftPreview');
        if (opt.value) {
            preview.innerHTML = opt.dataset.start + ' — ' + opt.dataset.end +
                ' | Lunch: ' + opt.dataset.lunchStart + ' — ' + opt.dataset.lunchEnd;
        } else {
            preview.innerHTML = '';
        }
    });
</script>
@endpush
