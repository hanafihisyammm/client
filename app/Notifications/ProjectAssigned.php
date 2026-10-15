<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;

class ProjectAssigned extends AppNotification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Project $project,
        public User $assignedBy
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
            'title' => 'Added to project: ' . $this->project->name,
            'message' => $this->assignedBy->name . ' added you to this project. You can now create reports for it.',
            'url' => '/dashboard',
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'assigned_by' => $this->assignedBy->name,
        ];
    }
}
