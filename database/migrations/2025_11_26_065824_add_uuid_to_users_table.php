<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->index('uuid');
        });

        // Generate UUIDs for existing users
        DB::table('users')->whereNull('uuid')->get()->each(function ($user) {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['uuid' => Str::uuid()]);
        });

        // Make uuid column required after populating existing records
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->unique('uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['uuid']);
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};