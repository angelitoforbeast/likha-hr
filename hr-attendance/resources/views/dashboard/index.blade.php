@extends('layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-primary bg-opacity-10 rounded-3 p-3">
                        <i class="bi bi-people fs-3 text-primary"></i>
                    </div>
                    <div class="ms-3">
                        <h6 class="text-muted mb-1">Active Employees</h6>
                        <h3 class="mb-0">{{ $stats['total_employees'] }}</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-success bg-opacity-10 rounded-3 p-3">
                        <i class="bi bi-upload fs-3 text-success"></i>
                    </div>
                    <div class="ms-3">
                        <h6 class="text-muted mb-1">Import Runs</h6>
                        <h3 class="mb-0">{{ $stats['total_imports'] }}</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-warning bg-opacity-10 rounded-3 p-3">
                        <i class="bi bi-cash-stack fs-3 text-warning"></i>
                    </div>
                    <div class="ms-3">
                        <h6 class="text-muted mb-1">Payroll Runs</h6>
                        <h3 class="mb-0">{{ $stats['total_payrolls'] }}</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h5 class="card-title">Quick Actions</h5>
        <div class="d-flex gap-2 flex-wrap mt-3">
            <a href="{{ url('/import') }}" class="btn btn-outline-primary">
                <i class="bi bi-upload"></i> Import Attendance
            </a>
            <a href="{{ url('/attendance') }}" class="btn btn-outline-success">
                <i class="bi bi-calendar-check"></i> View Attendance
            </a>
            <a href="{{ url('/payroll') }}" class="btn btn-outline-warning">
                <i class="bi bi-cash-stack"></i> Manage Payroll
            </a>
        </div>
    </div>
</div>
@endsection
