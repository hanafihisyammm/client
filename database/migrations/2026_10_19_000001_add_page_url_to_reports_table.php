<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember the address of the page an issue was seen on, so it can be found and debugged.
     * New issues must give one; issues created before this have none.
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('page_url', 2048)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('page_url');
        });
    }
};
