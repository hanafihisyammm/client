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
        // First, update existing data to match new status values
        DB::table('reports')
            ->where('status', 'unassigned')
            ->update(['status' => 'pending']);
            
        DB::table('reports')
            ->where('status', 'ready_for_client_confirmation')
            ->update(['status' => 'submit_for_review']);
            
        DB::table('reports')
            ->where('status', 'closed')
            ->update(['status' => 'approved']);
            
        DB::table('reports')
            ->where('status', 'reopened')
            ->update(['status' => 'rejected']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse the status updates
        DB::table('reports')
            ->where('status', 'pending')
            ->update(['status' => 'unassigned']);
            
        DB::table('reports')
            ->where('status', 'submit_for_review')
            ->update(['status' => 'ready_for_client_confirmation']);
            
        DB::table('reports')
            ->where('status', 'approved')
            ->update(['status' => 'closed']);
            
        DB::table('reports')
            ->where('status', 'rejected')
            ->update(['status' => 'reopened']);
    }
};
