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
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ZKTeco ID</th>
                        <th>Display Name</th>
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
                        <td>
                            <strong>{{ $emp->display_name }}</strong>
                            @if($emp->actual_name && $emp->actual_name !== $emp->full_name)
                                <br><small class="text-muted">ZKTeco: {{ $emp->full_name }}</small>
                            @endif
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
                        <td colspan="9" class="text-center text-muted py-4">No employees found.</td>
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
