@extends('layouts.app')

@section('title', 'Payroll Runs')
@section('page-title', 'Payroll Runs')

@section('content')
<div class="d-flex justify-content-between mb-3">
    <h5>All Payroll Runs</h5>
    <a href="{{ route('payroll.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Create Payroll Run
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Cutoff Period</th>
                    <th>Created By</th>
                    <th>Employees</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($runs as $run)
                <tr>
                    <td>{{ $run->id }}</td>
                    <td>{{ $run->cutoff_start->format('M d') }} — {{ $run->cutoff_end->format('M d, Y') }}</td>
                    <td>{{ $run->creator->name ?? '—' }}</td>
                    <td>{{ $run->items_count }}</td>
                    <td>
                        <span class="badge {{ $run->status === 'final' ? 'bg-success' : 'bg-secondary' }}">
                            {{ strtoupper($run->status) }}
                        </span>
                    </td>
                    <td>{{ $run->created_at->format('M d, Y H:i') }}</td>
                    <td class="d-flex gap-1">
                        <a href="{{ route('payroll.show', $run) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i> View
                        </a>
                        @if(auth()->user()->role === 'ceo')
                        <form action="{{ route('payroll.destroy', $run) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete Payroll Run #{{ $run->id }}? This will permanently remove all payroll items. This action cannot be undone.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No payroll runs yet.</td>
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
@endsection
