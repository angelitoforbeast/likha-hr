@extends('layouts.app')

@section('title', 'Edit User - ' . $user->name)
@section('page-title', 'Edit User')

@section('content')
<div class="mb-3">
    <a href="{{ route('users.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to User Management
    </a>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0">
            <i class="bi bi-pencil-square"></i> Edit User: {{ $user->name }}
            @if($isSelf)
                <span class="badge bg-info text-dark">Your Account</span>
            @endif
        </h6>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('users.update', $user) }}">
            @csrf
            @method('PUT')

            <div class="row g-3">
                {{-- Name --}}
                <div class="col-md-4">
                    <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror"
                           id="name" name="name" value="{{ old('name', $user->name) }}" required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Email --}}
                <div class="col-md-4">
                    <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control @error('email') is-invalid @enderror"
                           id="email" name="email" value="{{ old('email', $user->email) }}" required>
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Role --}}
                <div class="col-md-4">
                    <label for="role" class="form-label">Role</label>
                    @if($isSelf)
                        <input type="text" class="form-control" value="{{ $roleLabels[$user->role] ?? ucfirst($user->role) }}" disabled>
                        <small class="text-muted">You cannot change your own role.</small>
                    @else
                        <select class="form-select @error('role') is-invalid @enderror" id="role" name="role">
                            @foreach($assignableRoles as $role)
                                <option value="{{ $role }}" {{ old('role', $user->role) === $role ? 'selected' : '' }}>
                                    {{ $roleLabels[$role] ?? ucfirst($role) }}
                                </option>
                            @endforeach
                        </select>
                        @error('role')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    @endif
                </div>
            </div>

            <hr class="my-4">

            <h6><i class="bi bi-key"></i> Change Password</h6>
            <p class="text-muted small">Leave blank if you don't want to change the password.</p>

            <div class="row g-3">
                {{-- New Password --}}
                <div class="col-md-4">
                    <label for="password" class="form-label">New Password</label>
                    <input type="password" class="form-control @error('password') is-invalid @enderror"
                           id="password" name="password" minlength="6"
                           placeholder="Min 6 characters">
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Confirm Password --}}
                <div class="col-md-4">
                    <label for="password_confirmation" class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control"
                           id="password_confirmation" name="password_confirmation" minlength="6"
                           placeholder="Re-type password">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Save Changes
                </button>
                <a href="{{ route('users.index') }}" class="btn btn-outline-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

{{-- User Info Card --}}
<div class="card">
    <div class="card-header">
        <h6 class="mb-0"><i class="bi bi-info-circle"></i> User Information</h6>
    </div>
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tr>
                <th style="width:200px;">User ID</th>
                <td>{{ $user->id }}</td>
            </tr>
            <tr>
                <th>Role</th>
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
            </tr>
            <tr>
                <th>Account Created</th>
                <td>{{ $user->created_at->format('M d, Y H:i:s') }}</td>
            </tr>
            <tr>
                <th>Last Updated</th>
                <td>{{ $user->updated_at->format('M d, Y H:i:s') }}</td>
            </tr>
        </table>
    </div>
</div>
@endsection
