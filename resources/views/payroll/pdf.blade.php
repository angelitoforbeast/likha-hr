<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Run #{{ $run->id }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; }
        h2 { text-align: center; margin-bottom: 5px; }
        .subtitle { text-align: center; color: #666; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; }
        th { background: #f0f0f0; font-weight: bold; text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row { background: #f0f0f0; font-weight: bold; }
        @media print {
            @page { size: landscape; margin: 10mm; }
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
                <th>OT Min</th>
                <th>Base Pay</th>
                <th>Adjustments</th>
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
                <td class="text-center">{{ $item->total_late_minutes }}</td>
                <td class="text-center">{{ $item->total_overtime_minutes }}</td>
                <td class="text-right">{{ number_format($item->base_pay, 2) }}</td>
                <td class="text-right">{{ number_format($item->adjustments, 2) }}</td>
                <td class="text-right"><strong>{{ number_format($item->final_pay, 2) }}</strong></td>
                <td class="text-left">{{ $item->notes ?? '' }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td class="text-left">TOTALS</td>
                <td class="text-center" colspan="4"></td>
                <td class="text-right">{{ number_format($totals['base_pay'], 2) }}</td>
                <td class="text-right">{{ number_format($totals['adjustments'], 2) }}</td>
                <td class="text-right">{{ number_format($totals['final_pay'], 2) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
