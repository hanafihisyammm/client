<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportStatusChanged extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Report $report,
        public User $updatedBy,
        public string $oldStatus,
        public string $newStatus
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
            ->subject('Report Status Updated: ' . $this->report->title)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('A report status has been updated by ' . $updaterRole . ' ' . $this->updatedBy->name . '.')
            ->line('**Report:** ' . $this->report->title)
            ->line('**Project:** ' . ($this->report->project->name ?? 'Unknown Project'))
            ->line('**Updated by:** ' . $this->updatedBy->name . ' (' . ucfirst($this->updatedBy->role) . ')')
            ->line('**Status changed from:** ' . $this->formatStatus($this->oldStatus) . ' → ' . $this->formatStatus($this->newStatus))
            ->when($this->newStatus === 'ready_for_client_confirmation' && $this->report->resolution_summary, function ($message) {
                return $message->line('**Resolution Summary:** ' . $this->report->resolution_summary);
            })
            ->action('View Report', url('/reports/' . $this->report->uuid))
            ->line('Thank you for using Client Issue & Requirements Tracking System!');
    }

    /**
     * Format status for display.
     */
    private function formatStatus(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
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
            'updated_by' => $this->updatedBy->name,
            'updated_by_role' => $this->updatedBy->role,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
        ];
    }
}
