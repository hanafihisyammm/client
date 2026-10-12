<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

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
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->buildMail(
            $notifiable,
            'Project Updated: ' . $this->project->name,
            'Project updated',
            $this->updatedBy->name . ' updated a project you are involved in.',
            [
                'Project' => $this->project->name,
                'Updated by' => $this->nameWithRole($this->updatedBy),
                ...$this->formatChanges(),
            ],
            'View Project',
            $this->urlFor($notifiable)
        );
    }

    /**
     * Everyone notified about a project can open it: admins, its manager, and the staff on it.
     */
    private function urlFor(object $notifiable): string
    {
        return '/projects/view/' . $this->project->uuid;
    }

    /**
     * Format the changes as detail rows: field => "old → new".
     *
     * @return array<string, string>
     */
    private function formatChanges(): array
    {
        $formattedChanges = [];

        foreach ($this->changes as $field => $change) {
            $fieldName = ucfirst(str_replace('_', ' ', $field));
            $formattedChanges[$fieldName] = ($change['old'] ?: 'None') . ' → ' . ($change['new'] ?: 'None');
        }

        return $formattedChanges;
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
