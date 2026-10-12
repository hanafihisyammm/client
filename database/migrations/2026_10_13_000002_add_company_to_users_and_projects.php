<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Empty for super admins and admins, who work across every company
            $table->foreignId('company_id')->nullable()->after('role')->constrained('companies')->nullOnDelete();
            // Deactivated users keep their history but can no longer log in
            $table->timestamp('deactivated_at')->nullable()->after('company_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
        });

        Schema::table('reports', function (Blueprint $table) {
            // Who marked the issue resolved; they may not confirm their own fix
            $table->foreignId('resolved_by')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('deactivated_at');
        });
    }
};
