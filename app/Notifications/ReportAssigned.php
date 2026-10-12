<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ReportAssigned extends Notification
{
    use Queueable;

    public function __construct(
        public Report $report,
        public User $assignedBy
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $projectName = $this->report->project?->name ?? $this->report->project ?? 'Unknown Project';

        return [
            'type' => 'report_assigned',
            'report_id' => $this->report->id,
            'report_uuid' => $this->report->uuid,
            'report_title' => $this->report->title,
            'issue_key' => $this->report->issue_key,
            'project_name' => $projectName,
            'assigned_by_id' => $this->assignedBy->id,
            'assigned_by_name' => $this->assignedBy->name,
            'message' => "{$this->assignedBy->name} assigned you to {$this->report->issue_key}: {$this->report->title}",
        ];
    }
}
