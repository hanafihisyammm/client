<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

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
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->buildMail(
            $notifiable,
            'Added to Project: ' . $this->project->name,
            'You have been added to a project',
            $this->assignedBy->name . ' added you to a project. You can now create reports for it and follow their progress.',
            [
                'Project' => $this->project->name,
                'Description' => $this->project->description,
                'Added by' => $this->nameWithRole($this->assignedBy),
            ],
            'Create a Report',
            '/reports/create'
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
            'title' => 'Added to project: ' . $this->project->name,
            'message' => $this->assignedBy->name . ' added you to this project. You can now create reports for it.',
            'url' => '/dashboard',
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'assigned_by' => $this->assignedBy->name,
        ];
    }
}
