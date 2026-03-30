@extends('layouts.app')

@section('title', 'Manus Edit Logs')
@section('page-title', 'Manus Edit Logs')

@section('content')
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ url('/manus-edit-logs') }}" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="{{ $dateFrom }}">
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="{{ $dateTo }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filter</button>
            </div>
            <div class="col-auto">
                <a href="{{ url('/manus-edit-logs') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Edit History <span class="text-muted small">({{ $logs->total() }} entries)</span></h6>
    </div>
    <div class="card-body p-0">
        @if($logs->count() > 0)
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.85rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:150px">DateTime</th>
                        <th style="width:60px">Action</th>
                        <th style="width:280px">File</th>
                        <th>What Changed</th>
                        <th>Purpose</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                    <tr>
                        <td class="text-nowrap">{{ $log->datetime->format('Y-m-d H:i') }}</td>
                        <td>
                            @if($log->action === 'ADD')
                                <span class="badge bg-success">ADD</span>
                            @elseif($log->action === 'EDIT')
                                <span class="badge bg-warning text-dark">EDIT</span>
                            @elseif($log->action === 'DELETE')
                                <span class="badge bg-danger">DELETE</span>
                            @else
                                <span class="badge bg-secondary">{{ $log->action }}</span>
                            @endif
                        </td>
                        <td class="text-break"><code style="font-size:.8rem">{{ $log->file }}</code></td>
                        <td>{{ $log->what_changed }}</td>
                        <td>{{ $log->purpose }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-2">
            {{ $logs->links() }}
        </div>
        @else
        <div class="text-center text-muted py-5">
            <i class="bi bi-journal-text" style="font-size: 2rem;"></i>
            <p class="mt-2">No edit logs found.</p>
        </div>
        @endif
    </div>
</div>
@endsection
