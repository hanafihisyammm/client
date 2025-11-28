<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('uuid')->after('id')->nullable();
        });

        // Generate UUIDs for existing projects
        DB::table('projects')->whereNull('uuid')->get()->each(function ($project) {
            DB::table('projects')
                ->where('id', $project->id)
                ->update(['uuid' => Str::uuid()]);
        });

        // Now make the uuid column unique and not null
        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
