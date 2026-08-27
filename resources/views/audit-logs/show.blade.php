@extends('layouts.app')

@section('title', 'Audit Log #' . $log->id)
@section('page-title', 'Audit Log Detail')

@php
    // Compute a per-field diff between old and new values.
    $old = is_array($log->old_values) ? $log->old_values : [];
    $new = is_array($log->new_values) ? $log->new_values : [];
    $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));
    sort($allKeys);

    function formatVal($v) {
        if ($v === null) return '<span class="text-muted fst-italic">(null)</span>';
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_array($v)) return '<code>' . e(json_encode($v)) . '</code>';
        return e((string) $v);
    }
@endphp

@section('content')
<div class="mb-3">
    <a href="{{ route('audit-logs.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to logs
    </a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="mb-0">
                @if($log->action === 'created')
                    <span class="badge bg-success"><i class="bi bi-plus-lg"></i> Created</span>
                @elseif($log->action === 'updated')
                    <span class="badge bg-primary"><i class="bi bi-pencil"></i> Updated</span>
                @elseif($log->action === 'deleted')
                    <span class="badge bg-danger"><i class="bi bi-trash"></i> Deleted</span>
                @endif
                {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
            </h6>
            <small class="text-muted">Log ID: {{ $log->id }}</small>
        </div>
    </div>
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tbody>
                <tr>
                    <th style="width:200px">When</th>
                    <td>{{ $log->created_at->format('Y-m-d H:i:s') }} ({{ $log->created_at->diffForHumans() }})</td>
                </tr>
                <tr>
                    <th>Who</th>
                    <td>
                        {{ $log->user?->name ?? '—' }}
                        <span class="badge bg-secondary ms-1">{{ $log->user?->role ?? '—' }}</span>
                    </td>
                </tr>
                <tr>
                    <th>IP Address</th>
                    <td class="font-monospace">{{ $log->ip_address ?? '—' }}</td>
                </tr>
                <tr>
                    <th>Description</th>
                    <td>{{ $log->description ?? '—' }}</td>
                </tr>
                <tr>
                    <th>Entity</th>
                    <td>
                        <code>{{ $log->auditable_type }}</code>
                        <span class="text-muted">(ID: {{ $log->auditable_id }})</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <h6 class="mb-0"><i class="bi bi-arrow-left-right"></i> Field Changes</h6>
    </div>
    <div class="card-body p-0">
        @if(empty($allKeys))
            <div class="p-3 text-muted small">No field data recorded.</div>
        @else
        <table class="table table-sm table-bordered mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:200px">Field</th>
                    <th>Old Value</th>
                    <th>New Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach($allKeys as $key)
                <tr>
                    <td class="fw-semibold small">{{ $key }}</td>
                    <td class="small">{!! formatVal($old[$key] ?? null) !!}</td>
                    <td class="small">
                        @if(($old[$key] ?? null) !== ($new[$key] ?? null))
                            <span class="bg-warning bg-opacity-25 px-1 rounded">
                                {!! formatVal($new[$key] ?? null) !!}
                            </span>
                        @else
                            {!! formatVal($new[$key] ?? null) !!}
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>
@endsection
