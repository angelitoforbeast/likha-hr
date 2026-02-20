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
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-lock"></i> Finalize
            </button>
        </form>
        @endif
        <a href="{{ route('payroll.export-csv', $run) }}" class="btn btn-outline-success">
            <i class="bi bi-filetype-csv"></i> Export CSV
        </a>
        <a href="{{ route('payroll.export-pdf', $run) }}" class="btn btn-outline-secondary" target="_blank">
            <i class="bi bi-printer"></i> Print / PDF
        </a>
        <a href="{{ route('payroll.index') }}" class="btn btn-outline-primary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" style="font-size: 0.82rem;">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th class="text-center">Work Min</th>
                        <th class="text-center">Days</th>
                        <th class="text-center">Late Min</th>
                        <th class="text-center">Early Min</th>
                        <th class="text-center">OT Min</th>
                        <th class="text-end">Base Pay</th>
                        <th class="text-end text-danger">Late Ded.</th>
                        <th class="text-end text-danger">Early Ded.</th>
                        <th class="text-end text-success">OT Pay</th>
                        <th class="text-end">Adjustments</th>
                        <th class="text-end fw-bold">Final Pay</th>
                        <th>Notes</th>
                        @if(!$run->isFinal())
                        <th class="text-center">Edit</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                    <tr id="item-row-{{ $item->id }}">
                        <td>{{ $item->employee->full_name ?? '—' }}</td>
                        <td class="text-center">{{ number_format($item->total_work_minutes) }}</td>
                        <td class="text-center">{{ number_format($item->total_days_decimal, 2) }}</td>
                        <td class="text-center {{ $item->total_late_minutes > 0 ? 'text-danger' : '' }}">
                            {{ $item->total_late_minutes }}
                        </td>
                        <td class="text-center {{ ($item->total_early_minutes ?? 0) > 0 ? 'text-danger' : '' }}">
                            {{ $item->total_early_minutes ?? 0 }}
                        </td>
                        <td class="text-center {{ $item->total_overtime_minutes > 0 ? 'text-success' : '' }}">
                            {{ $item->total_overtime_minutes }}
                        </td>
                        <td class="text-end">{{ number_format($item->base_pay, 2) }}</td>
                        <td class="text-end {{ ($item->late_deduction ?? 0) > 0 ? 'text-danger' : '' }}">
                            {{ number_format($item->late_deduction ?? 0, 2) }}
                        </td>
                        <td class="text-end {{ ($item->early_deduction ?? 0) > 0 ? 'text-danger' : '' }}">
                            {{ number_format($item->early_deduction ?? 0, 2) }}
                        </td>
                        <td class="text-end {{ ($item->ot_pay ?? 0) > 0 ? 'text-success' : '' }}">
                            {{ number_format($item->ot_pay ?? 0, 2) }}
                        </td>
                        <td class="text-end adj-cell" id="adj-{{ $item->id }}">
                            {{ number_format($item->adjustments, 2) }}
                        </td>
                        <td class="text-end fw-bold final-cell" id="final-{{ $item->id }}">
                            {{ number_format($item->final_pay, 2) }}
                        </td>
                        <td class="small notes-cell" id="notes-{{ $item->id }}">{{ $item->notes ?? '' }}</td>
                        @if(!$run->isFinal())
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary edit-adj-btn"
                                    data-item-id="{{ $item->id }}"
                                    data-adj="{{ $item->adjustments }}"
                                    data-notes="{{ $item->notes ?? '' }}">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </td>
                        @endif
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ $run->isFinal() ? 13 : 14 }}" class="text-center text-muted py-4">
                            No payroll items found.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
                @if($items->isNotEmpty())
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td>TOTALS</td>
                        <td class="text-center">{{ number_format($totals['total_work_minutes']) }}</td>
                        <td class="text-center">{{ number_format($totals['total_days_decimal'], 2) }}</td>
                        <td class="text-center">{{ $totals['total_late_minutes'] }}</td>
                        <td class="text-center">{{ $totals['total_early_minutes'] ?? 0 }}</td>
                        <td class="text-center">{{ $totals['total_overtime_minutes'] }}</td>
                        <td class="text-end">{{ number_format($totals['base_pay'], 2) }}</td>
                        <td class="text-end text-danger">{{ number_format($totals['late_deduction'] ?? 0, 2) }}</td>
                        <td class="text-end text-danger">{{ number_format($totals['early_deduction'] ?? 0, 2) }}</td>
                        <td class="text-end text-success">{{ number_format($totals['ot_pay'] ?? 0, 2) }}</td>
                        <td class="text-end">{{ number_format($totals['adjustments'], 2) }}</td>
                        <td class="text-end">{{ number_format($totals['final_pay'], 2) }}</td>
                        <td></td>
                        @if(!$run->isFinal())
                        <td></td>
                        @endif
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
        Late Deduction = (Late Min ÷ 60 ÷ 8) × Daily Rate &nbsp;|&nbsp;
        Early Deduction = (Early Min ÷ 60 ÷ 8) × Daily Rate &nbsp;|&nbsp;
        OT Pay = (OT Min ÷ 60 ÷ 8) × Daily Rate × 1.25 &nbsp;|&nbsp;
        <strong>Final Pay = Base Pay − Late Ded. − Early Ded. + OT Pay + Adjustments</strong>
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
                document.getElementById('notes-' + itemId).textContent = notes;
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
