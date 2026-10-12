<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('prefix', 10)->nullable()->unique()->after('name');
            $table->unsignedInteger('next_issue_number')->default(1)->after('prefix');
        });

        // Generate prefixes for existing projects
        $this->generatePrefixes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['prefix', 'next_issue_number']);
        });
    }

    /**
     * Generate prefixes for existing projects
     */
    private function generatePrefixes(): void
    {
        $projects = DB::table('projects')->get();
        $usedPrefixes = [];

        foreach ($projects as $project) {
            $prefix = $this->generateUniquePrefix($project->name, $usedPrefixes);
            $usedPrefixes[] = $prefix;

            // Count existing reports to set next_issue_number
            $reportCount = DB::table('reports')
                ->where('project_id', $project->id)
                ->count();

            DB::table('projects')
                ->where('id', $project->id)
                ->update([
                    'prefix' => $prefix,
                    'next_issue_number' => $reportCount + 1,
                ]);
        }
    }

    /**
     * Generate a unique project prefix from the name
     */
    private function generateUniquePrefix(string $name, array $usedPrefixes): string
    {
        $words = preg_split('/[\s\-_]+/', preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name));

        if (count($words) === 1) {
            $basePrefix = strtoupper(substr($words[0], 0, 4));
        } else {
            $basePrefix = '';
            foreach (array_slice($words, 0, 4) as $word) {
                if (!empty($word)) {
                    $basePrefix .= strtoupper($word[0]);
                }
            }
        }

        $prefix = $basePrefix ?: 'PROJ';

        // Make unique if already used
        $counter = 1;
        $uniquePrefix = $prefix;
        while (in_array($uniquePrefix, $usedPrefixes)) {
            $uniquePrefix = $prefix . $counter;
            $counter++;
        }

        return $uniquePrefix;
    }
};
