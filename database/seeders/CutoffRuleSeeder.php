<?php

namespace Database\Seeders;

use App\Models\CutoffRule;
use Illuminate\Database\Seeder;

class CutoffRuleSeeder extends Seeder
{
    public function run(): void
    {
        CutoffRule::updateOrCreate(
            ['name' => '11-25'],
            [
                'type'      => 'fixed_day_ranges',
                'rule_json' => [
                    'ranges' => [
                        ['start_day' => 11, 'end_day' => 25, 'cross_month' => false],
                    ],
                ],
            ]
        );

        CutoffRule::updateOrCreate(
            ['name' => '26-10'],
            [
                'type'      => 'fixed_day_ranges',
                'rule_json' => [
                    'ranges' => [
                        ['start_day' => 26, 'end_day' => 10, 'cross_month' => true],
                    ],
                ],
            ]
        );
    }
}
