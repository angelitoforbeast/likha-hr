@extends('layouts.app')

@section('title', 'Settings — Employment Statuses')
@section('page-title', 'Settings — Employment Statuses')

@section('content')
<div class="row">
    <div class="col-lg-8">
        {{-- Add New Status --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-plus-circle"></i> Add Employment Status</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('employment-statuses.store') }}">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Status Name</label>
                            <input type="text" name="name" class="form-control form-control-sm"
                                   placeholder="e.g. Regular, Probationary" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Color</label>
                            <input type="color" name="color" class="form-control form-control-sm form-control-color"
                                   value="#6c757d" style="height:31px;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold d-block">&nbsp;</label>
                            <div class="form-check">
                                <input type="checkbox" name="holiday_eligible" value="1" class="form-check-input" id="holidayEligibleNew" checked>
                                <label class="form-check-label small" for="holidayEligibleNew">Holiday Eligible</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="bi bi-plus-lg"></i> Add Status
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- Status List --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-person-badge"></i> Employment Statuses</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Color</th>
                            <th class="text-center">Holiday Eligible</th>
                            <th class="text-center">Employees</th>
                            <th>Actions</th>
                        </tr>
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
                                <small class="text-muted">{{ $status->color ?? '#6c757d' }}</small>
                            </td>
                            <td class="text-center">
                                @if($status->holiday_eligible)
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i> Yes</span>
                                @else
                                    <span class="badge bg-secondary"><i class="bi bi-x-lg"></i> No</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info">{{ $status->statusHistories()->count() }}</span>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal" data-bs-target="#editModal{{ $status->id }}">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" action="{{ route('employment-statuses.destroy', $status) }}"
                                          onsubmit="return confirm('Delete this status?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                {{ $status->statusHistories()->exists() ? 'disabled' : '' }}>
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No statuses defined. Add one above.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Info --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="fw-semibold"><i class="bi bi-info-circle"></i> How Holiday Eligibility Works</h6>
                <table class="table table-sm table-bordered mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th></th>
                            <th class="text-center">Holiday Eligible ✅</th>
                            <th class="text-center">Not Eligible ❌</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="fw-semibold">Regular Holiday<br><small class="text-muted">(not working)</small></td>
                            <td class="text-center">Not required to work<br><strong>Paid</strong> (100% daily rate)<br><small class="text-muted">Shows in Earnings as Holiday Pay</small></td>
                            <td class="text-center">Required to work<br>If absent = <strong>deducted</strong></td>
                        </tr>
                        <tr>
                            <td class="fw-semibold">Special Non-Working<br><small class="text-muted">(not working)</small></td>
                            <td class="text-center">Not required to work<br><strong>No pay, no deduction</strong></td>
                            <td class="text-center">Required to work<br>If absent = <strong>deducted</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Edit Modals --}}
@foreach($statuses as $status)
<div class="modal fade" id="editModal{{ $status->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('employment-statuses.update', $status) }}">
                @csrf @method('PUT')
                <div class="modal-header">
                    <h6 class="modal-title">Edit: {{ $status->name }}</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Status Name</label>
                        <input type="text" name="name" class="form-control form-control-sm"
                               value="{{ $status->name }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Color</label>
                        <input type="color" name="color" class="form-control form-control-sm form-control-color"
                               value="{{ $status->color ?? '#6c757d' }}" style="height:38px;">
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input type="checkbox" name="holiday_eligible" value="1"
                                   class="form-check-input" id="holidayEdit{{ $status->id }}"
                                   {{ $status->holiday_eligible ? 'checked' : '' }}>
                            <label class="form-check-label" for="holidayEdit{{ $status->id }}">
                                Holiday Eligible
                            </label>
                        </div>
                        <small class="text-muted">
                            If enabled, employees with this status are not required to work on holidays and may receive holiday pay.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach
@endsection
