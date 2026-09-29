@extends('layouts.app')

@section('title', 'Attlog.dat Preview')
@section('page-title', 'Attlog.dat Preview')

@section('content')
@if(session('error'))
    <div class="alert alert-danger py-2 small">{{ session('error') }}</div>
@endif

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
            <div>
                <div class="text-muted small">Staged file</div>
                <div class="fw-semibold">{{ $stage['filename'] }}</div>
                <div class="text-muted small">Preview window: {{ \Carbon\Carbon::parse($dateFrom)->format('M d, Y') }} — {{ \Carbon\Carbon::parse($dateTo)->format('M d, Y') }} ({{ count($dates) }} days)</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <span class="badge bg-secondary">Total lines: {{ $totalLines }}</span>
                <span class="badge bg-warning text-dark">Out of range: {{ $outOfRange }}</span>
                <span class="badge bg-danger">Invalid: {{ $invalidLines }}</span>
                <span class="badge bg-primary">Known IDs: {{ count($knownRows) }}</span>
                <span class="badge bg-danger">Unknown IDs: {{ count($unknownRows) }}</span>
            </div>
        </div>

        @if(count($unknownRows) > 0)
        <div class="alert alert-danger small mt-3 mb-0">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <strong>Cannot proceed —</strong> {{ count($unknownRows) }} ZKTeco ID(s) in the file don't match any employee.
            Add or sync them first:
            <a href="{{ route('import.sync-users.form') }}" class="fw-semibold">Sync Users</a> ·
            <a href="{{ route('employees.index') }}" class="fw-semibold">Manage Employees</a>
        </div>
        @else
        <div class="alert alert-success small mt-3 mb-0">
            <i class="bi bi-check-circle-fill"></i>
            All IDs match. You can proceed with the import.
        </div>
        @endif

        <div class="d-flex justify-content-end gap-2 mt-3">
            <form method="POST" action="{{ route('import.preview.cancel') }}" onsubmit="return confirm('Discard the staged file?')">
                @csrf
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
            </form>
            <form method="POST" action="{{ route('import.preview.commit') }}" onsubmit="return confirm('Proceed with the import? Punches will be created for these employees.')">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm" @if(count($unknownRows) > 0) disabled title="Resolve unknown IDs first" @endif>
                    <i class="bi bi-cloud-upload"></i> Proceed with Import
                </button>
            </form>
        </div>
    </div>
</div>

@if(count($unknownRows) > 0)
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white text-danger">
        <h6 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Unknown ZKTeco IDs — not in employees table</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th style="width:120px">ZKTeco ID</th>
                        <th style="width:120px">Punch days</th>
                        @foreach($dates as $d)
                            <th class="text-center small p-1" style="min-width:38px;">
                                {{ \Carbon\Carbon::parse($d)->format('M j') }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($unknownRows as $row)
                    <tr class="table-danger">
                        <td class="font-monospace small">{{ $row['zkteco_id'] }}</td>
                        <td class="small">{{ $row['punch_days'] }} / {{ count($dates) }}</td>
                        @foreach($dates as $d)
                            <td class="text-center small p-1">
                                @if(isset($row['presence'][$d]))
                                    <span class="text-success">&#10003;</span>
                                @else
                                    <span class="text-muted">·</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-calendar-check"></i> Employees with punches ({{ count($knownRows) }})</h6>
        <small class="text-muted">Only employees with at least 1 punch in this window are shown.</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:140px">Department</th>
                        <th style="width:170px">Employee</th>
                        <th style="width:80px">Days</th>
                        @foreach($dates as $d)
                            <th class="text-center small p-1" style="min-width:38px;">
                                {{ \Carbon\Carbon::parse($d)->format('M j') }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($knownRows as $row)
                    <tr>
                        <td class="small">{{ $row['department'] }}</td>
                        <td class="small">
                            <a href="{{ route('employees.edit', $row['employee_id']) }}" target="_blank">{{ $row['name'] }}</a>
                            <div class="text-muted font-monospace" style="font-size:0.75em;">ID {{ $row['zkteco_id'] }}</div>
                        </td>
                        <td class="small">{{ $row['punch_days'] }} / {{ count($dates) }}</td>
                        @foreach($dates as $d)
                            <td class="text-center small p-1">
                                @if(isset($row['presence'][$d]))
                                    <span class="text-success">&#10003;</span>
                                @else
                                    <span class="text-danger">&#10007;</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    @empty
                    <tr><td colspan="{{ 3 + count($dates) }}" class="text-center text-muted py-4">No employees with punches in this window.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection