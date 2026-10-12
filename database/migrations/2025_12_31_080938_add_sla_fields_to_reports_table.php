<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * SLA Targets by Priority (in hours):
     * - Urgent: Response 1h, Resolution 4h
     * - High: Response 4h, Resolution 24h
     * - Medium: Response 8h, Resolution 48h
     * - Low: Response 24h, Resolution 168h (1 week)
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // Timestamp when first team member responds (comment or status change)
            $table->timestamp('first_response_at')->nullable()->after('resolution_summary');

            // Timestamp when issue was resolved
            $table->timestamp('resolved_at')->nullable()->after('first_response_at');

            // SLA breach flags for quick filtering
            $table->boolean('response_sla_breached')->default(false)->after('resolved_at');
            $table->boolean('resolution_sla_breached')->default(false)->after('response_sla_breached');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn([
                'first_response_at',
                'resolved_at',
                'response_sla_breached',
                'resolution_sla_breached',
            ]);
        });
    }
};
