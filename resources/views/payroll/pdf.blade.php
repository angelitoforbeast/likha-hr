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
                <th>Dept</th>
                <th>Rate</th>
                <th>Req.</th>
                <th>Worked</th>
                <th>Absent</th>
                <th>Late</th>
                <th>UT</th>
                <th>Basic Pay</th>
                <th>Earnings</th>
                <th>Deductions</th>
                <th>Adjust.</th>
                <th>NET PAY</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td class="text-left">{{ $item->employee->display_name ?? '' }}</td>
                <td class="text-center">{{ $item->employee->department->name ?? '—' }}</td>
                <td class="text-right">{{ number_format($item->daily_rate, 2) }}</td>
                <td class="text-center">{{ $item->required_mandays }}</td>
                <td class="text-center">{{ $item->days_worked }}</td>
                <td class="text-center {{ $item->absent_days > 0 ? 'text-danger' : '' }}">{{ $item->absent_days }}</td>
                <td class="text-center">{{ $item->total_late_minutes }}</td>
                <td class="text-center">{{ $item->total_early_minutes ?? 0 }}</td>
                <td class="text-right">{{ number_format($item->base_pay, 2) }}</td>
                <td class="text-right text-success">{{ number_format($item->total_earnings ?? 0, 2) }}</td>
                <td class="text-right text-danger">{{ number_format($item->total_deductions ?? 0, 2) }}</td>
                <td class="text-right">{{ number_format($item->adjustments, 2) }}</td>
                <td class="text-right"><strong>{{ number_format($item->final_pay, 2) }}</strong></td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td class="text-left" colspan="8">TOTALS ({{ $items->count() }} employees)</td>
                <td class="text-right">{{ number_format($totals['base_pay'], 2) }}</td>
                <td class="text-right text-success">{{ number_format($totals['total_earnings'] ?? 0, 2) }}</td>
                <td class="text-right text-danger">{{ number_format($totals['total_deductions'] ?? 0, 2) }}</td>
                <td class="text-right">{{ number_format($totals['adjustments'], 2) }}</td>
                <td class="text-right">{{ number_format($totals['final_pay'], 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="formula">
        <strong>Formula:</strong>
        Basic Pay = (Rate × Req. Days) − Absences − Late − Undertime |
        NET PAY = Basic Pay + Earnings − Deductions + Adjustments
    </div>
</body>
</html>
