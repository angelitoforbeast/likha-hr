@extends('layouts.app')

@section('title', 'Check user.dat')
@section('page-title', 'Check user.dat')

@section('content')
<div class="mb-3">
    <a href="{{ route('import.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Imports
    </a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white">
        <h6 class="mb-0"><i class="bi bi-search"></i> Check user.dat mapping</h6>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Upload a <code>user.dat</code> here to see how it would be interpreted against the current employees table.
            Nothing is written — this is read-only preview. Use it after a biometric transfer to verify that each ZKTeco ID
            still points to the correct employee before running a real import from <em>Import Attendance</em>.
        </p>

        @if(session('error'))
            <div class="alert alert-danger py-2 small">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger py-2 small">
                <ul class="mb-0 ps-3">
                    @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('import.check') }}" enctype="multipart/form-data">
            @csrf
            <div class="mb-3">
                <label class="form-label small fw-semibold">user.dat file</label>
                <input type="file" name="user_dat" class="form-control form-control-sm" accept=".dat,application/octet-stream" required>
                <small class="text-muted">Max 50MB. Binary or text ZKTeco format.</small>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-search"></i> Check Mapping
            </button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h6 class="fw-semibold"><i class="bi bi-info-circle"></i> What the checker reports</h6>
        <ul class="small text-muted mb-0">
            <li><span class="badge bg-success">EXACT MATCH</span> — file entry matches an existing employee (ID + name).</li>
            <li><span class="badge bg-warning text-dark">NAME CHANGED</span> — same ZKTeco ID in system but the name differs. A real import would rename the employee.</li>
            <li><span class="badge bg-danger">NAME CONFLICT</span> — ID is new in the system but the name matches a DIFFERENT existing employee under another ID. Likely a swapped/reassigned ID.</li>
            <li><span class="badge bg-info text-dark">NEW</span> — ID is not in the system and the name isn't recognised either. Would create a new employee if imported.</li>
            <li><span class="badge bg-secondary">MISSING FROM FILE</span> — employee exists in the system but wasn't in the uploaded file. Would not be deleted, just flagged for review.</li>
        </ul>
    </div>
</div>
@endsection