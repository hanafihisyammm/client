<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectUpdated extends Notification
{
    use Queueable;

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
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $updaterRole = $this->updatedBy->isAdmin() ? ucfirst($this->updatedBy->role) : 'Client';
        
        return (new MailMessage)
            ->subject('Project Updated: ' . $this->project->name)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('A project you are involved in has been updated by ' . $this->updatedBy->name . ' (' . ucfirst($this->updatedBy->role) . ').')
            ->line('**Project:** ' . $this->project->name)
            ->line('**Updated by:** ' . $this->updatedBy->name . ' (' . ucfirst($this->updatedBy->role) . ')')
            ->line('**Changes made:**')
            ->lines($this->formatChanges())
            ->action('View Project', url('/admin/projects/' . $this->project->uuid))
            ->line('Thank you for using Client Issue & Requirements Tracking System!');
    }

    /**
     * Format the changes for display in email.
     */
    private function formatChanges(): array
    {
        $formattedChanges = [];
        
        foreach ($this->changes as $field => $change) {
            $fieldName = ucfirst(str_replace('_', ' ', $field));
            $formattedChanges[] = "• {$fieldName}: {$change['old']} → {$change['new']}";
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
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'updated_by' => $this->updatedBy->name,
            'updated_by_role' => $this->updatedBy->role,
            'changes' => $this->changes,
        ];
    }
}
