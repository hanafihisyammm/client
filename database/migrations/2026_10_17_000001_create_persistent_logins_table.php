<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Remember me", one row per signed-in browser or installed app. Only hashes of the
     * secret in the browser's cookie are kept, so the table alone cannot sign anyone in.
     */
    public function up(): void
    {
        Schema::create('persistent_logins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Finds the row; the secret beside it in the cookie proves it
            $table->string('selector', 32)->unique();
            $table->string('token_hash', 64);
            $table->timestamp('token_issued_at');
            // A new secret sent to the browser but not yet seen back from it
            $table->string('pending_token_hash', 64)->nullable();
            $table->timestamp('pending_issued_at')->nullable();
            // The secret just replaced, still accepted for a moment from requests already on their way
            $table->string('previous_token_hash', 64)->nullable();
            $table->timestamp('previous_valid_until')->nullable();
            $table->timestamp('last_used_at');
            $table->timestamp('expires_at')->index();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persistent_logins');
    }
};
