<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The theme each user chose: "light", "dark" or "system".
     * Empty until they choose, which means "system".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('appearance', 10)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('appearance');
        });
    }
};
