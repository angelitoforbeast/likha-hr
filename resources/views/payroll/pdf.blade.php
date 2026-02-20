<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Run #{{ $run->id }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; margin: 20px; }
        h2 { text-align: center; margin-bottom: 5px; }
        .subtitle { text-align: center; color: #666; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 3px 5px; }
        th { background: #f0f0f0; font-weight: bold; text-align: center; font-size: 9px; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-danger { color: #dc3545; }
        .text-success { color: #198754; }
        .total-row { background: #f0f0f0; font-weight: bold; }
        .formula { font-size: 9px; color: #666; margin-top: 10px; }
        @media print {
            @page { size: landscape; margin: 8mm; }
            body { margin: 0; }
        }
    </style>
</head>
<body onload="window.print()">
    <h2>Payroll Summary</h2>
    <div class="subtitle">
        Run #{{ $run->id }} |
        {{ $run->cutoff_start->format('M d') }} — {{ $run->cutoff_end->format('M d, Y') }} |
        Status: {{ strtoupper($run->status) }} |
        Generated: {{ now()->format('M d, Y H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-left">Employee</th>
                <th>Work Min</th>
                <th>Days</th>
                <th>Late Min</th>
                <th>Early Min</th>
                <th>OT Min</th>
                <th>Base Pay</th>
                <th>Late Ded.</th>
                <th>Early Ded.</th>
                <th>OT Pay</th>
                <th>Adjust.</th>
                <th>Final Pay</th>
                <th class="text-left">Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td class="text-left">{{ $item->employee->full_name ?? '' }}</td>
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
                <td class="text-right">{{ number_format($item->base_pay, 2) }}</td>
                <td class="text-right text-danger">{{ number_format($item->late_deduction ?? 0, 2) }}</td>
                <td class="text-right text-danger">{{ number_format($item->early_deduction ?? 0, 2) }}</td>
                <td class="text-right text-success">{{ number_format($item->ot_pay ?? 0, 2) }}</td>
                <td class="text-right">{{ number_format($item->adjustments, 2) }}</td>
                <td class="text-right"><strong>{{ number_format($item->final_pay, 2) }}</strong></td>
                <td class="text-left">{{ $item->notes ?? '' }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td class="text-left">TOTALS</td>
                <td class="text-center" colspan="5"></td>
                <td class="text-right">{{ number_format($totals['base_pay'], 2) }}</td>
                <td class="text-right text-danger">{{ number_format($totals['late_deduction'] ?? 0, 2) }}</td>
                <td class="text-right text-danger">{{ number_format($totals['early_deduction'] ?? 0, 2) }}</td>
                <td class="text-right text-success">{{ number_format($totals['ot_pay'] ?? 0, 2) }}</td>
                <td class="text-right">{{ number_format($totals['adjustments'], 2) }}</td>
                <td class="text-right">{{ number_format($totals['final_pay'], 2) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div class="formula">
        <strong>Formula:</strong>
        Late/Early Deduction = (Minutes ÷ 60 ÷ 8) × Daily Rate |
        OT Pay = (OT Min ÷ 60 ÷ 8) × Daily Rate × 1.25 |
        Final Pay = Base Pay − Late Ded. − Early Ded. + OT Pay + Adjustments
    </div>
</body>
</html>
