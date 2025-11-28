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
        // Drop the existing status column with constraint
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('status');
        });
        
        // Add new status column with updated enum values
        Schema::table('reports', function (Blueprint $table) {
            $table->enum('status', ['pending', 'in_progress', 'submit_for_review', 'approved', 'rejected'])
                  ->default('pending')
                  ->after('project');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the new status column
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('status');
        });
        
        // Restore original status column
        Schema::table('reports', function (Blueprint $table) {
            $table->enum('status', ['unassigned', 'in_progress', 'ready_for_client_confirmation', 'closed', 'reopened'])
                  ->default('unassigned')
                  ->after('project');
        });
    }
};
