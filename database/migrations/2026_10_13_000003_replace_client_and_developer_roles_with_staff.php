<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The final roles are super_admin, admin, manager and staff. Existing client and
     * developer accounts are kept and become staff; nothing is deleted.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');

        DB::table('users')->whereIn('role', ['client', 'developer'])->update(['role' => 'staff']);

        DB::statement("ALTER TABLE users ALTER COLUMN role SET DEFAULT 'staff'");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'admin', 'manager', 'staff'))");
    }

    /**
     * Reverse the migrations.
     *
     * Which staff used to be clients and which developers is not recorded, so all become clients.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');

        DB::table('users')->where('role', 'staff')->update(['role' => 'client']);

        DB::statement("ALTER TABLE users ALTER COLUMN role SET DEFAULT 'client'");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'admin', 'manager', 'developer', 'client'))");
    }
};
