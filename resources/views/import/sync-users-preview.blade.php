@extends('layouts.app')

@section('title', 'Sync Users — Preview')
@section('page-title', 'Sync Users — Preview')

@php
    function suCatBadge($c) {
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
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
            <div>
                <div class="text-muted small">Staged file</div>
                <div class="fw-semibold">{{ $stage['filename'] }}</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <span class="badge bg-success">Exact match: {{ $counts['match_exact'] }}</span>
                <span class="badge bg-warning text-dark">Name changed: {{ $counts['match_id_only'] }}</span>
                <span class="badge bg-danger">Name conflict: {{ $counts['name_conflict'] }}</span>
                <span class="badge bg-info text-dark">New: {{ $counts['new'] }}</span>
            </div>
        </div>

        @if($counts['name_conflict'] > 0)
        <div class="alert alert-warning small mt-3 mb-0">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>Warning —</strong> {{ $counts['name_conflict'] }} name conflict(s) detected. Proceeding will create a
            NEW employee with the same name as an existing one, which usually means a ZKTeco ID was swapped or reassigned.
            Please review carefully.
        </div>
        @endif

        <div class="d-flex justify-content-end gap-2 mt-3">
            <form method="POST" action="{{ route('import.sync-users.cancel') }}" onsubmit="return confirm('Discard the staged file?')">
                @csrf
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
            </form>
            <form method="POST" action="{{ route('import.sync-users.commit') }}" onsubmit="return confirm('Apply this sync? {{ $counts['new'] }} new, {{ $counts['match_id_only'] }} renamed.')">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check2-square"></i> Apply Sync
                </button>
            </form>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <h6 class="mb-0"><i class="bi bi-people"></i> Users in file ({{ count($results) }})</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:110px">Status</th>
                        <th style="width:90px">ZKTeco ID</th>
                        <th>Name in file</th>
                        <th>Current in system</th>
                        <th>Action if applied</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($results as $r)
                    <tr class="{{ $r['category'] === 'name_conflict' ? 'table-danger' : ($r['category'] === 'match_id_only' ? 'table-warning' : ($r['category'] === 'new' ? 'table-info' : '')) }}">
                        <td>{!! suCatBadge($r['category']) !!}</td>
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
                                No change.
                            @elseif($r['category'] === 'match_id_only')
                                Rename employee to <strong>{{ $r['file_name'] }}</strong>.
                            @elseif($r['category'] === 'name_conflict')
                                Create new employee (name already used by id
                                @foreach($r['name_matches_other'] as $oid)#{{ $oid }}@if(!$loop->last), @endif @endforeach).
                            @elseif($r['category'] === 'new')
                                Create new employee.
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection