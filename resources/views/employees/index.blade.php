@extends('layouts.app')

@section('title', 'Employees')
@section('page-title', 'Employees')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">Employee List</h5>
        @php
            $hasAnyFilter = request()->filled('search') || request()->has('status') || request()->filled('department_id') || request()->has('has_department');
            $defaultStatus = $hasAnyFilter ? request('status', '') : 'active';
            $defaultHasDept = $hasAnyFilter ? request('has_department', '') : '1';
        @endphp
        <form method="GET" class="d-flex gap-2 flex-wrap">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name..."
                   value="{{ request('search') }}" style="width:160px">
            <select name="status" class="form-select form-select-sm" style="width:130px">
                <option value="">All Status</option>
                <option value="active" {{ $defaultStatus == 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ $defaultStatus == 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
            <select name="has_department" class="form-select form-select-sm" style="width:160px">
                <option value="">All (Dept/No Dept)</option>
                <option value="1" {{ $defaultHasDept == '1' ? 'selected' : '' }}>With Department</option>
                <option value="0" {{ $defaultHasDept == '0' ? 'selected' : '' }}>No Department</option>
            </select>
            <select name="department_id" class="form-select form-select-sm" style="width:160px">
                <option value="">All Departments</option>
                @foreach($departments as $dept)
                    <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>
                        {{ $dept->name }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            @if($hasAnyFilter)
            <a href="{{ route('employees.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            @endif
        </form>
    </div>

    {{-- Bulk Action Toolbar --}}
    <div id="bulkToolbar" class="card-header bg-primary text-white d-none align-items-center justify-content-between py-2">
        <div>
            <strong><span id="selectedCount">0</span> selected</strong>
        </div>
        <div class="d-flex gap-1 flex-wrap">
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#bulkAssignShiftModal">
                <i class="bi bi-clock"></i> Assign Shift
            </button>
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#bulkChangeDeptModal">
                <i class="bi bi-diagram-3"></i> Change Dept
            </button>
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#bulkChangeStatusModal">
                <i class="bi bi-person-badge"></i> Set Status
            </button>
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#bulkRestDayModal">
                <i class="bi bi-calendar-x"></i> Set Rest Day
            </button>
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#bulkBenefitModal">
                <i class="bi bi-gift"></i> Add Benefit
            </button>
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#bulkDailyRateModal">
                <i class="bi bi-cash"></i> Set Rate
            </button>
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#bulkScheduleModeModal">
                <i class="bi bi-toggles"></i> Set Mode
            </button>
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#bulkNightDiffModal">
                <i class="bi bi-moon"></i> Night Diff
            </button>
            <button type="button" class="btn btn-sm btn-success" onclick="submitBulk('activate')">
                <i class="bi bi-check-circle"></i> Activate
            </button>
            <button type="button" class="btn btn-sm btn-danger" onclick="submitBulk('deactivate')">
                <i class="bi bi-x-circle"></i> Deactivate
            </button>
        </div>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size: 0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:40px">
                            <input type="checkbox" id="selectAll" class="form-check-input">
                        </th>
                        <th style="width:70px">ZKTeco ID</th>
                        <th>ZKTeco Name</th>
                        <th>Actual Name</th>
                        <th>Department</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th>Current Shift</th>
                        <th>Lunch Break</th>
                        <th>Daily Rate</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $emp)
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input emp-checkbox" value="{{ $emp->id }}">
                        </td>
                        <td>{{ $emp->zkteco_id }}</td>
                        <td class="text-muted">{{ $emp->full_name }}</td>
                        <td class="inline-edit-cell" data-employee-id="{{ $emp->id }}">
                            <span class="inline-display" title="Click to edit">{{ $emp->actual_name ?? '' }}</span>
                            <input type="text" class="form-control form-control-sm inline-input d-none"
                                   value="{{ $emp->actual_name ?? '' }}"
                                   placeholder="Enter actual name...">
                            <small class="inline-status d-none"></small>
                        </td>
                        <td>
                            @if($emp->department)
                                <span class="badge bg-info text-dark">{{ $emp->department->name }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($emp->isDepartmentMode())
                                <span class="badge bg-info text-dark" title="Follows department schedule"><i class="bi bi-diagram-3"></i> Dept</span>
                            @else
                                <span class="badge bg-warning text-dark" title="Custom schedule"><i class="bi bi-pencil"></i> Manual</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $emp->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                                {{ ucfirst($emp->status) }}
                            </span>
                        </td>
                        <td>
                            @if($emp->current_shift)
                                <small>
                                    {{ $emp->current_shift->name }}<br>
                                    {{ \Carbon\Carbon::parse($emp->current_shift->start_time)->format('g:i A') }}
                                    — {{ \Carbon\Carbon::parse($emp->current_shift->end_time)->format('g:i A') }}
                                </small>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($emp->current_shift && $emp->current_shift->lunch_start)
                                <small class="text-info">
                                    {{ \Carbon\Carbon::parse($emp->current_shift->lunch_start)->format('g:i A') }}
                                    — {{ \Carbon\Carbon::parse($emp->current_shift->lunch_end)->format('g:i A') }}
                                </small>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($emp->current_rate)
                                <span class="text-success fw-semibold">{{ number_format($emp->current_rate, 2) }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('employees.edit', $emp) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Manage
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">No employees found.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($employees->hasPages())
    <div class="card-footer bg-white">
        {{ $employees->withQueryString()->links() }}
    </div>
    @endif
</div>

{{-- Hidden form for simple bulk actions --}}
<form id="bulkForm" method="POST" action="{{ route('employees.bulk-action') }}" class="d-none">
    @csrf
    <input type="hidden" name="action" id="bulkFormAction">
    <div id="bulkFormIds"></div>
</form>

{{-- ========== BULK MODALS ========== --}}

{{-- Assign Shift Modal --}}
<div class="modal fade" id="bulkAssignShiftModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('employees.bulk-action') }}">
            @csrf
            <input type="hidden" name="action" value="assign_shift">
            <div class="bulk-ids-container"></div>
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Bulk Assign Shift</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Assigning shift to <strong class="modal-selected-count">0</strong> employees</p>
                    <div class="mb-3">
                        <label class="form-label">Shift</label>
                        <select name="shift_id" class="form-select" required>
                            <option value="">Select shift...</option>
                            @foreach($shifts as $shift)
                                <option value="{{ $shift->id }}">{{ $shift->name }} ({{ \Carbon\Carbon::parse($shift->start_time)->format('H:i') }}-{{ \Carbon\Carbon::parse($shift->end_time)->format('H:i') }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective Date</label>
                        <input type="date" name="effective_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective Until <small class="text-muted">(optional)</small></label>
                        <input type="date" name="effective_until" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Change Department Modal --}}
<div class="modal fade" id="bulkChangeDeptModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('employees.bulk-action') }}">
            @csrf
            <input type="hidden" name="action" value="change_department">
            <div class="bulk-ids-container"></div>
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Bulk Change Department</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Moving <strong class="modal-selected-count">0</strong> employees</p>
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-select" required>
                            <option value="">Select department...</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Change Employment Status Modal --}}
<div class="modal fade" id="bulkChangeStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('employees.bulk-action') }}">
            @csrf
            <input type="hidden" name="action" value="change_status">
            <div class="bulk-ids-container"></div>
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Bulk Set Employment Status</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Setting status for <strong class="modal-selected-count">0</strong> employees</p>
                    <div class="mb-3">
                        <label class="form-label">Employment Status</label>
                        <select name="employment_status_id" class="form-select" required>
                            <option value="">Select status...</option>
                            @foreach(\App\Models\EmploymentStatus::orderBy('sort_order')->get() as $es)
                                <option value="{{ $es->id }}">{{ $es->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective From</label>
                        <input type="date" name="effective_from" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective Until <small class="text-muted">(optional)</small></label>
                        <input type="date" name="effective_until" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Set Rest Day Modal --}}
<div class="modal fade" id="bulkRestDayModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('employees.bulk-action') }}">
            @csrf
            <input type="hidden" name="action" value="set_rest_day">
            <div class="bulk-ids-container"></div>
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Bulk Set Rest Day</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Setting rest day for <strong class="modal-selected-count">0</strong> employees</p>
                    <div class="mb-3">
                        <label class="form-label">Day of Week</label>
                        <select name="day_of_week" class="form-select" required>
                            <option value="0">Sunday</option>
                            <option value="1">Monday</option>
                            <option value="2">Tuesday</option>
                            <option value="3">Wednesday</option>
                            <option value="4">Thursday</option>
                            <option value="5">Friday</option>
                            <option value="6">Saturday</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective From</label>
                        <input type="date" name="effective_from" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective Until <small class="text-muted">(optional)</small></label>
                        <input type="date" name="effective_until" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Add Benefit Modal --}}
<div class="modal fade" id="bulkBenefitModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('employees.bulk-action') }}">
            @csrf
            <input type="hidden" name="action" value="add_benefit">
            <div class="bulk-ids-container"></div>
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Bulk Add Benefit</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Adding benefit to <strong class="modal-selected-count">0</strong> employees</p>
                    <div class="mb-3">
                        <label class="form-label">Benefit Type</label>
                        <select name="benefit_type_id" class="form-select" required>
                            <option value="">Select type...</option>
                            @foreach(\App\Models\BenefitType::active()->orderBy('sort_order')->get() as $bt)
                                <option value="{{ $bt->id }}">{{ $bt->name }} ({{ $bt->category }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective From</label>
                        <input type="date" name="effective_from" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective Until <small class="text-muted">(optional)</small></label>
                        <input type="date" name="effective_until" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Set Daily Rate Modal --}}
<div class="modal fade" id="bulkDailyRateModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('employees.bulk-action') }}">
            @csrf
            <input type="hidden" name="action" value="set_daily_rate">
            <div class="bulk-ids-container"></div>
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Bulk Set Daily Rate</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Setting rate for <strong class="modal-selected-count">0</strong> employees</p>
                    <div class="mb-3">
                        <label class="form-label">Daily Rate (₱)</label>
                        <input type="number" name="daily_rate" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective Date</label>
                        <input type="date" name="effective_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective Until <small class="text-muted">(optional)</small></label>
                        <input type="date" name="effective_until" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Set Schedule Mode Modal --}}
<div class="modal fade" id="bulkScheduleModeModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('employees.bulk-action') }}">
            @csrf
            <input type="hidden" name="action" value="set_schedule_mode">
            <div class="bulk-ids-container"></div>
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Bulk Set Schedule Mode</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Setting mode for <strong class="modal-selected-count">0</strong> employees</p>
                    <div class="mb-3">
                        <label class="form-label">Schedule Mode</label>
                        <select name="schedule_mode" class="form-select" required>
                            <option value="department">Department (follows department schedule)</option>
                            <option value="manual">Manual (custom schedule)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Night Differential Modal --}}
<div class="modal fade" id="bulkNightDiffModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('employees.bulk-action') }}">
            @csrf
            <input type="hidden" name="action" value="set_night_diff">
            <div class="bulk-ids-container"></div>
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Bulk Set Night Differential</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Setting night diff for <strong class="modal-selected-count">0</strong> employees</p>
                    <div class="mb-3">
                        <label class="form-label">Night Differential Eligibility</label>
                        <select name="night_differential_eligible" class="form-select" required>
                            <option value="1">Eligible</option>
                            <option value="0">Not Eligible</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

@endsection

@push('styles')
<style>
    .inline-edit-cell {
        cursor: pointer;
        position: relative;
        min-width: 150px;
    }
    .inline-display {
        display: inline-block;
        padding: 2px 6px;
        border: 1px solid transparent;
        border-radius: 4px;
        min-height: 28px;
        min-width: 100px;
        transition: all 0.15s;
    }
    .inline-display:hover {
        border-color: #dee2e6;
        background: #f8f9fa;
    }
    .inline-display:empty::after {
        content: 'Click to set...';
        color: #adb5bd;
        font-style: italic;
    }
    .inline-input {
        width: 100%;
        font-size: 0.85rem !important;
    }
    .inline-status {
        font-size: 0.75rem;
        position: absolute;
        bottom: -16px;
        left: 6px;
    }
    .inline-status.text-success { color: #198754 !important; }
    .inline-status.text-danger { color: #dc3545 !important; }
    #bulkToolbar {
        transition: all 0.2s ease;
    }
    #bulkToolbar .btn {
        font-size: 0.78rem;
        padding: 2px 8px;
    }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.emp-checkbox');
    const toolbar = document.getElementById('bulkToolbar');
    const countEl = document.getElementById('selectedCount');

    // ── Select All / Deselect All ──
    selectAll.addEventListener('change', function() {
        checkboxes.forEach(cb => cb.checked = this.checked);
        updateToolbar();
    });

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateToolbar);
    });

    function getSelectedIds() {
        return Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
    }

    function updateToolbar() {
        const ids = getSelectedIds();
        const count = ids.length;
        countEl.textContent = count;

        if (count > 0) {
            toolbar.classList.remove('d-none');
            toolbar.classList.add('d-flex');
        } else {
            toolbar.classList.add('d-none');
            toolbar.classList.remove('d-flex');
        }

        // Update all modal selected counts
        document.querySelectorAll('.modal-selected-count').forEach(el => el.textContent = count);

        // Update all modal hidden ID inputs
        document.querySelectorAll('.bulk-ids-container').forEach(container => {
            container.innerHTML = '';
            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'employee_ids[]';
                input.value = id;
                container.appendChild(input);
            });
        });

        // Update selectAll state
        selectAll.checked = count === checkboxes.length && count > 0;
        selectAll.indeterminate = count > 0 && count < checkboxes.length;
    }

    // ── Simple bulk actions (activate/deactivate) ──
    window.submitBulk = function(action) {
        const ids = getSelectedIds();
        if (ids.length === 0) return;

        const label = action === 'activate' ? 'activate' : 'deactivate';
        if (!confirm(`Are you sure you want to ${label} ${ids.length} employee(s)?`)) return;

        const form = document.getElementById('bulkForm');
        document.getElementById('bulkFormAction').value = action;
        const idsContainer = document.getElementById('bulkFormIds');
        idsContainer.innerHTML = '';
        ids.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'employee_ids[]';
            input.value = id;
            idsContainer.appendChild(input);
        });
        form.submit();
    };

    // ── Inline Edit (Actual Name) ──
    document.querySelectorAll('.inline-edit-cell').forEach(cell => {
        const employeeId = cell.dataset.employeeId;
        const display = cell.querySelector('.inline-display');
        const input = cell.querySelector('.inline-input');
        const status = cell.querySelector('.inline-status');
        let originalValue = input.value;

        display.addEventListener('click', function() {
            display.classList.add('d-none');
            input.classList.remove('d-none');
            input.focus();
            input.select();
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            if (e.key === 'Escape') { input.value = originalValue; input.blur(); }
        });

        input.addEventListener('blur', function() {
            const newValue = input.value.trim();
            input.classList.add('d-none');
            display.classList.remove('d-none');

            if (newValue === originalValue) { display.textContent = newValue; return; }

            display.textContent = newValue || '';
            status.textContent = 'Saving...';
            status.className = 'inline-status text-muted';
            status.classList.remove('d-none');

            fetch(`/employees/${employeeId}/inline-update`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify({ field: 'actual_name', value: newValue || null })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    originalValue = newValue;
                    status.textContent = '✓ Saved';
                    status.className = 'inline-status text-success';
                    setTimeout(() => status.classList.add('d-none'), 1500);
                } else {
                    status.textContent = '✗ Error';
                    status.className = 'inline-status text-danger';
                    input.value = originalValue;
                    display.textContent = originalValue;
                }
            })
            .catch(() => {
                status.textContent = '✗ Network error';
                status.className = 'inline-status text-danger';
                input.value = originalValue;
                display.textContent = originalValue;
            });
        });
    });
});
</script>
@endpush
