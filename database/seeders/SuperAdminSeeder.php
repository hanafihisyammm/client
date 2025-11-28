<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create super admin if it doesn't exist
        if (!User::where('role', 'super_admin')->exists()) {
            User::create([
                'name' => 'Super Admin',
                'email' => 'admin@sidracore.com',
                'email_verified_at' => now(),
                'password' => Hash::make('password123'),
                'role' => 'super_admin',
            ]);

            $this->command->info('Super admin created successfully!');
            $this->command->info('Email: admin@sidracore.com');
            $this->command->info('Password: password123');
        } else {
            $this->command->info('Super admin already exists!');
        }
    }
}
