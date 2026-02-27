@extends('layouts.app')

@section('title', 'Manage Employee')
@section('page-title', 'Manage: ' . $employee->display_name)

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
                        <label class="form-label fw-semibold">ZKTeco Name <small class="text-muted">(from device, read-only)</small></label>
                        <input type="text" class="form-control bg-light" value="{{ $employee->full_name }}" disabled>
                    </div>

                    <div class="mb-3">
                        <label for="actual_name" class="form-label fw-semibold">Actual Name</label>
                        <input type="text" name="actual_name" id="actual_name"
                               class="form-control @error('actual_name') is-invalid @enderror"
                               value="{{ old('actual_name', $employee->actual_name) }}"
                               placeholder="Enter actual/formal name...">
                        <div class="form-text">Primary display name. Leave blank to use ZKTeco name.</div>
                        @error('actual_name')
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
                        <label for="department_id" class="form-label fw-semibold">Department</label>
                        <select name="department_id" id="department_id" class="form-select">
                            <option value="">— None —</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->id }}" {{ $employee->department_id == $dept->id ? 'selected' : '' }}>
                                    {{ $dept->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="schedule_mode" class="form-label fw-semibold">Schedule Mode</label>
                        <select name="schedule_mode" id="schedule_mode" class="form-select">
                            <option value="department" {{ $employee->schedule_mode === 'department' ? 'selected' : '' }}>
                                Department — follows department schedule
                            </option>
                            <option value="manual" {{ $employee->schedule_mode === 'manual' ? 'selected' : '' }}>
                                Manual — custom schedule, protected
                            </option>
                        </select>
                        <div class="form-text" id="scheduleModeHelp">
                            @if($employee->schedule_mode === 'department')
                                This employee follows department schedule changes automatically.
                            @else
                                This employee has a custom schedule. Department changes will not affect them.
                            @endif
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="default_shift_id" class="form-label fw-semibold">Fallback Shift</label>
                        <select name="default_shift_id" id="default_shift_id" class="form-select">
                            <option value="">— None —</option>
                            @foreach($shifts as $shift)
                                <option value="{{ $shift->id }}"
                                        data-start="{{ \Carbon\Carbon::parse($shift->start_time)->format('g:i A') }}"
                                        data-end="{{ \Carbon\Carbon::parse($shift->end_time)->format('g:i A') }}"
                                        data-lunch-start="{{ \Carbon\Carbon::parse($shift->lunch_start)->format('g:i A') }}"
                                        data-lunch-end="{{ \Carbon\Carbon::parse($shift->lunch_end)->format('g:i A') }}"
                                        {{ $employee->default_shift_id == $shift->id ? 'selected' : '' }}>
                                    {{ $shift->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Used only when no shift assignment exists for a date.</div>
                        <small class="text-muted" id="fallbackShiftPreview">
                            @if($employee->defaultShift)
                                Schedule: {{ \Carbon\Carbon::parse($employee->defaultShift->start_time)->format('g:i A') }}
                                — {{ \Carbon\Carbon::parse($employee->defaultShift->end_time)->format('g:i A') }}
                                | Lunch: {{ \Carbon\Carbon::parse($employee->defaultShift->lunch_start)->format('g:i A') }}
                                — {{ \Carbon\Carbon::parse($employee->defaultShift->lunch_end)->format('g:i A') }}
                            @endif
                        </small>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="night_differential_eligible" id="night_diff"
                                   class="form-check-input" value="1"
                                   {{ $employee->night_differential_eligible ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="night_diff">
                                Night Differential Eligible
                            </label>
                        </div>
                        <div class="form-text">10% premium for work between 10 PM - 6 AM (per DOLE).</div>
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

    {{-- Right Column --}}
    <div class="col-lg-8">

        {{-- Employment Status History --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-person-badge"></i> Employment Status</h6>
                <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addStatusForm">
                    <i class="bi bi-plus-lg"></i> Add Status
                </button>
            </div>
            <div class="collapse" id="addStatusForm">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('employees.add-status', $employee) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Status</label>
                                <select name="employment_status_id" class="form-select form-select-sm" required>
                                    <option value="">Select...</option>
                                    @foreach($employmentStatuses as $es)
                                        <option value="{{ $es->id }}">{{ $es->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">From</label>
                                <input type="date" name="effective_from" class="form-control form-control-sm"
                                       value="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Until <small class="text-muted">(optional)</small></label>
                                <input type="date" name="effective_until" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Remarks</label>
                                <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Optional">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-check"></i> Save</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Status</th><th>From</th><th>Until</th><th>Remarks</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        @forelse($employee->statusHistory as $sh)
                        <tr>
                            <td><span class="badge" style="background-color: {{ $sh->employmentStatus->color ?? '#6c757d' }}">{{ $sh->employmentStatus->name }}</span></td>
                            <td>{{ $sh->effective_from->format('M d, Y') }}</td>
                            <td>{{ $sh->effective_until ? $sh->effective_until->format('M d, Y') : '—  Ongoing' }}</td>
                            <td class="small">{{ $sh->remarks ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('employees.delete-status', [$employee, $sh]) }}" onsubmit="return confirm('Remove?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No employment status set.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Shift Assignments --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-clock-history"></i> Shift Assignments</h6>
                <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addShiftForm">
                    <i class="bi bi-plus-lg"></i> Add Assignment
                </button>
            </div>
            <div class="collapse" id="addShiftForm">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('employees.assign-shift', $employee) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Shift</label>
                                <select name="shift_id" class="form-select form-select-sm" required>
                                    <option value="">Select...</option>
                                    @foreach($shifts as $shift)
                                        <option value="{{ $shift->id }}">{{ $shift->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">From</label>
                                <input type="date" name="effective_date" class="form-control form-control-sm"
                                       value="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Until <small class="text-muted">(opt)</small></label>
                                <input type="date" name="effective_until" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Remarks</label>
                                <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Optional">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-check"></i> Save</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>From</th><th>Until</th><th>Shift</th><th>Schedule</th><th>Lunch Break</th><th>Remarks</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        @forelse($employee->shiftAssignments as $assignment)
                        <tr>
                            <td class="fw-semibold">{{ $assignment->effective_date->format('M d, Y') }}</td>
                            <td>{{ $assignment->effective_until ? $assignment->effective_until->format('M d, Y') : '— Ongoing' }}</td>
                            <td>{{ $assignment->shift->name }}</td>
                            <td class="small">
                                {{ \Carbon\Carbon::parse($assignment->shift->start_time)->format('g:i A') }}
                                — {{ \Carbon\Carbon::parse($assignment->shift->end_time)->format('g:i A') }}
                            </td>
                            <td class="small text-info">
                                {{ \Carbon\Carbon::parse($assignment->shift->lunch_start)->format('g:i A') }}
                                — {{ \Carbon\Carbon::parse($assignment->shift->lunch_end)->format('g:i A') }}
                            </td>
                            <td class="small">{{ $assignment->remarks ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('employees.delete-shift-assignment', [$employee, $assignment]) }}" onsubmit="return confirm('Remove?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="text-center text-muted py-3">No shift assignments. Will use fallback shift.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Daily Rates --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-currency-exchange"></i> Daily Rates</h6>
                <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addRateForm">
                    <i class="bi bi-plus-lg"></i> Add Rate
                </button>
            </div>
            <div class="collapse" id="addRateForm">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('employees.add-rate', $employee) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Daily Rate</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">&#8369;</span>
                                    <input type="number" name="daily_rate" step="0.01" min="0"
                                           class="form-control" placeholder="0.00" required>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">From</label>
                                <input type="date" name="effective_date" class="form-control form-control-sm"
                                       value="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Until <small class="text-muted">(opt)</small></label>
                                <input type="date" name="effective_until" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Remarks</label>
                                <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Optional">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-check"></i> Save</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>From</th><th>Until</th><th>Daily Rate</th><th>Remarks</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        @forelse($employee->employeeRates as $rate)
                        <tr>
                            <td class="fw-semibold">{{ $rate->effective_date->format('M d, Y') }}</td>
                            <td>{{ $rate->effective_until ? $rate->effective_until->format('M d, Y') : '— Ongoing' }}</td>
                            <td><span class="text-success fw-semibold">&#8369; {{ number_format($rate->daily_rate, 2) }}</span></td>
                            <td class="small">{{ $rate->remarks ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('employees.delete-rate', [$employee, $rate]) }}" onsubmit="return confirm('Remove?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No rates set yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Benefits & Deductions --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-shield-check"></i> Benefits & Deductions</h6>
                <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addBenefitForm">
                    <i class="bi bi-plus-lg"></i> Add Benefit
                </button>
            </div>
            <div class="collapse" id="addBenefitForm">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('employees.add-benefit', $employee) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Type</label>
                                <select name="benefit_type_id" class="form-select form-select-sm" required>
                                    <option value="">Select...</option>
                                    @foreach($benefitTypes as $bt)
                                        <option value="{{ $bt->id }}">{{ $bt->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Amount</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">&#8369;</span>
                                    <input type="number" name="amount" step="0.01" min="0"
                                           class="form-control" placeholder="0.00" required>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">From</label>
                                <input type="date" name="effective_from" class="form-control form-control-sm"
                                       value="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Until <small class="text-muted">(opt)</small></label>
                                <input type="date" name="effective_until" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Remarks</label>
                                <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Optional">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-check"></i> Save</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Type</th><th>Amount</th><th>From</th><th>Until</th><th>Remarks</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        @forelse($employee->benefits as $ben)
                        <tr>
                            <td>
                                <span class="badge bg-{{ $ben->benefitType->category === 'deduction' ? 'danger' : ($ben->benefitType->category === 'allowance' ? 'success' : 'info') }}">
                                    {{ $ben->benefitType->name }}
                                </span>
                            </td>
                            <td class="fw-semibold">&#8369; {{ number_format($ben->amount, 2) }}</td>
                            <td>{{ $ben->effective_from->format('M d, Y') }}</td>
                            <td>{{ $ben->effective_until ? $ben->effective_until->format('M d, Y') : '— Ongoing' }}</td>
                            <td class="small">{{ $ben->remarks ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('employees.delete-benefit', [$employee, $ben]) }}" onsubmit="return confirm('Remove?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No benefits/deductions set.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Rest Day Patterns --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-calendar-week"></i> Rest Day Pattern</h6>
                <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addRestDayForm">
                    <i class="bi bi-plus-lg"></i> Add Pattern
                </button>
            </div>
            <div class="collapse" id="addRestDayForm">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('employees.add-rest-day', $employee) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Day</label>
                                <select name="day_of_week" class="form-select form-select-sm" required>
                                    <option value="">Select...</option>
                                    @foreach(\App\Models\RestDayPattern::$dayNames as $num => $name)
                                        <option value="{{ $num }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">From</label>
                                <input type="date" name="effective_from" class="form-control form-control-sm"
                                       value="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Until <small class="text-muted">(opt)</small></label>
                                <input type="date" name="effective_until" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Remarks</label>
                                <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Optional">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-check"></i> Save</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Day</th><th>From</th><th>Until</th><th>Remarks</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        @forelse($employee->restDayPatterns as $rdp)
                        <tr>
                            <td><span class="badge bg-secondary">{{ $rdp->day_name }}</span></td>
                            <td>{{ $rdp->effective_from->format('M d, Y') }}</td>
                            <td>{{ $rdp->effective_until ? $rdp->effective_until->format('M d, Y') : '— Ongoing' }}</td>
                            <td class="small">{{ $rdp->remarks ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('employees.delete-rest-day', [$employee, $rdp]) }}" onsubmit="return confirm('Remove?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No rest day pattern set.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Day Off Overrides --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-calendar-x"></i> Day Off Overrides</h6>
                <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addDayOffForm">
                    <i class="bi bi-plus-lg"></i> Add Override
                </button>
            </div>
            <div class="collapse" id="addDayOffForm">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('employees.add-day-off', $employee) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Date</label>
                                <input type="date" name="off_date" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Type</label>
                                <select name="type" class="form-select form-select-sm" required>
                                    <option value="day_off">Extra Day Off</option>
                                    <option value="cancel_day_off">Cancel Day Off (must work)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Remarks</label>
                                <input type="text" name="remarks" class="form-control form-control-sm" placeholder="e.g. Change RD">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-check"></i> Save</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Date</th><th>Type</th><th>Remarks</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        @forelse($employee->dayOffs->sortByDesc('off_date') as $doff)
                        <tr>
                            <td class="fw-semibold">{{ $doff->off_date->format('M d, Y (l)') }}</td>
                            <td>
                                @if($doff->type === 'day_off')
                                    <span class="badge bg-info">Extra Day Off</span>
                                @else
                                    <span class="badge bg-warning text-dark">Cancel Day Off</span>
                                @endif
                            </td>
                            <td class="small">{{ $doff->remarks ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('employees.delete-day-off', [$employee, $doff]) }}" onsubmit="return confirm('Remove?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No day off overrides.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Cash Advances --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-cash"></i> Cash Advances</h6>
                <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addCashAdvanceForm">
                    <i class="bi bi-plus-lg"></i> Add Cash Advance
                </button>
            </div>
            <div class="collapse" id="addCashAdvanceForm">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('employees.add-cash-advance', $employee) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Amount</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">&#8369;</span>
                                    <input type="number" name="amount" step="0.01" min="1"
                                           class="form-control" placeholder="0.00" required>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Deduction/Cutoff</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">&#8369;</span>
                                    <input type="number" name="deduction_per_cutoff" step="0.01" min="0"
                                           class="form-control" placeholder="0.00" required>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Date Granted</label>
                                <input type="date" name="date_granted" class="form-control form-control-sm"
                                       value="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Deduction From</label>
                                <input type="date" name="effective_from" class="form-control form-control-sm"
                                       value="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Until <small>(opt)</small></label>
                                <input type="date" name="effective_until" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-check"></i> Save</button>
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-12">
                                <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Remarks (optional)">
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Date Granted</th><th>Amount</th><th>Deduction/Cutoff</th><th>Balance</th><th>Status</th><th>Remarks</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        @forelse($employee->cashAdvances as $ca)
                        <tr>
                            <td>{{ $ca->date_granted->format('M d, Y') }}</td>
                            <td class="fw-semibold">&#8369; {{ number_format($ca->amount, 2) }}</td>
                            <td>&#8369; {{ number_format($ca->deduction_per_cutoff, 2) }}</td>
                            <td>
                                <span class="text-{{ $ca->remaining_balance > 0 ? 'danger' : 'success' }} fw-semibold">
                                    &#8369; {{ number_format($ca->remaining_balance, 2) }}
                                </span>
                            </td>
                            <td><span class="badge bg-{{ $ca->status === 'active' ? 'warning' : 'success' }}">{{ ucfirst($ca->status) }}</span></td>
                            <td class="small">{{ $ca->remarks ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('employees.delete-cash-advance', [$employee, $ca]) }}" onsubmit="return confirm('Remove?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="text-center text-muted py-3">No cash advances.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
    // Fallback shift preview
    const fallbackSelect = document.getElementById('default_shift_id');
    const fallbackPreview = document.getElementById('fallbackShiftPreview');
    if (fallbackSelect) {
        fallbackSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (opt.value && opt.dataset.start) {
                fallbackPreview.innerHTML = 'Schedule: ' + opt.dataset.start + ' — ' + opt.dataset.end +
                    ' | Lunch: ' + opt.dataset.lunchStart + ' — ' + opt.dataset.lunchEnd;
            } else {
                fallbackPreview.innerHTML = '';
            }
        });
    }

    // Schedule mode help text
    const modeSelect = document.getElementById('schedule_mode');
    const modeHelp = document.getElementById('scheduleModeHelp');
    const deptSelect = document.getElementById('department_id');
    if (modeSelect) {
        modeSelect.addEventListener('change', function() {
            if (this.value === 'department') {
                modeHelp.textContent = 'This employee follows department schedule changes automatically.';
            } else {
                modeHelp.textContent = 'This employee has a custom schedule. Department changes will not affect them.';
            }
        });
    }

    if (deptSelect) {
        deptSelect.addEventListener('change', function() {
            if (!this.value) {
                modeSelect.value = 'manual';
                modeSelect.dispatchEvent(new Event('change'));
            }
        });
    }
</script>
@endpush
