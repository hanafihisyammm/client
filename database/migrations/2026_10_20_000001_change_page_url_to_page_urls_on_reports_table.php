<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An issue can happen on more than one page, so it keeps a list of page URLs instead of one.
     * A URL already given becomes the first and only entry of the list.
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->jsonb('page_urls')->nullable()->after('description');
        });

        DB::statement('UPDATE reports SET page_urls = jsonb_build_array(page_url) WHERE page_url IS NOT NULL');

        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('page_url');
        });
    }

    /** Going back keeps the first URL of each list. */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('page_url', 2048)->nullable()->after('description');
        });

        DB::statement("UPDATE reports SET page_url = page_urls->>0 WHERE page_urls IS NOT NULL");

        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('page_urls');
        });
    }
};
