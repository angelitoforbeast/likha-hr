@extends('layouts.app')

@section('title', 'Departments')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">Departments</h2>
    <a href="{{ route('departments.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Add Department
    </a>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th class="text-center">Employees</th>
                    <th class="text-center" style="width: 160px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($departments as $dept)
                <tr>
                    <td>{{ $dept->id }}</td>
                    <td><strong>{{ $dept->name }}</strong></td>
                    <td class="text-muted">{{ $dept->description ?? '—' }}</td>
                    <td class="text-center">
                        <span class="badge bg-secondary">{{ $dept->employees_count }}</span>
                    </td>
                    <td class="text-center">
                        <a href="{{ route('departments.edit', $dept) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        @if($dept->employees_count === 0)
                        <form action="{{ route('departments.destroy', $dept) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this department?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </form>
                        @else
                        <button class="btn btn-sm btn-outline-danger" disabled title="Has assigned employees">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">No departments yet. Click "Add Department" to create one.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($departments->hasPages())
<div class="mt-3 d-flex justify-content-center">
    {{ $departments->links() }}
</div>
@endif
@endsection
