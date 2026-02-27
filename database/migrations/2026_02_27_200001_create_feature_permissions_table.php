<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('feature_key');       // e.g., 'basic_information', 'shift_assignments'
            $table->string('feature_label');      // e.g., 'Basic Information', 'Shift Assignments'
            $table->string('role');               // 'ceo', 'admin', 'hr_staff'
            $table->boolean('can_view')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['feature_key', 'role']);
        });

        // Seed default permissions
        $features = [
            ['key' => 'basic_information',    'label' => 'Basic Information',    'sort' => 1],
            ['key' => 'employment_status',    'label' => 'Employment Status',    'sort' => 2],
            ['key' => 'shift_assignments',    'label' => 'Shift Assignments',    'sort' => 3],
            ['key' => 'schedule_mode',        'label' => 'Schedule Mode',        'sort' => 4],
            ['key' => 'daily_rates',          'label' => 'Daily Rates',          'sort' => 5],
            ['key' => 'benefits_deductions',  'label' => 'Benefits & Deductions','sort' => 6],
            ['key' => 'rest_day_pattern',     'label' => 'Rest Day Pattern',     'sort' => 7],
            ['key' => 'day_off_overrides',    'label' => 'Day Off Overrides',    'sort' => 8],
            ['key' => 'cash_advance',         'label' => 'Cash Advance',         'sort' => 9],
            ['key' => 'night_differential',   'label' => 'Night Differential',   'sort' => 10],
        ];

        $roles = [
            'ceo'      => ['basic_information' => [true, true], 'employment_status' => [true, true], 'shift_assignments' => [true, true], 'schedule_mode' => [true, true], 'daily_rates' => [true, true], 'benefits_deductions' => [true, true], 'rest_day_pattern' => [true, true], 'day_off_overrides' => [true, true], 'cash_advance' => [true, true], 'night_differential' => [true, true]],
            'admin'    => ['basic_information' => [true, true], 'employment_status' => [true, false], 'shift_assignments' => [true, true], 'schedule_mode' => [true, true], 'daily_rates' => [true, false], 'benefits_deductions' => [true, false], 'rest_day_pattern' => [true, true], 'day_off_overrides' => [true, true], 'cash_advance' => [true, false], 'night_differential' => [true, false]],
            'hr_staff' => ['basic_information' => [true, false], 'employment_status' => [false, false], 'shift_assignments' => [true, false], 'schedule_mode' => [false, false], 'daily_rates' => [false, false], 'benefits_deductions' => [false, false], 'rest_day_pattern' => [true, false], 'day_off_overrides' => [true, false], 'cash_advance' => [false, false], 'night_differential' => [false, false]],
        ];

        $now = now();
        foreach ($features as $feature) {
            foreach ($roles as $role => $perms) {
                DB::table('feature_permissions')->insert([
                    'feature_key'   => $feature['key'],
                    'feature_label' => $feature['label'],
                    'role'          => $role,
                    'can_view'      => $perms[$feature['key']][0],
                    'can_edit'      => $perms[$feature['key']][1],
                    'sort_order'    => $feature['sort'],
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_permissions');
    }
};
