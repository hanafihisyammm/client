<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;

class ProjectCreated extends AppNotification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Project $project,
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
            'title' => 'New project: ' . $this->project->name,
            'message' => $this->createdBy->name . ' created this project' . $this->forCompany() . '.',
            'url' => $this->url(),
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'created_by' => $this->createdBy->name,
        ];
    }

    private function forCompany(): string
    {
        return $this->project->company ? ' for ' . $this->project->company->name : '';
    }

    private function url(): string
    {
        return '/projects/view/' . $this->project->uuid;
    }
}
