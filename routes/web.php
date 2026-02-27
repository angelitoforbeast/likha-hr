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

    // Employees
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/{employee}/assign-shift', [EmployeeController::class, 'assignShift'])->name('employees.assign-shift');
    Route::delete('/employees/{employee}/shift-assignment/{assignment}', [EmployeeController::class, 'deleteShiftAssignment'])->name('employees.delete-shift-assignment');
    Route::post('/employees/{employee}/add-rate', [EmployeeController::class, 'addRate'])->name('employees.add-rate');
    Route::delete('/employees/{employee}/rate/{rate}', [EmployeeController::class, 'deleteRate'])->name('employees.delete-rate');
    Route::post('/employees/{employee}/inline-update', [EmployeeController::class, 'inlineUpdate'])->name('employees.inline-update');

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

    // User Management
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});
