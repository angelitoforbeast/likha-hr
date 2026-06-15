<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add category column to distinguish navigation vs employee-section permissions
        Schema::table('feature_permissions', function (Blueprint $table) {
            $table->string('category', 32)->default('employee_section')->after('feature_label');
        });

        // Tag all existing rows as employee_section
        DB::table('feature_permissions')->update(['category' => 'employee_section']);

        // Seed navigation permissions
        $navFeatures = [
            ['key' => 'nav_dashboard',                'label' => 'Dashboard',                'sort' => 101],
            ['key' => 'nav_import',                   'label' => 'Import Attendance',        'sort' => 102],
            ['key' => 'nav_attendance',               'label' => 'Attendance Viewer',        'sort' => 103],
            ['key' => 'nav_attendance_calendar',      'label' => 'Attendance Calendar',      'sort' => 104],
            ['key' => 'nav_payroll',                  'label' => 'Payroll',                  'sort' => 105],
            ['key' => 'nav_employees',                'label' => 'Employees',                'sort' => 106],
            ['key' => 'nav_shifts',                   'label' => 'Shifts',                   'sort' => 107],
            ['key' => 'nav_departments',              'label' => 'Departments',              'sort' => 108],
            ['key' => 'nav_day_off_calendar',         'label' => 'Day Off Calendar',         'sort' => 109],
            ['key' => 'nav_users',                    'label' => 'User Management',          'sort' => 110],
            ['key' => 'nav_edit_logs',                'label' => 'Edit Logs',                'sort' => 111],
            ['key' => 'nav_settings',                 'label' => 'Settings (parent)',        'sort' => 112],
            ['key' => 'nav_settings_employment_statuses', 'label' => '  → Employment Statuses', 'sort' => 113],
            ['key' => 'nav_settings_holidays',        'label' => '  → Holiday Calendar',     'sort' => 114],
        ];

        // Default permissions per role:
        // CEO is always full access (and bypassed in middleware, but stored as true for consistency).
        // Admin: most nav items except Payroll, User Management.
        // HR Staff: limited — Dashboard, Attendance viewer, Attendance Calendar, Employees.
        $rolePerms = [
            'ceo' => [
                'nav_dashboard' => true, 'nav_import' => true, 'nav_attendance' => true,
                'nav_attendance_calendar' => true, 'nav_payroll' => true, 'nav_employees' => true,
                'nav_shifts' => true, 'nav_departments' => true, 'nav_day_off_calendar' => true,
                'nav_users' => true, 'nav_edit_logs' => true, 'nav_settings' => true,
                'nav_settings_employment_statuses' => true, 'nav_settings_holidays' => true,
            ],
            'admin' => [
                'nav_dashboard' => true, 'nav_import' => true, 'nav_attendance' => true,
                'nav_attendance_calendar' => true, 'nav_payroll' => false, 'nav_employees' => true,
                'nav_shifts' => true, 'nav_departments' => true, 'nav_day_off_calendar' => true,
                'nav_users' => false, 'nav_edit_logs' => true, 'nav_settings' => true,
                'nav_settings_employment_statuses' => true, 'nav_settings_holidays' => true,
            ],
            'hr_staff' => [
                'nav_dashboard' => true, 'nav_import' => false, 'nav_attendance' => true,
                'nav_attendance_calendar' => true, 'nav_payroll' => false, 'nav_employees' => true,
                'nav_shifts' => false, 'nav_departments' => false, 'nav_day_off_calendar' => false,
                'nav_users' => false, 'nav_edit_logs' => false, 'nav_settings' => false,
                'nav_settings_employment_statuses' => false, 'nav_settings_holidays' => false,
            ],
        ];

        $now = now();
        foreach ($navFeatures as $feature) {
            foreach ($rolePerms as $role => $perms) {
                $canView = $perms[$feature['key']] ?? false;
                DB::table('feature_permissions')->insert([
                    'feature_key'   => $feature['key'],
                    'feature_label' => $feature['label'],
                    'category'      => 'navigation',
                    'role'          => $role,
                    'can_view'      => $canView,
                    'can_edit'      => $canView, // can_edit is unused for nav, mirror can_view
                    'sort_order'    => $feature['sort'],
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('feature_permissions')->where('category', 'navigation')->delete();

        Schema::table('feature_permissions', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
