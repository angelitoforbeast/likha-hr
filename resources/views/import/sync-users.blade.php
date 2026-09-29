@extends('layouts.app')

@section('title', 'Sync Users')
@section('page-title', 'Sync Users (user.dat)')

@section('content')
<div class="mb-3">
    <a href="{{ route('import.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Imports
    </a>
</div>

@if(session('error'))
    <div class="alert alert-danger py-2 small">{{ session('error') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger py-2 small">
        <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="card border-0 shadow-sm mb-3" style="max-width:640px;">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0"><i class="bi bi-people-fill"></i> Sync users from user.dat</h6>
        <a href="{{ route('import.check.form') }}" class="btn btn-sm btn-outline-info">
            <i class="bi bi-search"></i> Read-only check instead
        </a>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            This page updates the employees table from a ZKTeco <code>user.dat</code>. You'll see a preview first
            (mapping check) before anything is written. No attendance is processed here — attlog.dat is uploaded
            separately from the main Import page.
        </p>
        <form method="POST" action="{{ route('import.sync-users.upload') }}" enctype="multipart/form-data">
            @csrf
            <div class="mb-3">
                <label class="form-label small fw-semibold">user.dat file</label>
                <input type="file" name="user_dat" class="form-control form-control-sm" accept=".dat,application/octet-stream" required>
                <small class="text-muted">Max 50MB. Binary or text ZKTeco format.</small>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-eye"></i> Upload &amp; Preview
            </button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm" style="max-width:640px;">
    <div class="card-body">
        <h6 class="fw-semibold"><i class="bi bi-info-circle"></i> What Sync Users does</h6>
        <ul class="small text-muted mb-0">
            <li>Creates a new employee row for every ZKTeco ID not yet in the system.</li>
            <li>Renames existing employees when the name in user.dat differs from what's stored.</li>
            <li>Leaves untouched any employee whose ID/name matches exactly.</li>
            <li>Does <strong>not</strong> delete employees who are missing from the file (they stay).</li>
        </ul>
    </div>
</div>
@endsection