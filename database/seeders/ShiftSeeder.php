<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            [
                'name'         => 'Shift A (10:00-19:00)',
                'start_time'   => '10:00:00',
                'end_time'     => '19:00:00',
                'lunch_start'  => '14:00:00',
                'lunch_end'    => '15:00:00',
                'required_work_minutes' => 480,
                'grace_in_minutes'  => 0,
                'grace_out_minutes' => 0,
                'lunch_inference_window_before_minutes' => 0,
                'lunch_inference_window_after_minutes'  => 60,
            ],
            [
                'name'         => 'Shift B (10:30-19:30)',
                'start_time'   => '10:30:00',
                'end_time'     => '19:30:00',
                'lunch_start'  => '14:30:00',
                'lunch_end'    => '15:30:00',
                'required_work_minutes' => 480,
                'grace_in_minutes'  => 0,
                'grace_out_minutes' => 0,
                'lunch_inference_window_before_minutes' => 0,
                'lunch_inference_window_after_minutes'  => 60,
            ],
            [
                'name'         => 'Shift C (09:30-18:30)',
                'start_time'   => '09:30:00',
                'end_time'     => '18:30:00',
                'lunch_start'  => '13:30:00',
                'lunch_end'    => '14:30:00',
                'required_work_minutes' => 480,
                'grace_in_minutes'  => 0,
                'grace_out_minutes' => 0,
                'lunch_inference_window_before_minutes' => 0,
                'lunch_inference_window_after_minutes'  => 60,
            ],
        ];

        foreach ($shifts as $shift) {
            Shift::updateOrCreate(['name' => $shift['name']], $shift);
        }
    }
}
