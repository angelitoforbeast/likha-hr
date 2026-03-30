<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'HR Attendance & Payroll')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; }
        .sidebar {
            min-height: 100vh;
            background: #212529;
            color: #fff;
            width: 250px;
            position: fixed;
            top: 0; left: 0;
            z-index: 100;
        }
        .sidebar .nav-link { color: rgba(255,255,255,.7); padding: .75rem 1.25rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.1); }
        .sidebar .nav-link i { margin-right: .5rem; width: 20px; text-align: center; }
        .main-content { margin-left: 250px; padding: 1.5rem; }
        .sidebar-brand { padding: 1rem 1.25rem; font-size: 1.1rem; font-weight: 600; border-bottom: 1px solid rgba(255,255,255,.1); }
        .top-bar { background: #fff; border-bottom: 1px solid #dee2e6; padding: .75rem 1.5rem; margin: -1.5rem -1.5rem 1.5rem; }
        @media print {
            .sidebar, .top-bar, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
        }
    </style>
    @stack('styles')
</head>
<body>
    {{-- Sidebar --}}
    <div class="sidebar d-print-none">
        <div class="sidebar-brand">
            <i class="bi bi-clock-history"></i> HR Attendance
        </div>
        <nav class="nav flex-column mt-2">
            <a class="nav-link {{ request()->is('dashboard*') ? 'active' : '' }}" href="{{ url('/dashboard') }}">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a class="nav-link {{ request()->is('import*') ? 'active' : '' }}" href="{{ url('/import') }}">
                <i class="bi bi-upload"></i> Import Attendance
            </a>
            <a class="nav-link {{ request()->is('attendance', 'attendance/*') ? 'active' : '' }}" href="{{ url('/attendance') }}">
                <i class="bi bi-calendar-check"></i> Attendance Viewer
            </a>
            <a class="nav-link {{ request()->is("attendance-calendar*") ? "active" : "" }}" href="{{ url("/attendance-calendar") }}">
                <i class="bi bi-calendar2-week"></i> Attendance Calendar
            </a>
            <a class="nav-link {{ request()->is('payroll*') ? 'active' : '' }}" href="{{ url('/payroll') }}">
                <i class="bi bi-cash-stack"></i> Payroll
            </a>
            <a class="nav-link {{ request()->is('employees*') ? 'active' : '' }}" href="{{ url('/employees') }}">
                <i class="bi bi-people"></i> Employees
            </a>
            <a class="nav-link {{ request()->is('shifts*') ? 'active' : '' }}" href="{{ url('/shifts') }}">
                <i class="bi bi-clock"></i> Shifts
            </a>
            <a class="nav-link {{ request()->is('departments*') ? 'active' : '' }}" href="{{ url('/departments') }}">
                <i class="bi bi-diagram-3"></i> Departments
            </a>
            <a class="nav-link {{ request()->is('day-off-calendar*') ? 'active' : '' }}" href="{{ url('/day-off-calendar') }}">
                <i class="bi bi-calendar-x"></i> Day Off Calendar
            </a>
            <a class="nav-link {{ request()->is('users*') ? 'active' : '' }}" href="{{ url('/users') }}">
                <i class="bi bi-person-gear"></i> User Management
            </a>
            <div class="nav-item">
                <a class="nav-link d-flex justify-content-between align-items-center {{ request()->is('settings*') ? 'active' : '' }}" data-bs-toggle="collapse" href="#settingsMenu" role="button">
                    <span><i class="bi bi-gear"></i> Settings</span>
                    <i class="bi bi-chevron-down small"></i>
                </a>
                <div class="collapse {{ request()->is('settings*') ? 'show' : '' }}" id="settingsMenu">
                    <a class="nav-link ps-4 py-2 {{ request()->is('settings/employment-statuses*') ? 'active' : '' }}" href="{{ url('/settings/employment-statuses') }}">
                        <i class="bi bi-tags"></i> Employment Statuses
                    </a>
                    <a class="nav-link ps-4 py-2 {{ request()->is('settings/holidays*') ? 'active' : '' }}" href="{{ url('/settings/holidays') }}">
                        <i class="bi bi-calendar-heart"></i> Holiday Calendar
                    </a>
                    @if(Auth::user() && Auth::user()->role === 'ceo')
                    <a class="nav-link ps-4 py-2 {{ request()->is('settings/feature-permissions*') ? 'active' : '' }}" href="{{ url('/settings/feature-permissions') }}">
                        <i class="bi bi-shield-lock"></i> Feature Permissions
                    </a>
                    @endif
                </div>
            </div>
        </nav>
    </div>

    {{-- Main Content --}}
    <div class="main-content">
        <div class="top-bar d-flex justify-content-between align-items-center d-print-none">
            <h5 class="mb-0">@yield('page-title', 'Dashboard')</h5>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted small">
                    {{ Auth::user()->name ?? '' }}
                    @if(Auth::user())
                        <span class="badge bg-secondary">{{ Auth::user()->role_label }}</span>
                    @endif
                </span>
                <form method="POST" action="{{ url('/logout') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </form>
            </div>
        </div>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('warning'))
            <div class="alert alert-warning alert-dismissible fade show">
                {{ session('warning') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show">
                <ul class="mb-0">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @yield('content')
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    @stack('scripts')
</body>
</html>
