<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some activity is done by the app itself, such as closing a resolved issue nobody reviewed.
     * Those entries have no user.
     */
    public function up(): void
    {
        Schema::table('report_activity_logs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('report_activity_logs')->whereNull('user_id')->delete();

        Schema::table('report_activity_logs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
