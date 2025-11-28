<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the existing check constraint
        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_status_check');
        
        // Add the new check constraint with 'closed' status included
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_check CHECK (status IN ('pending', 'in_progress', 'submit_for_review', 'approved', 'rejected', 'closed'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the updated check constraint
        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_status_check');
        
        // Restore the original check constraint without 'closed'
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_check CHECK (status IN ('pending', 'in_progress', 'submit_for_review', 'approved', 'rejected'))");
    }
};
