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
        // First, update any existing developer users to admin
        DB::table('users')->where('role', 'developer')->update(['role' => 'admin']);
        
        // Drop the existing constraint
        DB::statement("ALTER TABLE users DROP CONSTRAINT users_role_check");
        
        // Add new constraint without developer role
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'admin', 'client'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the existing constraint
        DB::statement("ALTER TABLE users DROP CONSTRAINT users_role_check");
        
        // Add constraint with developer role back
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'admin', 'developer', 'client'))");
    }
};
