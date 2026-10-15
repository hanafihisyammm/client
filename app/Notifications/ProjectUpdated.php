<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;

class ProjectUpdated extends AppNotification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Project $project,
        public User $updatedBy,
        public array $changes
    ) {
        //
    }

    /**
     * Everyone notified about a project can open it: admins, its manager, and the staff on it.
     */
    private function urlFor(object $notifiable): string
    {
        return '/projects/view/' . $this->project->uuid;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Project updated: ' . $this->project->name,
            'message' => $this->updatedBy->name . ' changed the project ' . implode(', ', array_keys($this->changes)) . '.',
            'url' => $this->urlFor($notifiable),
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'updated_by' => $this->updatedBy->name,
            'updated_by_role' => $this->updatedBy->role,
            'changes' => $this->changes,
        ];
    }
}
