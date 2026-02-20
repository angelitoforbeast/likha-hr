@extends('layouts.app')

@section('title', 'Create Payroll Run')
@section('page-title', 'Create Payroll Run')

@section('content')
<div class="card border-0 shadow-sm" style="max-width:600px">
    <div class="card-body">
        <form method="POST" action="{{ route('payroll.store') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label">Quick Select (Cutoff Rule)</label>
                <select id="cutoff-rule-select" class="form-select">
                    <option value="">— Manual Entry —</option>
                    @foreach($cutoffRules as $rule)
                        <option value="{{ $rule->id }}" data-rule='@json($rule->rule_json)'>
                            {{ $rule->name }}
                        </option>
                    @endforeach
                </select>
                <div class="form-text">Select a cutoff rule to auto-fill dates, or enter manually below.</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Reference Month</label>
                <input type="month" id="ref-month" class="form-control" value="{{ now()->format('Y-m') }}">
            </div>

            <div class="mb-3">
                <label for="cutoff_start" class="form-label">Cutoff Start <span class="text-danger">*</span></label>
                <input type="date" class="form-control @error('cutoff_start') is-invalid @enderror"
                       id="cutoff_start" name="cutoff_start" value="{{ old('cutoff_start') }}" required>
                @error('cutoff_start')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="cutoff_end" class="form-label">Cutoff End <span class="text-danger">*</span></label>
                <input type="date" class="form-control @error('cutoff_end') is-invalid @enderror"
                       id="cutoff_end" name="cutoff_end" value="{{ old('cutoff_end') }}" required>
                @error('cutoff_end')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-calculator"></i> Create &amp; Compute
                </button>
                <a href="{{ route('payroll.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ruleSelect = document.getElementById('cutoff-rule-select');
    const refMonth = document.getElementById('ref-month');

    function applyRule() {
        const option = ruleSelect.selectedOptions[0];
        if (!option || !option.dataset.rule) return;

        const rule = JSON.parse(option.dataset.rule);
        const ranges = rule.ranges || [];
        if (ranges.length === 0) return;

        const range = ranges[0];
        const ym = refMonth.value;
        if (!ym) return;

        const [year, month] = ym.split('-').map(Number);
        const startDay = range.start_day;
        const endDay = range.end_day;
        const crossMonth = range.cross_month || false;

        const startDate = new Date(year, month - 1, startDay);
        let endDate;
        if (crossMonth) {
            endDate = new Date(year, month, endDay); // next month
        } else {
            endDate = new Date(year, month - 1, endDay);
        }

        document.getElementById('cutoff_start').value = formatDate(startDate);
        document.getElementById('cutoff_end').value = formatDate(endDate);
    }

    function formatDate(d) {
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    ruleSelect.addEventListener('change', applyRule);
    refMonth.addEventListener('change', applyRule);
});
</script>
@endpush
