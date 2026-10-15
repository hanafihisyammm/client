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

    public const TYPE_BUG = 'bug';
    public const TYPE_FEATURE = 'feature';
    public const TYPES = [self::TYPE_BUG, self::TYPE_FEATURE];

    /**
     * Statuses where the team's work is done, so a report no longer counts as overdue.
     * Resolved is not final: it is waiting for the client to review and confirm.
     */
    public const FINISHED_STATUSES = [self::STATUS_RESOLVED, self::STATUS_CLOSED];

    /**
     * Left over from when issues had an assignee; kept in the database but never sent to
     * a page or the API. Issues belong to projects, not to individual people.
     */
    protected $hidden = [
        'assigned_to',
    ];

    protected $fillable = [
        'title',
        'description',
        'page_urls',
        'project_id',
        'status',
        'reported_by',
        'resolved_by',
        'resolution_summary',
        'closed_at',
        'uuid',
        'issue_key',
        'due_date',
        'priority',
        'type',
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
            'page_urls' => 'array',
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

    /** Files attached to the issue itself; images posted with a comment belong to that comment */
    public function attachments(): HasMany
    {
        return $this->hasMany(ReportAttachment::class)->whereNull('report_comment_id');
    }

    /** Every file stored for the issue, including the images posted with its comments */
    public function allAttachments(): HasMany
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
     * Limit a query to the reports this user may see. Admins see everything. A manager
     * sees their company's reports. Staff see reports in their company that are in a
     * project they are assigned to, or that they raised.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isAdmin()) {
            return $query;
        }

        // Managers and staff with no company see nothing
        if (!$user->company_id) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereHas('project', fn ($project) => $project->where('company_id', $user->company_id));

        if ($user->isManager()) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->whereIn('reports.project_id', $user->projectIds())
                ->orWhere('reports.reported_by', $user->id);
        });
    }

    /**
     * Whether this user may open this report (same rule as scopeVisibleTo).
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (!$this->belongsToCompanyOf($user)) {
            return false;
        }

        return $user->isManager()
            || $this->reported_by === $user->id
            || $user->projectIds()->contains($this->project_id);
    }

    /**
     * What this user may do with the report right now. One place decides it, so the
     * buttons on the page and the checks on the server can never disagree.
     *
     * @return array<string, bool>
     */
    public function abilitiesFor(User $user): array
    {
        if (!$this->isVisibleTo($user)) {
            return array_fill_keys(
                ['view', 'start_progress', 'resolve', 'reopen_work', 'confirm', 'close', 'reopen', 'edit', 'comment', 'delete'],
                false
            );
        }

        $isAdmin = $user->isAdmin();
        $isManager = $user->isManager();
        $isReporter = $this->reported_by === $user->id;

        // Anyone who can see the report may work on it: admins, the manager, and staff on the project
        $canWork = true;
        // Confirming, closing and reopening is for the reporter, the manager, or an admin
        $canDecide = $isAdmin || $isManager || $isReporter;

        return [
            'view' => true,
            'start_progress' => $canWork && in_array($this->status, [self::STATUS_OPEN, self::STATUS_REOPENED], true),
            'resolve' => $canWork && $this->status === self::STATUS_IN_PROGRESS,
            // Pull a resolved or in-progress issue back a step
            'reopen_work' => $canWork && in_array($this->status, [self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED], true),
            // The person who resolved an issue may not confirm their own fix
            'confirm' => $this->status === self::STATUS_RESOLVED && $canDecide && $this->resolved_by !== $user->id,
            'close' => in_array($this->status, [self::STATUS_OPEN, self::STATUS_REOPENED], true) && $canDecide,
            'reopen' => in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true) && $canDecide,
            'edit' => $isAdmin || $isManager || ($isReporter && $this->status === self::STATUS_OPEN),
            'comment' => true,
            'delete' => $isAdmin || $isManager || ($isReporter && $this->status === self::STATUS_OPEN),
        ];
    }

    /**
     * Which ability is needed to move this report to the given status.
     */
    public function abilityNeededFor(string $newStatus): string
    {
        return match ($newStatus) {
            self::STATUS_IN_PROGRESS => $this->status === self::STATUS_RESOLVED ? 'reopen_work' : 'start_progress',
            self::STATUS_RESOLVED => 'resolve',
            self::STATUS_OPEN => 'reopen_work',
            self::STATUS_CLOSED => $this->status === self::STATUS_RESOLVED ? 'confirm' : 'close',
            default => 'reopen',
        };
    }

    private function belongsToCompanyOf(User $user): bool
    {
        if (!$user->company_id || !$this->project_id) {
            return false;
        }

        // Looked up directly: a loaded "project" relation may have been selected without its company
        $companyId = \Illuminate\Support\Facades\DB::table('projects')->where('id', $this->project_id)->value('company_id');

        return $companyId === $user->company_id;
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

        // Remember who resolved it, so they cannot confirm their own fix
        if ($newStatus === self::STATUS_RESOLVED) {
            $updateData['resolved_by'] = $user->id;
        }

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

    public function isBug(): bool
    {
        return ($this->attributes['type'] ?? self::TYPE_BUG) !== self::TYPE_FEATURE;
    }

    /**
     * Only bugs have a severity. A bug that is turned into a feature keeps its
     * severity in the database, out of sight, so it comes back if the issue is
     * turned into a bug again.
     */
    public function getSeverityAttribute(?string $value): ?string
    {
        return $this->isBug() ? $value : null;
    }

    /**
     * The severity held in the database, even while the issue is a feature.
     */
    public function storedSeverity(): ?string
    {
        return $this->attributes['severity'] ?? null;
    }

    // ==================== Dates ====================

    /**
     * Today's calendar date where the business works (Malaysia), as Y-m-d.
     * Timestamps are stored in UTC, but a due date is a plain calendar day,
     * so it has to be compared with the local day, not the UTC one.
     */
    public static function businessToday(): string
    {
        return now(config('app.business_timezone'))->toDateString();
    }

    /**
     * Issues past their due date that are neither resolved nor closed.
     */
    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
            ->whereDate('due_date', '<', self::businessToday())
            ->whereNotIn('status', self::FINISHED_STATUSES);
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->toDateString() < self::businessToday()
            && !in_array($this->status, self::FINISHED_STATUSES, true);
    }

    /**
     * Remember when the issue was resolved.
     */
    public function recordResolution(): void
    {
        $this->update(['resolved_at' => now()]);
    }
}
