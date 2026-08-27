@extends('layouts.app')

@section('title', 'Audit Logs')
@section('page-title', 'Audit Logs')

@section('content')
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Entity Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach($types as $t)
                        <option value="{{ $t }}" {{ request('type') === $t ? 'selected' : '' }}>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach(['created', 'updated', 'deleted'] as $a)
                        <option value="{{ $a }}" {{ request('action') === $a ? 'selected' : '' }}>{{ ucfirst($a) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">User</label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}" {{ (string) request('user_id') === (string) $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="description..." class="form-control form-control-sm">
            </div>
            <div class="col-md-12 text-end">
                <a href="{{ route('audit-logs.index') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i> Clear
                </a>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-funnel"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:150px">When</th>
                        <th style="width:150px">Who</th>
                        <th style="width:100px">Action</th>
                        <th>Description</th>
                        <th style="width:100px">Changes</th>
                        <th style="width:120px">IP</th>
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                    <tr>
                        <td class="small">
                            {{ $log->created_at->format('Y-m-d') }}<br>
                            <span class="text-muted">{{ $log->created_at->format('H:i:s') }}</span>
                        </td>
                        <td class="small">
                            {{ $log->user?->name ?? '—' }}<br>
                            <span class="text-muted small">{{ $log->user?->role ?? '' }}</span>
                        </td>
                        <td>
                            @if($log->action === 'created')
                                <span class="badge bg-success"><i class="bi bi-plus-lg"></i> Created</span>
                            @elseif($log->action === 'updated')
                                <span class="badge bg-primary"><i class="bi bi-pencil"></i> Updated</span>
                            @elseif($log->action === 'deleted')
                                <span class="badge bg-danger"><i class="bi bi-trash"></i> Deleted</span>
                            @else
                                <span class="badge bg-secondary">{{ $log->action }}</span>
                            @endif
                        </td>
                        <td>
                            <div class="fw-semibold small">{{ $log->description ?? '—' }}</div>
                            <div class="text-muted small">
                                {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                            </div>
                        </td>
                        <td class="small">
                            @if($log->action === 'updated' && is_array($log->new_values))
                                <span class="badge bg-info">{{ count($log->new_values) }} field(s)</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="small text-muted">{{ $log->ip_address ?? '—' }}</td>
                        <td>
                            <a href="{{ route('audit-logs.show', $log) }}" class="btn btn-sm btn-outline-info">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No audit logs match your filters.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($logs->hasPages())
    <div class="card-footer bg-white">
        {{ $logs->links() }}
    </div>
    @endif
</div>
@endsection
