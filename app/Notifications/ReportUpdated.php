<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;

class ReportUpdated extends AppNotification
{
    /**
     * Create a new notification instance.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function __construct(
        public Report $report,
        public User $updatedBy,
        public array $changes
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
        $fields = array_map(fn ($field) => str_replace('_', ' ', $field), array_keys($this->changes));

        return [
            'title' => 'Report updated: ' . $this->report->title,
            'message' => $this->updatedBy->name . ' changed the ' . implode(', ', $fields) . '.',
            'url' => '/reports/' . $this->report->uuid,
            'report_id' => $this->report->id,
            'report_title' => $this->report->title,
            'project_name' => $this->report->project?->name,
            'updated_by' => $this->updatedBy->name,
            'updated_by_role' => $this->updatedBy->role,
            'changes' => $this->changes,
        ];
    }
}
