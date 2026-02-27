@extends('layouts.app')

@section('title', 'Payroll Run #' . $run->id)
@section('page-title', 'Payroll Run #' . $run->id)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <span class="badge {{ $run->status === 'final' ? 'bg-success' : 'bg-secondary' }} fs-6">
            {{ strtoupper($run->status) }}
        </span>
        <span class="ms-2 text-muted">
            {{ $run->cutoff_start->format('M d') }} — {{ $run->cutoff_end->format('M d, Y') }}
        </span>
    </div>
    <div class="d-flex gap-2">
        @if(!$run->isFinal())
        <form method="POST" action="{{ route('payroll.finalize', $run) }}"
              onsubmit="return confirm('Are you sure you want to finalize this payroll run? This action cannot be undone.')">
            @csrf
            <button type="submit" class="btn btn-warning btn-sm">
                <i class="bi bi-lock"></i> Finalize
            </button>
        </form>
        @endif
        <a href="{{ route('payroll.export-csv', $run) }}" class="btn btn-outline-success btn-sm">
            <i class="bi bi-filetype-csv"></i> CSV
        </a>
        <a href="{{ route('payroll.export-pdf', $run) }}" class="btn btn-outline-secondary btn-sm" target="_blank">
            <i class="bi bi-printer"></i> Print
        </a>
        <a href="{{ route('payroll.index') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>
</div>

{{-- Filters --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-0">Search Name</label>
                <input type="text" name="search_name" class="form-control form-control-sm"
                       placeholder="Type employee name..."
                       value="{{ request('search_name') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-0">Department</label>
                <select name="department_id" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>
                            {{ $dept->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filter</button>
            </div>
            @if(request('search_name') || request('department_id'))
            <div class="col-auto">
                <a href="{{ route('payroll.show', $run) }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
            @endif
        </form>
    </div>
</div>

{{-- Payroll Summary Table --}}
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" style="font-size: 0.78rem;">
                <thead class="table-dark">
                    <tr>
                        <th>Employee</th>
                        <th>Dept</th>
                        <th class="text-end" style="font-size:0.7rem;">Daily<br>Rate</th>
                        <th class="text-center" style="font-size:0.7rem;">Req.<br>Days</th>
                        <th class="text-center" style="font-size:0.7rem;">Days<br>Worked</th>
                        <th class="text-center" style="font-size:0.7rem;">Absent</th>
                        <th class="text-center" style="font-size:0.7rem;">Late<br>Min</th>
                        <th class="text-center" style="font-size:0.7rem;">UT<br>Min</th>
                        <th class="text-end" style="background:#2c5f2d;color:#fff;">Basic Pay</th>
                        <th class="text-end text-success" style="font-size:0.7rem;">Earnings</th>
                        <th class="text-end text-danger" style="font-size:0.7rem;">Deductions</th>
                        <th class="text-end">Adj.</th>
                        <th class="text-end fw-bold" style="background:#1a3c5e;color:#fff;">NET PAY</th>
                        <th class="text-center" style="width:80px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                    <tr id="item-row-{{ $item->id }}">
                        <td class="fw-semibold">{{ $item->employee->display_name ?? '—' }}</td>
                        <td>
                            @if($item->employee->department ?? null)
                                <span class="badge bg-info text-dark" style="font-size:0.7rem;">{{ $item->employee->department->name }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">{{ number_format($item->daily_rate, 2) }}</td>
                        <td class="text-center">{{ $item->required_mandays }}</td>
                        <td class="text-center">{{ $item->days_worked }}</td>
                        <td class="text-center {{ $item->absent_days > 0 ? 'text-danger fw-bold' : '' }}">
                            {{ $item->absent_days }}
                        </td>
                        <td class="text-center {{ $item->total_late_minutes > 0 ? 'text-danger' : '' }}">
                            {{ $item->total_late_minutes }}
                        </td>
                        <td class="text-center {{ ($item->total_early_minutes ?? 0) > 0 ? 'text-danger' : '' }}">
                            {{ $item->total_early_minutes ?? 0 }}
                        </td>
                        <td class="text-end fw-semibold">{{ number_format($item->base_pay, 2) }}</td>
                        <td class="text-end {{ ($item->total_earnings ?? 0) > 0 ? 'text-success' : '' }}">
                            {{ number_format($item->total_earnings ?? 0, 2) }}
                        </td>
                        <td class="text-end {{ ($item->total_deductions ?? 0) > 0 ? 'text-danger' : '' }}">
                            {{ number_format($item->total_deductions ?? 0, 2) }}
                        </td>
                        <td class="text-end adj-cell" id="adj-{{ $item->id }}">
                            {{ number_format($item->adjustments, 2) }}
                        </td>
                        <td class="text-end fw-bold final-cell" id="final-{{ $item->id }}">
                            {{ number_format($item->final_pay, 2) }}
                        </td>
                        <td class="text-center">
                            <a href="{{ route('payroll.payslip', [$run, $item]) }}" class="btn btn-sm btn-outline-info" title="View Payslip">
                                <i class="bi bi-receipt"></i>
                            </a>
                            @if(!$run->isFinal())
                            <button class="btn btn-sm btn-outline-primary edit-adj-btn"
                                    data-item-id="{{ $item->id }}"
                                    data-adj="{{ $item->adjustments }}"
                                    data-notes="{{ $item->notes ?? '' }}"
                                    title="Edit Adjustment">
                                <i class="bi bi-pencil"></i>
                            </button>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="14" class="text-center text-muted py-4">
                            No payroll items found.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
                @if($items->isNotEmpty())
                <tfoot class="table-dark fw-bold">
                    <tr>
                        <td colspan="2">TOTALS ({{ $items->count() }} employees)</td>
                        <td></td>
                        <td class="text-center">{{ $totals['required_mandays'] }}</td>
                        <td class="text-center">{{ $totals['days_worked'] }}</td>
                        <td class="text-center">{{ $totals['absent_days'] }}</td>
                        <td class="text-center">{{ $totals['total_late_minutes'] }}</td>
                        <td class="text-center">{{ $totals['total_early_minutes'] ?? 0 }}</td>
                        <td class="text-end">{{ number_format($totals['base_pay'], 2) }}</td>
                        <td class="text-end text-success">{{ number_format($totals['total_earnings'] ?? 0, 2) }}</td>
                        <td class="text-end text-danger">{{ number_format($totals['total_deductions'] ?? 0, 2) }}</td>
                        <td class="text-end">{{ number_format($totals['adjustments'], 2) }}</td>
                        <td class="text-end">{{ number_format($totals['final_pay'], 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>

{{-- Formula Reference --}}
<div class="card border-0 shadow-sm mt-3">
    <div class="card-body small text-muted">
        <strong>Computation Reference:</strong><br>
        <strong>Basic Pay</strong> = (Daily Rate &times; Required Mandays) &minus; Absence Ded. &minus; Late Ded. &minus; Undertime Ded.<br>
        <strong>Absence Ded.</strong> = Daily Rate &times; Absent Days &nbsp;|&nbsp;
        <strong>Late Ded.</strong> = (Late Min &divide; 60 &divide; 8) &times; Daily Rate &nbsp;|&nbsp;
        <strong>Undertime Ded.</strong> = (UT Min &divide; 60 &divide; 8) &times; Daily Rate<br>
        <strong>NET PAY</strong> = Basic Pay + Earnings (Rice Allowance, etc.) &minus; Deductions (SSS, PhilHealth, Pag-ibig) + Adjustments
    </div>
</div>

{{-- Adjustment Modal --}}
@if(!$run->isFinal())
<div class="modal fade" id="adjModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Adjustments</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="adj-item-id">
                <div class="mb-3">
                    <label class="form-label">Adjustments (+ bonus / - deduction)</label>
                    <input type="number" step="0.01" class="form-control" id="adj-amount">
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" id="adj-notes" rows="2"></textarea>
                </div>
                <div id="adj-error" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="adj-save">Save</button>
            </div>
        </div>
    </div>
</div>
@endif
@endsection

@if(!$run->isFinal())
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const runId = {{ $run->id }};

    document.querySelectorAll('.edit-adj-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('adj-item-id').value = this.dataset.itemId;
            document.getElementById('adj-amount').value = this.dataset.adj;
            document.getElementById('adj-notes').value = this.dataset.notes;
            document.getElementById('adj-error').classList.add('d-none');
            new bootstrap.Modal(document.getElementById('adjModal')).show();
        });
    });

    document.getElementById('adj-save').addEventListener('click', function() {
        const itemId = document.getElementById('adj-item-id').value;
        const adjustments = document.getElementById('adj-amount').value;
        const notes = document.getElementById('adj-notes').value;

        fetch(`/payroll/${runId}/adjustment`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                payroll_item_id: itemId,
                adjustments: adjustments,
                notes: notes,
            })
        })
        .then(r => {
            if (r.status === 403) throw new Error('Payroll run is finalized.');
            return r.json();
        })
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('adjModal')).hide();
                document.getElementById('adj-' + itemId).textContent = parseFloat(adjustments).toFixed(2);
                document.getElementById('final-' + itemId).textContent = data.final_pay;
                const btn = document.querySelector(`.edit-adj-btn[data-item-id="${itemId}"]`);
                btn.dataset.adj = adjustments;
                btn.dataset.notes = notes;
            } else {
                document.getElementById('adj-error').textContent = data.message || 'Error saving.';
                document.getElementById('adj-error').classList.remove('d-none');
            }
        })
        .catch(err => {
            document.getElementById('adj-error').textContent = err.message || 'Network error.';
            document.getElementById('adj-error').classList.remove('d-none');
        });
    });
});
</script>
@endpush
@endif
