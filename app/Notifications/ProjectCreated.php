<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

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
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->buildMail(
            $notifiable,
            'New Project: ' . $this->project->name,
            'A new project has been created',
            $this->createdBy->name . ' created a new project' . $this->forCompany() . '.',
            [
                'Project' => $this->project->name,
                'Client' => $this->project->company?->name,
                'Description' => $this->project->description,
                'Created by' => $this->nameWithRole($this->createdBy),
            ],
            'View Project',
            $this->url()
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
