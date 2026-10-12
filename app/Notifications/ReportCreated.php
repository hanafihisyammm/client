<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class ReportCreated extends AppNotification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Report $report,
        public User $createdBy
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
            'New Report: ' . $this->report->title,
            'New report created',
            $this->createdBy->name . ' created a new report.',
            [
                'Report' => $this->report->title,
                'Project' => $this->report->project->name ?? 'Unknown Project',
                'Created by' => $this->nameWithRole($this->createdBy),
                'Priority' => $this->humanize($this->report->priority ?? 'Normal'),
                'Due date' => $this->report->due_date?->format('M j, Y'),
            ],
            'View Report',
            '/reports/' . $this->report->uuid,
            $this->report->description,
            'Description'
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
            'title' => 'New report: ' . $this->report->title,
            'message' => $this->createdBy->name . ' created a report in ' . ($this->report->project->name ?? 'Unknown Project') . '.',
            'url' => '/reports/' . $this->report->uuid,
            'report_id' => $this->report->id,
            'report_title' => $this->report->title,
            'project_name' => $this->report->project?->name,
            'created_by' => $this->createdBy->name,
            'created_by_role' => $this->createdBy->role,
            'priority' => $this->report->priority,
            'due_date' => $this->report->due_date?->toDateString(),
        ];
    }
}
