@extends('layouts.app')

@section('title', 'user.dat Check Result')
@section('page-title', 'user.dat Check Result')

@php
    function catBadge($c) {
        return match ($c) {
            'match_exact'   => '<span class="badge bg-success">EXACT MATCH</span>',
            'match_id_only' => '<span class="badge bg-warning text-dark">NAME CHANGED</span>',
            'name_conflict' => '<span class="badge bg-danger">NAME CONFLICT</span>',
            'new'           => '<span class="badge bg-info text-dark">NEW</span>',
            default         => '<span class="badge bg-secondary">' . e($c) . '</span>',
        };
    }
@endphp

@section('content')
<div class="mb-3 d-flex gap-2">
    <a href="{{ route('import.check.form') }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-arrow-repeat"></i> Check Another File
    </a>
    <a href="{{ route('import.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Imports
    </a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="text-muted small">Checked file</div>
                <div class="fw-semibold">{{ $fileName }}</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <span class="badge bg-success">Exact match: {{ $counts['match_exact'] }}</span>
                <span class="badge bg-warning text-dark">Name changed: {{ $counts['match_id_only'] }}</span>
                <span class="badge bg-danger">Name conflict: {{ $counts['name_conflict'] }}</span>
                <span class="badge bg-info text-dark">New: {{ $counts['new'] }}</span>
                <span class="badge bg-secondary">Missing from file: {{ count($missing) }}</span>
            </div>
        </div>
        <div class="alert alert-info small mt-3 mb-0">
            <i class="bi bi-shield-check"></i>
            <strong>No changes were made.</strong> This is a read-only check. To actually import, use
            <a href="{{ route('import.index') }}">Import Attendance</a>.
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white">
        <h6 class="mb-0"><i class="bi bi-people"></i> Users found in file ({{ count($results) }})</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:80px">Status</th>
                        <th style="width:80px">ZKTeco ID</th>
                        <th>Name in file</th>
                        <th>Current name in system</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($results as $r)
                    <tr class="{{ $r['category'] === 'name_conflict' ? 'table-danger' : ($r['category'] === 'match_id_only' ? 'table-warning' : ($r['category'] === 'new' ? 'table-info' : '')) }}">
                        <td>{!! catBadge($r['category']) !!}</td>
                        <td class="font-monospace small">{{ $r['zkteco_id'] }}</td>
                        <td class="small">{{ $r['file_name'] }}</td>
                        <td class="small">
                            @if($r['system_id'])
                                <a href="{{ route('employees.edit', $r['system_id']) }}" target="_blank">{{ $r['system_name'] }}</a>
                                <small class="text-muted">(id={{ $r['system_id'] }})</small>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="small text-muted">
                            @if($r['category'] === 'match_exact')
                                No change on import.
                            @elseif($r['category'] === 'match_id_only')
                                Import would rename this employee to <strong>{{ $r['file_name'] }}</strong>.
                            @elseif($r['category'] === 'name_conflict')
                                A different employee already goes by this name:
                                @foreach($r['name_matches_other'] as $otherId)
                                    <a href="{{ route('employees.edit', $otherId) }}" target="_blank">#{{ $otherId }}</a>@if(!$loop->last), @endif
                                @endforeach
                                — likely a swapped ID.
                            @elseif($r['category'] === 'new')
                                Import would create a new employee.
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No users found in the uploaded file.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@if(!empty($missing))
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white">
        <h6 class="mb-0"><i class="bi bi-exclamation-triangle text-secondary"></i> Missing from this file ({{ count($missing) }})</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:120px">ZKTeco ID in system</th>
                        <th>Current employee</th>
                        <th class="small text-muted">Reason to review</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($missing as $m)
                    <tr>
                        <td class="font-monospace small">{{ $m['zkteco_id'] }}</td>
                        <td class="small">
                            <a href="{{ route('employees.edit', $m['system_id']) }}" target="_blank">{{ $m['system_name'] }}</a>
                            <small class="text-muted">(id={{ $m['system_id'] }})</small>
                        </td>
                        <td class="small text-muted">
                            Exists in system but not in the uploaded file. If this file is your only source of truth, this employee may have been removed/renumbered on the biometric device.
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
@endsection