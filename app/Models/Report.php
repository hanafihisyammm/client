<?php

namespace App\Models;

use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Report extends Model
{
    /**
     * Statuses where no further work is expected on a report.
     */
    public const FINISHED_STATUSES = ['approved', 'closed'];

    protected $fillable = [
        'title',
        'description',
        'project_id',
        'status',
        'reported_by',
        'assigned_to',
        'resolution_summary',
        'closed_at',
        'uuid',
        'due_date',
        'priority',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($report) {
            if (empty($report->uuid)) {
                $report->uuid = Str::uuid();
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReportAttachment::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ReportActivityLog::class)->orderBy('created_at', 'desc');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ReportComment::class)->orderBy('created_at', 'asc');
    }

    public function canBeAssigned(): bool
    {
        return $this->status === 'unassigned';
    }

    public function canBeClosedByClient(): bool
    {
        return $this->status === 'ready_for_client_confirmation';
    }

    public function canBeReopened(): bool
    {
        return $this->status === 'closed';
    }

    public function assignTo(User $user): void
    {
        $oldStatus = $this->status;
        
        $this->update([
            'assigned_to' => $user->id,
            'status' => 'in_progress',
        ]);

        $this->logActivity('assigned', $user, ['assigned_to' => $user->id, 'status' => 'in_progress']);
        
        // Send notification for status change
        $this->load('project');
        app(NotificationService::class)->notifyReportStatusChanged($this, $user, $oldStatus, 'in_progress');
    }

    public function markReadyForClientConfirmation(User $user, string $resolutionSummary): void
    {
        $oldStatus = $this->status;
        $oldValues = ['status' => $this->status, 'resolution_summary' => $this->resolution_summary];
        $newValues = ['status' => 'ready_for_client_confirmation', 'resolution_summary' => $resolutionSummary];

        $this->update([
            'status' => 'ready_for_client_confirmation',
            'resolution_summary' => $resolutionSummary,
        ]);

        $this->logActivity('marked_ready_for_confirmation', $user, $newValues, $oldValues);
        
        // Send notification for status change
        $this->load('project');
        app(NotificationService::class)->notifyReportStatusChanged($this, $user, $oldStatus, 'ready_for_client_confirmation');
    }

    public function close(User $user): void
    {
        $oldStatus = $this->status;
        $oldValues = ['status' => $this->status, 'closed_at' => $this->closed_at];
        $newValues = ['status' => 'closed', 'closed_at' => now()];

        $this->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->logActivity('closed', $user, $newValues, $oldValues);
        
        // Send notification for status change
        $this->load('project');
        app(NotificationService::class)->notifyReportStatusChanged($this, $user, $oldStatus, 'closed');
    }

    public function reopen(User $user): void
    {
        $oldStatus = $this->status;
        $oldValues = ['status' => $this->status, 'closed_at' => $this->closed_at];
        $newValues = ['status' => 'reopened', 'closed_at' => null];

        $this->update([
            'status' => 'reopened',
            'closed_at' => null,
        ]);

        $this->logActivity('reopened', $user, $newValues, $oldValues);
        
        // Send notification for status change
        $this->load('project');
        app(NotificationService::class)->notifyReportStatusChanged($this, $user, $oldStatus, 'reopened');
    }

    private function logActivity(string $action, User $user, ?array $newValues = null, ?array $oldValues = null): void
    {
        ReportActivityLog::create([
            'report_id' => $this->id,
            'user_id' => $user->id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
