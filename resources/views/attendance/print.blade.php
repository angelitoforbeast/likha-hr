<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Report {{ $startDate }} to {{ $endDate }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; }
        h2 { text-align: center; margin-bottom: 5px; }
        .subtitle { text-align: center; color: #666; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: center; }
        th { background: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; }
        .review { background: #fff3cd; }
        .late { color: #dc3545; font-weight: bold; }
        @media print {
            @page { size: landscape; margin: 10mm; }
            body { margin: 0; }
        }
    </style>
</head>
<body onload="window.print()">
    <h2>Attendance Report</h2>
    <div class="subtitle">{{ $startDate }} to {{ $endDate }} | Generated: {{ now()->format('M d, Y H:i') }}</div>

    <table>
        <thead>
            <tr>
                <th class="text-left">Employee</th>
                <th>Date</th>
                <th>Shift</th>
                <th>Time In</th>
                <th>Lunch Out</th>
                <th>Lunch In</th>
                <th>Time Out</th>
                <th>Work Min</th>
                <th>Late</th>
                <th>Early</th>
                <th>OT</th>
                <th>Payable</th>
                <th>Review</th>
            </tr>
        </thead>
        <tbody>
            @foreach($days as $day)
            <tr class="{{ $day->needs_review ? 'review' : '' }}">
                <td class="text-left">{{ $day->employee->full_name ?? '' }}</td>
                <td>{{ $day->work_date->format('m/d') }}</td>
                <td>{{ $day->shift->name ?? '' }}</td>
                <td>{{ $day->time_in ? \Carbon\Carbon::parse($day->time_in)->format('H:i') : '' }}</td>
                <td>{{ $day->lunch_out ? \Carbon\Carbon::parse($day->lunch_out)->format('H:i') : '' }}</td>
                <td>{{ $day->lunch_in ? \Carbon\Carbon::parse($day->lunch_in)->format('H:i') : '' }}</td>
                <td>{{ $day->time_out ? \Carbon\Carbon::parse($day->time_out)->format('H:i') : '' }}</td>
                <td>{{ $day->computed_work_minutes }}</td>
                <td class="{{ $day->computed_late_minutes > 0 ? 'late' : '' }}">{{ $day->computed_late_minutes }}</td>
                <td>{{ $day->computed_early_minutes }}</td>
                <td>{{ $day->computed_overtime_minutes }}</td>
                <td><strong>{{ $day->payable_work_minutes }}</strong></td>
                <td>{{ $day->needs_review ? 'YES' : '' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
