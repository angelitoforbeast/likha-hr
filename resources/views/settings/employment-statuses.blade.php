@extends('layouts.app')

@section('title', 'Settings — Employment Statuses')
@section('page-title', 'Settings — Employment Statuses')

@section('content')
<div class="row">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-person-badge"></i> Employment Statuses</h6>
            </div>
            <div class="card-body border-bottom bg-light">
                <form method="POST" action="{{ route('employment-statuses.store') }}">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Status Name</label>
                            <input type="text" name="name" class="form-control form-control-sm"
                                   placeholder="e.g. Regular, Probationary" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Color</label>
                            <input type="color" name="color" class="form-control form-control-sm form-control-color"
                                   value="#6c757d" style="height:31px;">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="bi bi-plus-lg"></i> Add Status
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>#</th><th>Name</th><th>Color</th><th>Employees</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        @forelse($statuses as $status)
                        <tr>
                            <td>{{ $status->sort_order }}</td>
                            <td>
                                <span class="badge" style="background-color: {{ $status->color ?? '#6c757d' }}">
                                    {{ $status->name }}
                                </span>
                            </td>
                            <td>
                                <span class="d-inline-block rounded" style="width:20px;height:20px;background:{{ $status->color ?? '#6c757d' }}"></span>
                                <small class="text-muted">{{ $status->color }}</small>
                            </td>
                            <td>{{ $status->statusHistories()->count() }}</td>
                            <td>
                                <form method="POST" action="{{ route('employment-statuses.destroy', $status) }}"
                                      onsubmit="return confirm('Delete this status?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            {{ $status->statusHistories()->exists() ? 'disabled' : '' }}>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No statuses defined. Add one above.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="text-muted small">
            <i class="bi bi-info-circle"></i>
            Employment statuses are customizable. Add statuses like <strong>Training</strong>, <strong>Hybrid</strong>,
            <strong>Probationary</strong>, <strong>Regular</strong>, etc. Assign them to employees from the Employee edit page.
        </div>
    </div>
</div>
@endsection
