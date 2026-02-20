@extends('layouts.app')

@section('title', 'Import Attendance')
@section('page-title', 'Import Attendance')

@section('content')
<div class="row g-4">
    {{-- Upload Form --}}
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-upload"></i> Upload ZKTeco Files</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('import.upload') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label for="user_dat" class="form-label">user.dat <span class="text-danger">*</span></label>
                        <input type="file" class="form-control @error('user_dat') is-invalid @enderror"
                               id="user_dat" name="user_dat" accept=".dat,.txt" required>
                        @error('user_dat')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">ZKTeco user data file (maps user IDs to names)</div>
                    </div>
                    <div class="mb-3">
                        <label for="attlog_dat" class="form-label">attlog.dat <span class="text-danger">*</span></label>
                        <input type="file" class="form-control @error('attlog_dat') is-invalid @enderror"
                               id="attlog_dat" name="attlog_dat" accept=".dat,.txt" required>
                        @error('attlog_dat')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">ZKTeco attendance log file (punch records)</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-cloud-upload"></i> Upload &amp; Process
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Import Runs --}}
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-list-check"></i> Import History</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Uploaded By</th>
                            <th>Status</th>
                            <th>Stats</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody id="runs-table">
                        @forelse($runs as $run)
                        <tr data-run-id="{{ $run->id }}" data-status="{{ $run->status }}">
                            <td>{{ $run->id }}</td>
                            <td>{{ $run->uploader->name ?? '—' }}</td>
                            <td>
                                <span class="badge run-status-badge
                                    {{ $run->status === 'done' ? 'bg-success' : '' }}
                                    {{ $run->status === 'failed' ? 'bg-danger' : '' }}
                                    {{ $run->status === 'processing' ? 'bg-warning text-dark' : '' }}
                                    {{ $run->status === 'queued' ? 'bg-info text-dark' : '' }}
                                ">
                                    {{ strtoupper($run->status) }}
                                </span>
                            </td>
                            <td class="run-stats small">
                                @if($run->stats_json)
                                    Users: {{ $run->stats_json['total_users_parsed'] ?? 0 }},
                                    Logs: {{ $run->stats_json['total_logs_inserted'] ?? 0 }}/{{ $run->stats_json['total_logs_parsed'] ?? 0 }},
                                    Dupes: {{ $run->stats_json['total_duplicates_skipped'] ?? 0 }},
                                    Unmatched: {{ $run->stats_json['unmatched_zkteco_ids'] ?? 0 }}
                                    @if(isset($run->stats_json['duration_seconds']))
                                        ({{ $run->stats_json['duration_seconds'] }}s)
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small">{{ $run->created_at->format('M d, Y H:i') }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No import runs yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($runs->hasPages())
            <div class="card-footer bg-white">
                {{ $runs->links() }}
            </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Poll for active runs
    function pollActiveRuns() {
        const rows = document.querySelectorAll('tr[data-run-id]');
        rows.forEach(row => {
            const status = row.getAttribute('data-status');
            if (status === 'queued' || status === 'processing') {
                const runId = row.getAttribute('data-run-id');
                fetch(`/import/${runId}/status`)
                    .then(r => r.json())
                    .then(data => {
                        row.setAttribute('data-status', data.status);

                        // Update status badge
                        const badge = row.querySelector('.run-status-badge');
                        badge.textContent = data.status.toUpperCase();
                        badge.className = 'badge run-status-badge';
                        if (data.status === 'done') badge.classList.add('bg-success');
                        else if (data.status === 'failed') badge.classList.add('bg-danger');
                        else if (data.status === 'processing') badge.classList.add('bg-warning', 'text-dark');
                        else badge.classList.add('bg-info', 'text-dark');

                        // Update stats
                        const statsCell = row.querySelector('.run-stats');
                        if (data.stats_json) {
                            const s = data.stats_json;
                            statsCell.innerHTML =
                                `Users: ${s.total_users_parsed || 0}, ` +
                                `Logs: ${s.total_logs_inserted || 0}/${s.total_logs_parsed || 0}, ` +
                                `Dupes: ${s.total_duplicates_skipped || 0}, ` +
                                `Unmatched: ${s.unmatched_zkteco_ids || 0}` +
                                (s.duration_seconds ? ` (${s.duration_seconds}s)` : '');
                        }
                    })
                    .catch(() => {});
            }
        });
    }

    setInterval(pollActiveRuns, 3000);
});
</script>
@endpush
