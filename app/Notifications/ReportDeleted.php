<?php

namespace App\Notifications;

use App\Models\User;

class ReportDeleted extends AppNotification
{
    /**
     * Create a new notification instance.
     *
     * The report no longer exists when this is sent, so only its details are kept.
     */
    public function __construct(
        public string $reportTitle,
        public ?string $projectName,
        public User $deletedBy
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
            'title' => 'Report deleted: ' . $this->reportTitle,
            'message' => $this->deletedBy->name . ' deleted a report in ' . ($this->projectName ?? 'Unknown Project') . '.',
            'url' => '/dashboard',
            'report_title' => $this->reportTitle,
            'project_name' => $this->projectName,
            'deleted_by' => $this->deletedBy->name,
            'deleted_by_role' => $this->deletedBy->role,
        ];
    }
}
