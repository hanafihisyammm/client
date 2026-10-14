<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Images posted with a comment are attachments of the issue that belong to that comment.
     * Attachments of the issue itself leave it empty.
     */
    public function up(): void
    {
        Schema::table('report_attachments', function (Blueprint $table) {
            $table->foreignId('report_comment_id')->nullable()->after('report_id')
                ->constrained('report_comments')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('report_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('report_comment_id');
        });
    }
};
