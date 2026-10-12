<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Demo data is for a developer's own machine only. Anywhere else, only the
     * super admin account is created and nothing existing is touched.
     */
    public function run(): void
    {
        if (DemoDataSeeder::isSafeHere()) {
            $this->call(DemoDataSeeder::class);

            return;
        }

        $this->command?->warn('Not a local database: demo data skipped, only the super admin is ensured.');
        $this->call(SuperAdminSeeder::class);
    }
}
