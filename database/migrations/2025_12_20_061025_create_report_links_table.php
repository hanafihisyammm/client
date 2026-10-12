<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('report_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_report_id')->constrained('reports')->onDelete('cascade');
            $table->foreignId('target_report_id')->constrained('reports')->onDelete('cascade');
            $table->enum('link_type', ['duplicates', 'is_duplicated_by', 'blocks', 'is_blocked_by', 'relates_to']);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['source_report_id', 'target_report_id', 'link_type']);
            $table->index('target_report_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_links');
    }
};
