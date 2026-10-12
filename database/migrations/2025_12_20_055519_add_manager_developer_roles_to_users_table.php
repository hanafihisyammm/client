<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the existing constraint
        DB::statement("ALTER TABLE users DROP CONSTRAINT users_role_check");

        // Add new constraint with manager and developer roles
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'admin', 'manager', 'developer', 'client'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First, update any manager/developer users to admin
        DB::table('users')->whereIn('role', ['manager', 'developer'])->update(['role' => 'admin']);

        // Drop the existing constraint
        DB::statement("ALTER TABLE users DROP CONSTRAINT users_role_check");

        // Add constraint without manager and developer roles
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'admin', 'client'))");
    }
};
