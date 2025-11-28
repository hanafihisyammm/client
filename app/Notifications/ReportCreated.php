<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportCreated extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Report $report,
        public User $createdBy
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
        $creatorRole = $this->createdBy->isAdmin() ? ucfirst($this->createdBy->role) : 'Client';
        
        return (new MailMessage)
            ->subject('New Report Created: ' . $this->report->title)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('A new report has been created by ' . $creatorRole . ' ' . $this->createdBy->name . '.')
            ->line('**Report:** ' . $this->report->title)
            ->line('**Project:** ' . ($this->report->project->name ?? 'Unknown Project'))
            ->line('**Created by:** ' . $this->createdBy->name . ' (' . ucfirst($this->createdBy->role) . ')')
            ->line('**Priority:** ' . ucfirst($this->report->priority ?? 'Normal'))
            ->when($this->report->due_date, function ($message) {
                return $message->line('**Due Date:** ' . $this->report->due_date->format('M j, Y'));
            })
            ->line('**Description:** ' . $this->report->description)
            ->action('View Report', url('/reports/' . $this->report->uuid))
            ->line('Thank you for using Client Issue & Requirements Tracking System!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'report_id' => $this->report->id,
            'report_title' => $this->report->title,
            'project_name' => $this->report->project->name,
            'created_by' => $this->createdBy->name,
            'created_by_role' => $this->createdBy->role,
            'priority' => $this->report->priority,
            'due_date' => $this->report->due_date?->toDateString(),
        ];
    }
}
