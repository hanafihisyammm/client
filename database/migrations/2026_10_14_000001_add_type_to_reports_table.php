<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember whether an issue is a bug or a feature request.
     * Severity only describes bugs, so it becomes optional and stays empty for features.
     * Issues created before this are kept as bugs with the severity they already have.
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('type', 20)->default('bug')->after('description');
        });

        DB::statement('ALTER TABLE reports ALTER COLUMN severity DROP NOT NULL');
    }

    public function down(): void
    {
        DB::table('reports')->whereNull('severity')->update(['severity' => 'minor']);
        DB::statement('ALTER TABLE reports ALTER COLUMN severity SET NOT NULL');

        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
