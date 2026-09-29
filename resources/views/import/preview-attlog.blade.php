@extends('layouts.app')

@section('title', 'Attlog.dat Preview')
@section('page-title', 'Attlog.dat Preview')

@section('content')
@if(session('error'))
    <div class="alert alert-danger py-2 small">{{ session('error') }}</div>
@endif

{{-- One form wraps the whole page so date range + row exclusions + submit all travel together --}}
<form id="importPreviewForm" method="GET" action="{{ route('import.preview') }}">
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                <div>
                    <div class="text-muted small">Staged file</div>
                    <div class="fw-semibold">{{ $stage['filename'] }}</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge bg-secondary">Total lines: {{ number_format($totalLines) }}</span>
                    <span class="badge bg-warning text-dark">Out of range: {{ number_format($outOfRange) }}</span>
                    <span class="badge bg-danger">Invalid: {{ number_format($invalidLines) }}</span>
                    <span class="badge bg-primary">Known IDs: {{ count($knownRows) }}</span>
                    <span class="badge bg-danger">Unknown IDs: {{ count($unknownRows) }}</span>
                </div>
            </div>

            <hr class="my-3">

            {{-- Date range + submit --}}
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">Import window — from</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">to</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-arrow-repeat"></i> Reload preview
                    </button>
                </div>
                <div class="col">
                    <div class="small text-muted">
                        Only punches within this window are imported. Older or newer punches in the file are ignored.
                    </div>
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
        </div>
    </div>
</form>

<form method="POST" action="{{ route('import.preview.commit') }}" id="commitForm"
      onsubmit="return confirmCommit();">
    @csrf
    <input type="hidden" name="date_from" value="{{ $dateFrom }}">
    <input type="hidden" name="date_to"   value="{{ $dateTo }}">

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2 d-flex justify-content-end gap-2">
            <a href="{{ route('import.index') }}" class="btn btn-outline-secondary btn-sm"
               onclick="return confirm('Leave without importing? The staged file will be kept until you cancel it explicitly.');">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <button type="button" class="btn btn-outline-danger btn-sm"
                    onclick="if (confirm('Discard the staged file?')) { document.getElementById('cancelForm').submit(); }">
                <i class="bi bi-x-lg"></i> Cancel &amp; Discard
            </button>
            <button type="submit" class="btn btn-primary btn-sm" @if(count($unknownRows) > 0) disabled title="Resolve unknown IDs first" @endif>
                <i class="bi bi-cloud-upload"></i> Proceed with Import
            </button>
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
                        <thead class="table-light">
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
                                        <td class="text-center p-1">
                                            @if(isset($row['presence'][$d]))
                                                ✅
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
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0"><i class="bi bi-calendar-check"></i> Employees with punches ({{ count($knownRows) }})</h6>
            <div class="d-flex gap-3 align-items-center small text-muted">
                <span>Uncheck to exclude from this import.</span>
                <span>
                    <button type="button" class="btn btn-link btn-sm p-0" onclick="toggleAllEmployees(true)">Include all</button>
                    /
                    <button type="button" class="btn btn-link btn-sm p-0" onclick="toggleAllEmployees(false)">Exclude all</button>
                </span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px" class="text-center">Include</th>
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
                                <td class="text-center">
                                    <input class="form-check-input emp-include" type="checkbox"
                                           name="include_zkteco[]" value="{{ $row['zkteco_id'] }}" checked>
                                </td>
                                <td class="small">{{ $row['department'] }}</td>
                                <td class="small">
                                    <a href="{{ route('employees.edit', $row['employee_id']) }}" target="_blank">{{ $row['name'] }}</a>
                                    <div class="text-muted font-monospace" style="font-size:0.75em;">ID {{ $row['zkteco_id'] }}</div>
                                </td>
                                <td class="small">{{ $row['punch_days'] }} / {{ count($dates) }}</td>
                                @foreach($dates as $d)
                                    <td class="text-center p-1">
                                        @if(isset($row['presence'][$d]))
                                            ✅
                                        @else
                                            <span class="text-muted">❌</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ 4 + count($dates) }}" class="text-center text-muted py-4">No employees with punches in this window.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</form>

{{-- Separate cancel form so the Cancel button can post without carrying the huge commit form's fields --}}
<form id="cancelForm" method="POST" action="{{ route('import.preview.cancel') }}" style="display:none;">
    @csrf
</form>

<script>
    function toggleAllEmployees(state) {
        document.querySelectorAll('.emp-include').forEach(function (cb) { cb.checked = state; });
    }
    function confirmCommit() {
        var included = document.querySelectorAll('.emp-include:checked').length;
        var total = document.querySelectorAll('.emp-include').length;
        if (included === 0) {
            return confirm('You unchecked ALL employees — nothing will be imported. Continue anyway?');
        }
        if (included < total) {
            return confirm('Proceed with import for ' + included + ' of ' + total + ' employees? Excluded employees will be skipped.');
        }
        return confirm('Proceed with import for all ' + total + ' employees within the selected date range?');
    }
</script>
@endsection