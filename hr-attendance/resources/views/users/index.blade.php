@extends('layouts.app')

@section('title', 'User Management')
@section('page-title', 'User Management')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-person-plus"></i> Create New User</h6>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('users.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror"
                           id="name" name="name" value="{{ old('name') }}" required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3">
                    <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control @error('email') is-invalid @enderror"
                           id="email" name="email" value="{{ old('email') }}" required>
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-2">
                    <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                    <select class="form-select @error('role') is-invalid @enderror" id="role" name="role" required>
                        @foreach($manageableRoles as $role)
                            <option value="{{ $role }}" {{ old('role') === $role ? 'selected' : '' }}>
                                {{ $roleLabels[$role] ?? ucfirst($role) }}
                            </option>
                        @endforeach
                    </select>
                    @error('role')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-2">
                    <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control @error('password') is-invalid @enderror"
                           id="password" name="password" required minlength="6">
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-2">
                    <label for="password_confirmation" class="form-label">Confirm <span class="text-danger">*</span></label>
                    <input type="password" class="form-control"
                           id="password_confirmation" name="password_confirmation" required minlength="6">
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-person-plus-fill"></i> Create User
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-people"></i> Users You Can Manage</h6>
            <span class="badge bg-secondary">{{ $users->total() }} user(s)</span>
        </div>
        <form method="GET" action="{{ route('users.index') }}" class="row g-2 mt-2">
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="Search by name or email..." value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm" name="role">
                    <option value="">All Roles</option>
                    @foreach($manageableRoles as $role)
                        <option value="{{ $role }}" {{ request('role') === $role ? 'selected' : '' }}>
                            {{ $roleLabels[$role] ?? ucfirst($role) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                    <i class="bi bi-search"></i> Filter
                </button>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Created</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr>
                            <td>{{ $user->id }}</td>
                            <td>
                                {{ $user->name }}
                                @if($user->id === $currentUser->id)
                                    <span class="badge bg-info text-dark">You</span>
                                @endif
                            </td>
                            <td>{{ $user->email }}</td>
                            <td>
                                @php
                                    $badgeClass = match($user->role) {
                                        'ceo'      => 'bg-danger',
                                        'admin'    => 'bg-warning text-dark',
                                        'hr_staff' => 'bg-primary',
                                        default    => 'bg-secondary',
                                    };
                                @endphp
                                <span class="badge {{ $badgeClass }}">{{ $user->role_label }}</span>
                            </td>
                            <td>{{ $user->created_at->format('M d, Y H:i') }}</td>
                            <td class="text-center">
                                @if($currentUser->canManage($user))
                                    <form method="POST" action="{{ route('users.destroy', $user) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('Are you sure you want to delete {{ $user->name }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i> Delete
                                        </button>
                                    </form>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No users found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($users->hasPages())
        <div class="card-footer">
            {{ $users->links() }}
        </div>
    @endif
</div>
@endsection
