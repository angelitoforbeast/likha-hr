@extends('layouts.app')

@section('title', 'Employees')
@section('page-title', 'Employees')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Employee List</h5>
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name..."
                   value="{{ request('search') }}">
            <select name="status" class="form-select form-select-sm" style="width:130px">
                <option value="">All Status</option>
                <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
            <select name="department_id" class="form-select form-select-sm" style="width:160px">
                <option value="">All Departments</option>
                @foreach($departments as $dept)
                    <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>
                        {{ $dept->name }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size: 0.9rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:80px">ZKTeco ID</th>
                        <th>ZKTeco Name</th>
                        <th>Actual Name</th>
                        <th>Department</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th>Current Shift</th>
                        <th>Lunch Break</th>
                        <th>Daily Rate</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $emp)
                    <tr>
                        <td>{{ $emp->zkteco_id }}</td>
                        <td class="text-muted">{{ $emp->full_name }}</td>
                        <td class="inline-edit-cell" data-employee-id="{{ $emp->id }}">
                            <span class="inline-display" title="Click to edit">{{ $emp->actual_name ?? '' }}</span>
                            <input type="text" class="form-control form-control-sm inline-input d-none"
                                   value="{{ $emp->actual_name ?? '' }}"
                                   placeholder="Enter actual name...">
                            <small class="inline-status d-none"></small>
                        </td>
                        <td>
                            @if($emp->department)
                                <span class="badge bg-info text-dark">{{ $emp->department->name }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($emp->isDepartmentMode())
                                <span class="badge bg-info text-dark" title="Follows department schedule"><i class="bi bi-diagram-3"></i> Dept</span>
                            @else
                                <span class="badge bg-warning text-dark" title="Custom schedule"><i class="bi bi-pencil"></i> Manual</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $emp->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                                {{ ucfirst($emp->status) }}
                            </span>
                        </td>
                        <td>
                            @if($emp->current_shift)
                                <small>
                                    {{ $emp->current_shift->name }}<br>
                                    {{ \Carbon\Carbon::parse($emp->current_shift->start_time)->format('g:i A') }}
                                    — {{ \Carbon\Carbon::parse($emp->current_shift->end_time)->format('g:i A') }}
                                </small>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($emp->current_shift && $emp->current_shift->lunch_start)
                                <small class="text-info">
                                    {{ \Carbon\Carbon::parse($emp->current_shift->lunch_start)->format('g:i A') }}
                                    — {{ \Carbon\Carbon::parse($emp->current_shift->lunch_end)->format('g:i A') }}
                                </small>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($emp->current_rate)
                                <span class="text-success fw-semibold">{{ number_format($emp->current_rate, 2) }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('employees.edit', $emp) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Manage
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">No employees found.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($employees->hasPages())
    <div class="card-footer bg-white">
        {{ $employees->withQueryString()->links() }}
    </div>
    @endif
</div>
@endsection

@push('styles')
<style>
    .inline-edit-cell {
        cursor: pointer;
        position: relative;
        min-width: 150px;
    }
    .inline-display {
        display: inline-block;
        padding: 2px 6px;
        border: 1px solid transparent;
        border-radius: 4px;
        min-height: 28px;
        min-width: 100px;
        transition: all 0.15s;
    }
    .inline-display:hover {
        border-color: #dee2e6;
        background: #f8f9fa;
    }
    .inline-display:empty::after {
        content: 'Click to set...';
        color: #adb5bd;
        font-style: italic;
    }
    .inline-input {
        width: 100%;
        font-size: 0.9rem !important;
    }
    .inline-status {
        font-size: 0.75rem;
        position: absolute;
        bottom: -16px;
        left: 6px;
    }
    .inline-status.text-success { color: #198754 !important; }
    .inline-status.text-danger { color: #dc3545 !important; }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    document.querySelectorAll('.inline-edit-cell').forEach(cell => {
        const employeeId = cell.dataset.employeeId;
        const display = cell.querySelector('.inline-display');
        const input = cell.querySelector('.inline-input');
        const status = cell.querySelector('.inline-status');
        let originalValue = input.value;

        // Click to edit
        display.addEventListener('click', function() {
            display.classList.add('d-none');
            input.classList.remove('d-none');
            input.focus();
            input.select();
        });

        // Save on Enter
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                input.blur();
            }
            if (e.key === 'Escape') {
                input.value = originalValue;
                input.blur();
            }
        });

        // Save on blur
        input.addEventListener('blur', function() {
            const newValue = input.value.trim();

            // Hide input, show display
            input.classList.add('d-none');
            display.classList.remove('d-none');

            // If no change, do nothing
            if (newValue === originalValue) {
                display.textContent = newValue;
                return;
            }

            // Show saving indicator
            display.textContent = newValue || '';
            status.textContent = 'Saving...';
            status.className = 'inline-status text-muted';
            status.classList.remove('d-none');

            // AJAX save
            fetch(`/employees/${employeeId}/inline-update`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    field: 'actual_name',
                    value: newValue || null,
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    originalValue = newValue;
                    status.textContent = '✓ Saved';
                    status.className = 'inline-status text-success';
                    setTimeout(() => status.classList.add('d-none'), 1500);
                } else {
                    status.textContent = '✗ Error';
                    status.className = 'inline-status text-danger';
                    input.value = originalValue;
                    display.textContent = originalValue;
                }
            })
            .catch(err => {
                status.textContent = '✗ Network error';
                status.className = 'inline-status text-danger';
                input.value = originalValue;
                display.textContent = originalValue;
            });
        });
    });
});
</script>
@endpush
