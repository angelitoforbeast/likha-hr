@extends('layouts.app')

@section('title', 'Manage Employee')
@section('page-title', 'Manage: ' . $employee->full_name)

@section('content')
<div class="row">
    {{-- Left Column: Basic Info --}}
    <div class="col-lg-4 mb-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-person"></i> Basic Information</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('employees.update', $employee) }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label class="form-label fw-semibold">ZKTeco ID</label>
                        <input type="text" class="form-control" value="{{ $employee->zkteco_id }}" disabled>
                    </div>

                    <div class="mb-3">
                        <label for="full_name" class="form-label fw-semibold">Full Name</label>
                        <input type="text" name="full_name" id="full_name"
                               class="form-control @error('full_name') is-invalid @enderror"
                               value="{{ old('full_name', $employee->full_name) }}" required>
                        @error('full_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="status" class="form-label fw-semibold">Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="active" {{ $employee->status === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ $employee->status === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="default_shift_id" class="form-label fw-semibold">Fallback Shift</label>
                        <select name="default_shift_id" id="default_shift_id" class="form-select">
                            <option value="">— None —</option>
                            @foreach($shifts as $shift)
                                <option value="{{ $shift->id }}" {{ $employee->default_shift_id == $shift->id ? 'selected' : '' }}>
                                    {{ $shift->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Used only when no shift assignment exists for a date.</div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>

        <div class="mt-3">
            <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary w-100">
                <i class="bi bi-arrow-left"></i> Back to Employees
            </a>
        </div>
    </div>

    {{-- Right Column: Shift Assignments & Rates --}}
    <div class="col-lg-8">
        {{-- Shift Assignments --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-clock-history"></i> Shift Assignments</h6>
                <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addShiftForm">
                    <i class="bi bi-plus-lg"></i> Add Assignment
                </button>
            </div>

            {{-- Add Shift Form (collapsible) --}}
            <div class="collapse {{ $errors->has('shift_id') || $errors->has('effective_date') ? 'show' : '' }}" id="addShiftForm">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('employees.assign-shift', $employee) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Shift</label>
                                <select name="shift_id" class="form-select form-select-sm @error('shift_id') is-invalid @enderror" required>
                                    <option value="">Select...</option>
                                    @foreach($shifts as $shift)
                                        <option value="{{ $shift->id }}" {{ old('shift_id') == $shift->id ? 'selected' : '' }}>
                                            {{ $shift->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('shift_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Effective Date</label>
                                <input type="date" name="effective_date"
                                       class="form-control form-control-sm @error('effective_date') is-invalid @enderror"
                                       value="{{ old('effective_date', date('Y-m-d')) }}" required>
                                @error('effective_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Remarks (optional)</label>
                                <input type="text" name="remarks" class="form-control form-control-sm"
                                       value="{{ old('remarks') }}" placeholder="e.g. Transferred to night shift">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-success w-100">
                                    <i class="bi bi-check"></i> Save
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Effective Date</th>
                            <th>Shift</th>
                            <th>Schedule</th>
                            <th>Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($employee->shiftAssignments as $assignment)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $assignment->effective_date->format('M d, Y') }}</span>
                            </td>
                            <td>{{ $assignment->shift->name }}</td>
                            <td class="small text-muted">
                                {{ \Carbon\Carbon::parse($assignment->shift->start_time)->format('g:i A') }}
                                —
                                {{ \Carbon\Carbon::parse($assignment->shift->end_time)->format('g:i A') }}
                            </td>
                            <td class="small">{{ $assignment->remarks ?? '—' }}</td>
                            <td>
                                <form method="POST"
                                      action="{{ route('employees.delete-shift-assignment', [$employee, $assignment]) }}"
                                      onsubmit="return confirm('Remove this shift assignment?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">
                                No shift assignments yet. Will use fallback shift.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Daily Rates --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-currency-exchange"></i> Daily Rates</h6>
                <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addRateForm">
                    <i class="bi bi-plus-lg"></i> Add Rate
                </button>
            </div>

            {{-- Add Rate Form (collapsible) --}}
            <div class="collapse {{ $errors->has('daily_rate') ? 'show' : '' }}" id="addRateForm">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('employees.add-rate', $employee) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Daily Rate</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">&#8369;</span>
                                    <input type="number" name="daily_rate" step="0.01" min="0"
                                           class="form-control @error('daily_rate') is-invalid @enderror"
                                           value="{{ old('daily_rate') }}" placeholder="0.00" required>
                                    @error('daily_rate')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Effective Date</label>
                                <input type="date" name="effective_date"
                                       class="form-control form-control-sm @error('effective_date') is-invalid @enderror"
                                       value="{{ old('effective_date', date('Y-m-d')) }}" required>
                                @error('effective_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Remarks (optional)</label>
                                <input type="text" name="remarks" class="form-control form-control-sm"
                                       value="{{ old('remarks') }}" placeholder="e.g. Salary increase">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-success w-100">
                                    <i class="bi bi-check"></i> Save
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Effective Date</th>
                            <th>Daily Rate</th>
                            <th>Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($employee->employeeRates as $rate)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $rate->effective_date->format('M d, Y') }}</span>
                            </td>
                            <td>
                                <span class="text-success fw-semibold">&#8369; {{ number_format($rate->daily_rate, 2) }}</span>
                            </td>
                            <td class="small">{{ $rate->remarks ?? '—' }}</td>
                            <td>
                                <form method="POST"
                                      action="{{ route('employees.delete-rate', [$employee, $rate]) }}"
                                      onsubmit="return confirm('Remove this rate entry?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-3">
                                No rates set yet.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
