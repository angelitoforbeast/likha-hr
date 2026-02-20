@extends('layouts.app')

@section('title', $department ? 'Edit Department' : 'Create Department')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <h2 class="mb-4">{{ $department ? 'Edit Department' : 'Create Department' }}</h2>

        <div class="card shadow-sm">
            <div class="card-body">
                <form action="{{ $department ? route('departments.update', $department) : route('departments.store') }}" method="POST">
                    @csrf
                    @if($department)
                        @method('PUT')
                    @endif

                    <div class="mb-3">
                        <label for="name" class="form-label">Department Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name"
                               value="{{ old('name', $department->name ?? '') }}"
                               placeholder="e.g., Marketing, Operations, IT"
                               required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <input type="text" class="form-control @error('description') is-invalid @enderror"
                               id="description" name="description"
                               value="{{ old('description', $department->description ?? '') }}"
                               placeholder="Optional description">
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> {{ $department ? 'Update' : 'Create' }}
                        </button>
                        <a href="{{ route('departments.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
