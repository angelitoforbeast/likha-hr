<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DayOffCalendarController;
use App\Http\Controllers\EmploymentStatusController;
use App\Http\Controllers\FeaturePermissionController;
use App\Http\Controllers\HolidayController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return redirect('/login');
});

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
Route::post('/register', [RegisterController::class, 'register']);

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Import Attendance (Phase 1)
    Route::get('/import', [ImportController::class, 'index'])->name('import.index');
    Route::post('/import/upload', [ImportController::class, 'upload'])->name('import.upload');
    Route::get('/import/{run}/status', [ImportController::class, 'status'])->name('import.status');

    // Attendance Viewer (Phase 2 & 3)
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('/attendance/compute', [AttendanceController::class, 'compute'])->name('attendance.compute');
    Route::get('/attendance/export-csv', [AttendanceController::class, 'exportCsv'])->name('attendance.export-csv');
    Route::get('/attendance/print', [AttendanceController::class, 'printView'])->name('attendance.print');
    Route::post('/attendance/override', [AttendanceController::class, 'override'])->name('attendance.override');
    Route::post('/attendance/force-compute', [AttendanceController::class, 'forceCompute'])->name('attendance.force-compute');
    Route::get('/attendance/count-overrides', [AttendanceController::class, 'countOverrides'])->name('attendance.count-overrides');
    Route::get('/attendance/cutoff-dates', [AttendanceController::class, 'cutoffDates'])->name('attendance.cutoff-dates');
    Route::get('/attendance/employees-in-range', [AttendanceController::class, 'employeesInRange'])->name('attendance.employees-in-range');

    // Payroll (Phase 4)
    Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::get('/payroll/create', [PayrollController::class, 'create'])->name('payroll.create');
    Route::post('/payroll', [PayrollController::class, 'store'])->name('payroll.store');
    Route::get('/payroll/{run}', [PayrollController::class, 'show'])->name('payroll.show');
    Route::post('/payroll/{run}/finalize', [PayrollController::class, 'finalize'])->name('payroll.finalize');
    Route::post('/payroll/{run}/adjustment', [PayrollController::class, 'saveAdjustment'])->name('payroll.adjustment');
    Route::get('/payroll/{run}/export-csv', [PayrollController::class, 'exportCsv'])->name('payroll.export-csv');
    Route::get('/payroll/{run}/export-pdf', [PayrollController::class, 'exportPdf'])->name('payroll.export-pdf');
    Route::get('/payroll/{run}/payslip/{item}', [PayrollController::class, 'payslip'])->name('payroll.payslip');

    // Employees
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/{employee}/assign-shift', [EmployeeController::class, 'assignShift'])->name('employees.assign-shift');
    Route::delete('/employees/{employee}/shift-assignment/{assignment}', [EmployeeController::class, 'deleteShiftAssignment'])->name('employees.delete-shift-assignment');
    Route::post('/employees/{employee}/add-rate', [EmployeeController::class, 'addRate'])->name('employees.add-rate');
    Route::delete('/employees/{employee}/rate/{rate}', [EmployeeController::class, 'deleteRate'])->name('employees.delete-rate');
    Route::post('/employees/{employee}/inline-update', [EmployeeController::class, 'inlineUpdate'])->name('employees.inline-update');
    Route::post('/employees/{employee}/add-status', [EmployeeController::class, 'addStatus'])->name('employees.add-status');
    Route::delete('/employees/{employee}/status/{status}', [EmployeeController::class, 'deleteStatus'])->name('employees.delete-status');
    Route::post('/employees/{employee}/add-benefit', [EmployeeController::class, 'addBenefit'])->name('employees.add-benefit');
    Route::delete('/employees/{employee}/benefit/{benefit}', [EmployeeController::class, 'deleteBenefit'])->name('employees.delete-benefit');
    Route::post('/employees/{employee}/add-rest-day', [EmployeeController::class, 'addRestDay'])->name('employees.add-rest-day');
    Route::delete('/employees/{employee}/rest-day/{restday}', [EmployeeController::class, 'deleteRestDay'])->name('employees.delete-rest-day');
    Route::post('/employees/{employee}/add-day-off', [EmployeeController::class, 'addDayOff'])->name('employees.add-day-off');
    Route::delete('/employees/{employee}/day-off/{dayoff}', [EmployeeController::class, 'deleteDayOff'])->name('employees.delete-day-off');
    Route::post('/employees/{employee}/add-cash-advance', [EmployeeController::class, 'addCashAdvance'])->name('employees.add-cash-advance');
    Route::delete('/employees/{employee}/cash-advance/{cashadvance}', [EmployeeController::class, 'deleteCashAdvance'])->name('employees.delete-cash-advance');

    // Bulk Operations
    Route::post('/employees/bulk-action', [EmployeeController::class, 'bulkAction'])->name('employees.bulk-action');

    // Shifts
    Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
    Route::get('/shifts/create', [ShiftController::class, 'create'])->name('shifts.create');
    Route::post('/shifts', [ShiftController::class, 'store'])->name('shifts.store');
    Route::get('/shifts/{shift}/edit', [ShiftController::class, 'edit'])->name('shifts.edit');
    Route::put('/shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
    Route::delete('/shifts/{shift}', [ShiftController::class, 'destroy'])->name('shifts.destroy');

    // Departments
    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::get('/departments/create', [DepartmentController::class, 'create'])->name('departments.create');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::get('/departments/{department}', [DepartmentController::class, 'show'])->name('departments.show');
    Route::get('/departments/{department}/edit', [DepartmentController::class, 'edit'])->name('departments.edit');
    Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
    Route::post('/departments/{department}/assign-shift', [DepartmentController::class, 'assignShift'])->name('departments.assign-shift');
    Route::delete('/departments/{department}/shift-assignment/{assignment}', [DepartmentController::class, 'deleteShiftAssignment'])->name('departments.delete-shift-assignment');
    Route::post('/departments/{department}/add-employee', [DepartmentController::class, 'addEmployee'])->name('departments.add-employee');
    Route::delete('/departments/{department}/remove-employee/{employee}', [DepartmentController::class, 'removeEmployee'])->name('departments.remove-employee');

    // Day Off Calendar
    Route::get('/day-off-calendar', [DayOffCalendarController::class, 'index'])->name('dayoff.index');
    Route::post('/day-off-calendar/toggle', [DayOffCalendarController::class, 'toggle'])->name('dayoff.toggle');
    Route::get('/day-off-calendar/employee-month', [DayOffCalendarController::class, 'employeeMonth'])->name('dayoff.employee-month');

    // Employment Statuses (Settings)
    Route::get('/settings/employment-statuses', [EmploymentStatusController::class, 'index'])->name('employment-statuses.index');
    Route::post('/settings/employment-statuses', [EmploymentStatusController::class, 'store'])->name('employment-statuses.store');
    Route::delete('/settings/employment-statuses/{employmentStatus}', [EmploymentStatusController::class, 'destroy'])->name('employment-statuses.destroy');

    // Holiday Management (Settings)
    Route::get('/settings/holidays', [HolidayController::class, 'index'])->name('holidays.index');
    Route::post('/settings/holidays', [HolidayController::class, 'store'])->name('holidays.store');
    Route::put('/settings/holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
    Route::delete('/settings/holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');

    // Feature Permissions (Settings)
    Route::get('/settings/feature-permissions', [FeaturePermissionController::class, 'index'])->name('settings.feature-permissions');
    Route::post('/settings/feature-permissions', [FeaturePermissionController::class, 'update'])->name('settings.feature-permissions.update');

    // User Management
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});
