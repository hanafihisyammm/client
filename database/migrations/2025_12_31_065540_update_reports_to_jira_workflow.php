<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Migrate from old statuses to simplified Jira-like workflow:
     * - Old: pending, in_progress, submit_for_review, approved, rejected, closed
     * - New: open, in_progress, resolved, closed, reopened
     */
    public function up(): void
    {
        // Add issue_key column for Jira-style keys (PROJ-123)
        Schema::table('reports', function (Blueprint $table) {
            $table->string('issue_key')->nullable()->unique()->after('uuid');
        });

        // Drop existing check constraint
        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_status_check');

        // Migrate existing statuses to new workflow
        DB::table('reports')->where('status', 'pending')->update(['status' => 'open']);
        DB::table('reports')->where('status', 'unassigned')->update(['status' => 'open']);
        DB::table('reports')->where('status', 'submit_for_review')->update(['status' => 'resolved']);
        DB::table('reports')->where('status', 'ready_for_client_confirmation')->update(['status' => 'resolved']);
        DB::table('reports')->where('status', 'approved')->update(['status' => 'closed']);
        DB::table('reports')->where('status', 'rejected')->update(['status' => 'reopened']);
        // 'in_progress', 'closed', 'reopened' remain the same

        // Add new check constraint with simplified statuses
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_check CHECK (status IN ('open', 'in_progress', 'resolved', 'closed', 'reopened'))");

        // Generate issue keys for existing reports
        $this->generateIssueKeys();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop new check constraint
        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_status_check');

        // Reverse status migrations
        DB::table('reports')->where('status', 'open')->update(['status' => 'pending']);
        DB::table('reports')->where('status', 'resolved')->update(['status' => 'submit_for_review']);
        // Note: some data may be lost in reverse since approved/rejected both map from closed/reopened

        // Restore old check constraint
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_check CHECK (status IN ('pending', 'in_progress', 'submit_for_review', 'approved', 'rejected', 'closed'))");

        // Remove issue_key column
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('issue_key');
        });
    }

    /**
     * Generate issue keys for existing reports based on project prefix
     */
    private function generateIssueKeys(): void
    {
        $projects = DB::table('projects')->get();

        foreach ($projects as $project) {
            // Create prefix from project name (first 3-4 letters, uppercase)
            $prefix = $this->generatePrefix($project->name);

            // Get all reports for this project ordered by creation
            $reports = DB::table('reports')
                ->where('project_id', $project->id)
                ->orderBy('created_at', 'asc')
                ->get();

            $counter = 1;
            foreach ($reports as $report) {
                $issueKey = $prefix . '-' . $counter;
                DB::table('reports')
                    ->where('id', $report->id)
                    ->update(['issue_key' => $issueKey]);
                $counter++;
            }
        }
    }

    /**
     * Generate a project prefix from the name
     */
    private function generatePrefix(string $name): string
    {
        // Remove special characters and split into words
        $words = preg_split('/[\s\-_]+/', preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name));

        if (count($words) === 1) {
            // Single word: take first 3-4 characters
            return strtoupper(substr($words[0], 0, 4));
        }

        // Multiple words: take first letter of each word (up to 4)
        $prefix = '';
        foreach (array_slice($words, 0, 4) as $word) {
            if (!empty($word)) {
                $prefix .= strtoupper($word[0]);
            }
        }

        return $prefix ?: 'PROJ';
    }
};
