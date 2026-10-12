<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class ReportAssigned extends AppNotification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Report $report,
        public User $assignedBy
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
            'Assigned to You: ' . $this->report->issue_key . ' ' . $this->report->title,
            'A report has been assigned to you',
            $this->assignedBy->name . ' assigned you to a report.',
            [
                'Report' => $this->report->issue_key . ' ' . $this->report->title,
                'Project' => $this->report->project->name ?? 'Unknown Project',
                'Priority' => $this->humanize($this->report->priority ?? 'Normal'),
                'Due date' => $this->report->due_date?->format('M j, Y'),
                'Assigned by' => $this->nameWithRole($this->assignedBy),
            ],
            'View Report',
            '/reports/' . $this->report->uuid
        );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Assigned to you: ' . $this->report->title,
            'message' => "{$this->assignedBy->name} assigned you to {$this->report->issue_key}: {$this->report->title}",
            'url' => '/reports/' . $this->report->uuid,
            'type' => 'report_assigned',
            'report_id' => $this->report->id,
            'report_uuid' => $this->report->uuid,
            'report_title' => $this->report->title,
            'issue_key' => $this->report->issue_key,
            'project_name' => $this->report->project?->name ?? 'Unknown Project',
            'assigned_by_id' => $this->assignedBy->id,
            'assigned_by_name' => $this->assignedBy->name,
        ];
    }
}
