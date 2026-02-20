@extends('layouts.app')

@section('title', 'Shifts')
@section('page-title', 'Shifts')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Shift List</h5>
        <a href="{{ route('shifts.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg"></i> Create Shift
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Schedule</th>
                        <th>Lunch</th>
                        <th>Work Mins</th>
                        <th>Grace In</th>
                        <th>Grace Out</th>
                        <th>Lunch Window</th>
                        <th>Employees</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($shifts as $shift)
                    <tr>
                        <td class="fw-semibold">{{ $shift->name }}</td>
                        <td>
                            {{ \Carbon\Carbon::parse($shift->start_time)->format('g:i A') }}
                            —
                            {{ \Carbon\Carbon::parse($shift->end_time)->format('g:i A') }}
                        </td>
                        <td>
                            {{ \Carbon\Carbon::parse($shift->lunch_start)->format('g:i A') }}
                            —
                            {{ \Carbon\Carbon::parse($shift->lunch_end)->format('g:i A') }}
                        </td>
                        <td>{{ $shift->required_work_minutes }}</td>
                        <td>{{ $shift->grace_in_minutes }} min</td>
                        <td>{{ $shift->grace_out_minutes }} min</td>
                        <td>{{ $shift->lunch_inference_window_before_minutes }}min before / {{ $shift->lunch_inference_window_after_minutes }}min after</td>
                        <td>
                            <span class="badge bg-info">{{ $shift->employees_count }}</span>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="{{ route('shifts.edit', $shift) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                                <form method="POST" action="{{ route('shifts.destroy', $shift) }}"
                                      onsubmit="return confirm('Delete shift \'{{ $shift->name }}\'? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No shifts found. Create one to get started.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
