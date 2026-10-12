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
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->string('name');
            $table->string('color', 7)->default('#6366f1'); // Hex color code
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::create('report_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->onDelete('cascade');
            $table->foreignId('label_id')->constrained('labels')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['report_id', 'label_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_labels');
        Schema::dropIfExists('labels');
    }
};
