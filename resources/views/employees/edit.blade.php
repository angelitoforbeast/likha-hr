@extends('layouts.app')

@section('title', 'Edit Employee')
@section('page-title', 'Edit Employee: ' . $employee->full_name)

@section('content')
<div class="card border-0 shadow-sm" style="max-width:600px">
    <div class="card-body">
        <form method="POST" action="{{ route('employees.update', $employee) }}">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label class="form-label">ZKTeco ID</label>
                <input type="text" class="form-control" value="{{ $employee->zkteco_id }}" disabled>
            </div>

            <div class="mb-3">
                <label for="full_name" class="form-label">Full Name</label>
                <input type="text" class="form-control @error('full_name') is-invalid @enderror"
                       id="full_name" name="full_name" value="{{ old('full_name', $employee->full_name) }}" required>
                @error('full_name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select @error('status') is-invalid @enderror" id="status" name="status">
                    <option value="active" {{ old('status', $employee->status) === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ old('status', $employee->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
                @error('status')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="default_shift_id" class="form-label">Default Shift</label>
                <select class="form-select @error('default_shift_id') is-invalid @enderror" id="default_shift_id" name="default_shift_id">
                    <option value="">— No Shift —</option>
                    @foreach($shifts as $shift)
                        <option value="{{ $shift->id }}" {{ old('default_shift_id', $employee->default_shift_id) == $shift->id ? 'selected' : '' }}>
                            {{ $shift->name }}
                        </option>
                    @endforeach
                </select>
                @error('default_shift_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
