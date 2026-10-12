<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Kept as text so the entry still reads correctly after the user is deleted
            $table->string('user_name')->nullable();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->text('description');
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['subject_type', 'subject_id']);
        });

        // Carry over the existing report history so the audit log does not start empty
        DB::table('report_activity_logs')
            ->join('reports', 'reports.id', '=', 'report_activity_logs.report_id')
            ->leftJoin('users', 'users.id', '=', 'report_activity_logs.user_id')
            ->select('report_activity_logs.*', 'reports.title as report_title', 'users.name as user_name')
            ->orderBy('report_activity_logs.id')
            ->chunk(500, function ($logs) {
                $rows = [];

                foreach ($logs as $log) {
                    $old = json_decode($log->old_values ?? 'null', true) ?: [];
                    $new = json_decode($log->new_values ?? 'null', true) ?: [];
                    $changes = [];

                    foreach ($new as $field => $value) {
                        if (($old[$field] ?? null) !== $value) {
                            $changes[$field] = ['old' => $old[$field] ?? null, 'new' => $value];
                        }
                    }

                    $rows[] = [
                        'user_id' => $log->user_id,
                        'user_name' => $log->user_name,
                        'action' => 'report.' . $log->action,
                        'subject_type' => 'report',
                        'subject_id' => $log->report_id,
                        'subject_label' => $log->report_title,
                        'description' => ucfirst(str_replace('_', ' ', $log->action)) . ' report "' . $log->report_title . '"',
                        'changes' => $changes ? json_encode($changes) : null,
                        'ip_address' => null,
                        'created_at' => $log->created_at,
                    ];
                }

                DB::table('audit_logs')->insert($rows);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
