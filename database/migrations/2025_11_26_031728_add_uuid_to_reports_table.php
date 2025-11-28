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
        Schema::table('reports', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->index('uuid');
        });
        
        // Generate UUIDs for existing reports
        DB::statement("UPDATE reports SET uuid = gen_random_uuid() WHERE uuid IS NULL");
        
        // Make the column non-nullable and unique
        Schema::table('reports', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->unique('uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
