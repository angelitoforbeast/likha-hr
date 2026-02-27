@extends('layouts.app')

@section('title', 'Holiday Calendar')
@section('page-title', 'Holiday Calendar')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0">
            <i class="bi bi-calendar-heart"></i> Holiday Calendar
        </h5>
        <small class="text-muted">Manage regular holidays and special non-working days</small>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Add Holiday Form --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white">
        <strong><i class="bi bi-plus-circle"></i> Add Holiday</strong>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('holidays.store') }}">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small">Date</label>
                    <input type="date" name="date" class="form-control form-control-sm" required value="{{ old('date') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Holiday Name</label>
                    <input type="text" name="name" class="form-control form-control-sm" required placeholder="e.g., New Year's Day" value="{{ old('name') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Type</label>
                    <select name="type" class="form-select form-select-sm" required>
                        <option value="regular" {{ old('type') === 'regular' ? 'selected' : '' }}>Regular Holiday</option>
                        <option value="special" {{ old('type') === 'special' ? 'selected' : '' }}>Special Non-Working</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Remarks</label>
                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Optional" value="{{ old('remarks') }}">
                </div>
                <div class="col-md-1">
                    <div class="form-check mt-2">
                        <input type="checkbox" name="recurring" value="1" class="form-check-input" id="recurringNew" {{ old('recurring') ? 'checked' : '' }}>
                        <label class="form-check-label small" for="recurringNew">Yearly</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-success w-100">
                        <i class="bi bi-plus"></i> Add Holiday
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Year Filter --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex align-items-center gap-2">
            <label class="form-label small mb-0">Year:</label>
            <select name="year" class="form-select form-select-sm" style="width:120px;" onchange="this.form.submit()">
                @foreach($years as $y)
                    <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endforeach
            </select>
        </form>
    </div>
</div>

{{-- Holiday List --}}
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-list-ul"></i> Holidays for {{ $year }}</strong>
        <span class="badge bg-primary">{{ $allHolidays->count() }} holidays</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" style="font-size: 0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:130px;">Date</th>
                        <th>Day</th>
                        <th>Holiday Name</th>
                        <th>Type</th>
                        <th>Recurring</th>
                        <th>Remarks</th>
                        <th class="text-center" style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($allHolidays as $holiday)
                    <tr id="holiday-row-{{ $holiday->id }}">
                        <td class="fw-semibold">{{ $holiday->date->format('M d, Y') }}</td>
                        <td>{{ $holiday->date->format('l') }}</td>
                        <td>
                            {{ $holiday->name }}
                            @if($holiday->is_virtual ?? false)
                                <span class="badge bg-secondary" style="font-size:0.65rem;">from recurring</span>
                            @endif
                        </td>
                        <td>
                            @if($holiday->type === 'regular')
                                <span class="badge bg-danger">Regular Holiday</span>
                            @else
                                <span class="badge bg-warning text-dark">Special Non-Working</span>
                            @endif
                        </td>
                        <td>
                            @if($holiday->recurring)
                                <span class="badge bg-info text-dark"><i class="bi bi-arrow-repeat"></i> Yearly</span>
                            @else
                                <span class="text-muted">One-time</span>
                            @endif
                        </td>
                        <td class="text-muted small">{{ $holiday->remarks ?? '—' }}</td>
                        <td class="text-center">
                            @if(!($holiday->is_virtual ?? false))
                            <button class="btn btn-sm btn-outline-primary edit-holiday-btn"
                                    data-id="{{ $holiday->id }}"
                                    data-date="{{ $holiday->date->format('Y-m-d') }}"
                                    data-name="{{ $holiday->name }}"
                                    data-type="{{ $holiday->type }}"
                                    data-recurring="{{ $holiday->recurring ? '1' : '0' }}"
                                    data-remarks="{{ $holiday->remarks ?? '' }}">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('holidays.destroy', $holiday) }}" class="d-inline"
                                  onsubmit="return confirm('Delete this holiday?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @else
                                <span class="text-muted small">Edit original</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No holidays set for {{ $year }}. Add one above.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Edit Holiday Modal --}}
<div class="modal fade" id="editHolidayModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editHolidayForm">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Edit Holiday</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="date" class="form-control" id="editDate" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Holiday Name</label>
                        <input type="text" name="name" class="form-control" id="editName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" id="editType" required>
                            <option value="regular">Regular Holiday</option>
                            <option value="special">Special Non-Working Day</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <input type="text" name="remarks" class="form-control" id="editRemarks">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="recurring" value="1" class="form-check-input" id="editRecurring">
                        <label class="form-check-label" for="editRecurring">Recurring every year</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.edit-holiday-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            document.getElementById('editHolidayForm').action = `/settings/holidays/${id}`;
            document.getElementById('editDate').value = this.dataset.date;
            document.getElementById('editName').value = this.dataset.name;
            document.getElementById('editType').value = this.dataset.type;
            document.getElementById('editRemarks').value = this.dataset.remarks;
            document.getElementById('editRecurring').checked = this.dataset.recurring === '1';
            new bootstrap.Modal(document.getElementById('editHolidayModal')).show();
        });
    });
});
</script>
@endpush
