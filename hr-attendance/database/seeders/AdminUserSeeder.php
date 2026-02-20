<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'ceo@example.com'],
            [
                'name'     => 'CEO',
                'role'     => 'ceo',
                'password' => Hash::make('password'),
            ]
        );

        // Keep the old admin account but set role
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'     => 'Admin',
                'role'     => 'admin',
                'password' => Hash::make('password'),
            ]
        );
    }
}
