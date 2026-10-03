<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProductionAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.test'],
            [
                'name' => 'System Administrator',
                'password' => 'demo-password-123',
                'role' => Role::Admin,
                'status' => UserStatus::Active,
                'failed_login_count' => 0,
                'locked_until' => null,
            ]
        );
    }
}