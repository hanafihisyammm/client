<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;

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
