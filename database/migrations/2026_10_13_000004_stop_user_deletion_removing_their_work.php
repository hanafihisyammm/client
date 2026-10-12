<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links from a user to the work they created. Deleting the user used to delete
     * that work too; now the database refuses, and people are deactivated instead.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $links = [
        ['reports', 'reported_by'],
        ['report_comments', 'user_id'],
        ['report_activity_logs', 'user_id'],
        ['time_entries', 'user_id'],
        ['projects', 'created_by'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->relink('restrict');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->relink('cascade');
    }

    private function relink(string $onDelete): void
    {
        foreach ($this->links as [$tableName, $column]) {
            Schema::table($tableName, function (Blueprint $table) use ($column, $onDelete) {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on('users')->onDelete($onDelete);
            });
        }
    }
};
