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
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>ZKTeco ID</th>
                    <th>Full Name</th>
                    <th>Status</th>
                    <th>Default Shift</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($employees as $emp)
                <tr>
                    <td>{{ $emp->id }}</td>
                    <td>{{ $emp->zkteco_id }}</td>
                    <td>{{ $emp->full_name }}</td>
                    <td>
                        <span class="badge {{ $emp->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                            {{ ucfirst($emp->status) }}
                        </span>
                    </td>
                    <td>{{ $emp->defaultShift->name ?? '—' }}</td>
                    <td>
                        <a href="{{ route('employees.edit', $emp) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No employees found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($employees->hasPages())
    <div class="card-footer bg-white">
        {{ $employees->withQueryString()->links() }}
    </div>
    @endif
</div>
@endsection
