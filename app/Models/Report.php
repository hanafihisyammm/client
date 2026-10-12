<?php

namespace App\Models;

use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Report extends Model
{
    // Available statuses for Jira-like workflow
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_REOPENED = 'reopened';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
        self::STATUS_REOPENED,
    ];

    // Status labels for display
    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_RESOLVED => 'Resolved',
        self::STATUS_CLOSED => 'Closed',
        self::STATUS_REOPENED => 'Reopened',
    ];

    // Valid status transitions
    public const STATUS_TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_IN_PROGRESS, self::STATUS_CLOSED],
        self::STATUS_IN_PROGRESS => [self::STATUS_RESOLVED, self::STATUS_OPEN],
        self::STATUS_RESOLVED => [self::STATUS_CLOSED, self::STATUS_REOPENED, self::STATUS_IN_PROGRESS],
        self::STATUS_CLOSED => [self::STATUS_REOPENED],
        self::STATUS_REOPENED => [self::STATUS_IN_PROGRESS, self::STATUS_CLOSED],
    ];

    // SLA Targets by Priority (in hours)
    public const SLA_TARGETS = [
        'urgent' => ['response' => 1, 'resolution' => 4],
        'high' => ['response' => 4, 'resolution' => 24],
        'medium' => ['response' => 8, 'resolution' => 48],
        'low' => ['response' => 24, 'resolution' => 168], // 1 week
    ];

    /**
     * Statuses where no further work is expected on a report.
     */
    public const FINISHED_STATUSES = [self::STATUS_RESOLVED, self::STATUS_CLOSED];

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
        'issue_key',
        'due_date',
        'priority',
        'severity',
        'estimated_hours',
        'first_response_at',
        'resolved_at',
        'response_sla_breached',
        'resolution_sla_breached',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($report) {
            if (empty($report->uuid)) {
                $report->uuid = Str::uuid();
            }

            // Generate issue key from project
            if (empty($report->issue_key) && $report->project_id) {
                $report->issue_key = $report->generateIssueKey();
            }

            // Default status is open
            if (empty($report->status)) {
                $report->status = self::STATUS_OPEN;
            }
        });
    }

    /**
     * Generate issue key for this report
     */
    public function generateIssueKey(): ?string
    {
        $project = Project::find($this->project_id);
        if (!$project) {
            return null;
        }

        return $project->getNextIssueKey();
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
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'response_sla_breached' => 'boolean',
            'resolution_sla_breached' => 'boolean',
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

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'report_labels')
            ->withTimestamps();
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class)->orderBy('logged_date', 'desc');
    }

    public function getTotalTimeSpent(): float
    {
        return $this->timeEntries()->sum('hours');
    }

    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(ReportLink::class, 'source_report_id');
    }

    public function incomingLinks(): HasMany
    {
        return $this->hasMany(ReportLink::class, 'target_report_id');
    }

    public function linkedReports()
    {
        $outgoing = $this->outgoingLinks()->with('targetReport')->get();
        $incoming = $this->incomingLinks()->with('sourceReport')->get();

        return [
            'outgoing' => $outgoing,
            'incoming' => $incoming,
        ];
    }

    /**
     * Check if report can transition to the given status
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowedTransitions = self::STATUS_TRANSITIONS[$this->status] ?? [];
        return in_array($newStatus, $allowedTransitions);
    }

    /**
     * Get valid next statuses for this report
     */
    public function getValidTransitions(): array
    {
        return self::STATUS_TRANSITIONS[$this->status] ?? [];
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Check if the report is open (can be picked up)
     */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_REOPENED]);
    }

    /**
     * Check if report is resolved (waiting for client confirmation)
     */
    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /**
     * Check if report is closed
     */
    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Transition to a new status
     */
    public function transitionTo(string $newStatus, User $user, ?string $resolutionSummary = null, ?string $reason = null): bool
    {
        if (!$this->canTransitionTo($newStatus)) {
            return false;
        }

        $oldStatus = $this->status;
        $oldValues = ['status' => $this->status];
        $newValues = ['status' => $newStatus];

        $updateData = ['status' => $newStatus];

        // Handle special cases
        if ($newStatus === self::STATUS_RESOLVED && $resolutionSummary) {
            $updateData['resolution_summary'] = $resolutionSummary;
            $oldValues['resolution_summary'] = $this->resolution_summary;
            $newValues['resolution_summary'] = $resolutionSummary;
        }

        if ($newStatus === self::STATUS_CLOSED) {
            $updateData['closed_at'] = now();
            $oldValues['closed_at'] = $this->closed_at;
            $newValues['closed_at'] = now();
        }

        if ($newStatus === self::STATUS_REOPENED) {
            $updateData['closed_at'] = null;
            $oldValues['closed_at'] = $this->closed_at;
            $newValues['closed_at'] = null;
        }

        // Keep the reason given for a reopen with the activity entry
        if ($reason) {
            $newValues['reason'] = $reason;
        }

        $this->update($updateData);

        $this->logActivity('status_changed', $user, $newValues, $oldValues);

        // Send notification for status change, with the reopen reason or resolution summary
        $this->load('project');
        app(NotificationService::class)->notifyReportStatusChanged(
            $this,
            $user,
            $oldStatus,
            $newStatus,
            $reason ?? ($newStatus === self::STATUS_RESOLVED ? $resolutionSummary : null)
        );

        return true;
    }

    /**
     * Assign report to a user and start progress
     */
    public function assignTo(User $user): void
    {
        $oldStatus = $this->status;

        $this->update([
            'assigned_to' => $user->id,
            'status' => self::STATUS_IN_PROGRESS,
        ]);

        $this->logActivity('assigned', $user, [
            'assigned_to' => $user->id,
            'status' => self::STATUS_IN_PROGRESS
        ]);

        // Send notification for status change
        $this->load('project');
        app(NotificationService::class)->notifyReportStatusChanged($this, $user, $oldStatus, self::STATUS_IN_PROGRESS);
    }

    /**
     * Mark report as resolved
     */
    public function resolve(User $user, string $resolutionSummary): void
    {
        $this->transitionTo(self::STATUS_RESOLVED, $user, $resolutionSummary);
    }

    /**
     * Close the report
     */
    public function close(User $user): void
    {
        $this->transitionTo(self::STATUS_CLOSED, $user);
    }

    /**
     * Reopen the report
     */
    public function reopen(User $user, ?string $reason = null): void
    {
        $this->transitionTo(self::STATUS_REOPENED, $user, null, $reason);
    }

    /**
     * Start working on the report
     */
    public function startProgress(User $user): void
    {
        $this->transitionTo(self::STATUS_IN_PROGRESS, $user);
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

    // ==================== SLA Methods ====================

    /**
     * Get SLA targets for this report based on priority
     */
    public function getSlaTargets(): array
    {
        return self::SLA_TARGETS[$this->priority] ?? self::SLA_TARGETS['medium'];
    }

    /**
     * Record first response (when team member first responds)
     */
    public function recordFirstResponse(): void
    {
        if ($this->first_response_at) {
            return; // Already recorded
        }

        $this->update([
            'first_response_at' => now(),
            'response_sla_breached' => $this->isResponseSlaBreached(),
        ]);
    }

    /**
     * Record resolution time
     */
    public function recordResolution(): void
    {
        $this->update([
            'resolved_at' => now(),
            'resolution_sla_breached' => $this->isResolutionSlaBreached(),
        ]);
    }

    /**
     * Check if response SLA is breached
     */
    public function isResponseSlaBreached(): bool
    {
        $targets = $this->getSlaTargets();
        $deadline = $this->created_at->addHours($targets['response']);

        if ($this->first_response_at) {
            return $this->first_response_at->gt($deadline);
        }

        return now()->gt($deadline);
    }

    /**
     * Check if resolution SLA is breached
     */
    public function isResolutionSlaBreached(): bool
    {
        if ($this->isClosed()) {
            return false; // Closed issues don't have active SLA
        }

        $targets = $this->getSlaTargets();
        $deadline = $this->created_at->addHours($targets['resolution']);

        if ($this->resolved_at) {
            return $this->resolved_at->gt($deadline);
        }

        return now()->gt($deadline);
    }

    /**
     * Get response SLA status
     * Returns: 'breached', 'at_risk', 'on_track', 'met'
     */
    public function getResponseSlaStatus(): string
    {
        $targets = $this->getSlaTargets();
        $deadline = $this->created_at->addHours($targets['response']);

        // If already responded
        if ($this->first_response_at) {
            return $this->first_response_at->gt($deadline) ? 'breached' : 'met';
        }

        // Check current status
        $now = now();
        if ($now->gt($deadline)) {
            return 'breached';
        }

        // At risk if less than 25% time remaining
        $totalHours = $targets['response'];
        $hoursRemaining = $now->diffInMinutes($deadline) / 60;
        if ($hoursRemaining < ($totalHours * 0.25)) {
            return 'at_risk';
        }

        return 'on_track';
    }

    /**
     * Get resolution SLA status
     * Returns: 'breached', 'at_risk', 'on_track', 'met', 'paused'
     */
    public function getResolutionSlaStatus(): string
    {
        // Closed issues - SLA complete
        if ($this->isClosed()) {
            return $this->resolution_sla_breached ? 'breached' : 'met';
        }

        // Resolved issues - waiting for close
        if ($this->isResolved()) {
            return 'paused';
        }

        $targets = $this->getSlaTargets();
        $deadline = $this->created_at->addHours($targets['resolution']);

        $now = now();
        if ($now->gt($deadline)) {
            return 'breached';
        }

        // At risk if less than 25% time remaining
        $totalHours = $targets['resolution'];
        $hoursRemaining = $now->diffInMinutes($deadline) / 60;
        if ($hoursRemaining < ($totalHours * 0.25)) {
            return 'at_risk';
        }

        return 'on_track';
    }

    /**
     * Get time remaining for response SLA (in minutes, negative if breached)
     */
    public function getResponseTimeRemaining(): ?int
    {
        if ($this->first_response_at) {
            return null; // Already responded
        }

        $targets = $this->getSlaTargets();
        $deadline = $this->created_at->addHours($targets['response']);

        return now()->diffInMinutes($deadline, false);
    }

    /**
     * Get time remaining for resolution SLA (in minutes, negative if breached)
     */
    public function getResolutionTimeRemaining(): ?int
    {
        if ($this->isClosed()) {
            return null; // Already resolved
        }

        if ($this->isResolved()) {
            return null; // Paused - waiting for close
        }

        $targets = $this->getSlaTargets();
        $deadline = $this->created_at->addHours($targets['resolution']);

        return now()->diffInMinutes($deadline, false);
    }

    /**
     * Get complete SLA information for API response
     */
    public function getSlaInfo(): array
    {
        $targets = $this->getSlaTargets();

        return [
            'targets' => [
                'response_hours' => $targets['response'],
                'resolution_hours' => $targets['resolution'],
            ],
            'response' => [
                'status' => $this->getResponseSlaStatus(),
                'responded_at' => $this->first_response_at?->toIso8601String(),
                'deadline' => $this->created_at->addHours($targets['response'])->toIso8601String(),
                'time_remaining_minutes' => $this->getResponseTimeRemaining(),
                'breached' => $this->response_sla_breached,
            ],
            'resolution' => [
                'status' => $this->getResolutionSlaStatus(),
                'resolved_at' => $this->resolved_at?->toIso8601String(),
                'deadline' => $this->created_at->addHours($targets['resolution'])->toIso8601String(),
                'time_remaining_minutes' => $this->getResolutionTimeRemaining(),
                'breached' => $this->resolution_sla_breached,
            ],
        ];
    }

    /**
     * Update SLA breach status (can be called periodically)
     */
    public function updateSlaBreachStatus(): void
    {
        $updates = [];

        if (!$this->first_response_at && $this->isResponseSlaBreached()) {
            $updates['response_sla_breached'] = true;
        }

        if (!$this->isClosed() && !$this->isResolved() && $this->isResolutionSlaBreached()) {
            $updates['resolution_sla_breached'] = true;
        }

        if (!empty($updates)) {
            $this->update($updates);
        }
    }
}
