<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class ReportStatusChanged extends AppNotification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Report $report,
        public User $updatedBy,
        public string $oldStatus,
        public string $newStatus,
        public ?string $reason = null
    ) {
        //
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->buildMail(
            $notifiable,
            'Report ' . $this->formatStatus($this->newStatus) . ': ' . $this->report->title,
            'Report status updated',
            $this->updatedBy->name . ' changed the status of a report.',
            [
                'Report' => $this->report->title,
                'Project' => $this->report->project->name ?? 'Unknown Project',
                'Status' => $this->formatStatus($this->oldStatus) . ' → ' . $this->formatStatus($this->newStatus),
                'Updated by' => $this->nameWithRole($this->updatedBy),
            ],
            'View Report',
            '/reports/' . $this->report->uuid,
            $this->reason,
            $this->newStatus === Report::STATUS_RESOLVED ? 'Resolution' : 'Reason'
        );
    }

    /**
     * Format status for display.
     */
    private function formatStatus(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Report ' . strtolower($this->formatStatus($this->newStatus)) . ': ' . $this->report->title,
            'message' => $this->updatedBy->name . ' changed the status from ' . $this->formatStatus($this->oldStatus) . ' to ' . $this->formatStatus($this->newStatus) . '.'
                . ($this->reason ? ' ' . ($this->newStatus === Report::STATUS_RESOLVED ? 'Resolution' : 'Reason') . ': ' . Str::limit($this->reason, 120) : ''),
            'url' => '/reports/' . $this->report->uuid,
            'report_id' => $this->report->id,
            'report_title' => $this->report->title,
            'project_name' => $this->report->project?->name,
            'updated_by' => $this->updatedBy->name,
            'updated_by_role' => $this->updatedBy->role,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'reason' => $this->reason,
        ];
    }
}
