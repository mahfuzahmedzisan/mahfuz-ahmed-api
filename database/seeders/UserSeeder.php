<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@dev.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('admin@dev.com'),
                'email_verified_at' => now(),
                'role' => UserRole::Admin,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'user@dev.com'],
            [
                'name' => 'User',
                'password' => Hash::make('user@dev.com'),
                'email_verified_at' => now(),
                'role' => UserRole::User,
            ],
        );
    }
}
