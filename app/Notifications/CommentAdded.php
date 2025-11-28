<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommentAdded extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Report $report,
        public ReportComment $comment,
        public User $commentedBy
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
        $commenterRole = $this->commentedBy->isAdmin() ? ucfirst($this->commentedBy->role) : 'Client';
        
        return (new MailMessage)
            ->subject('New Comment Added: ' . $this->report->title)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('A new comment has been added to a report by ' . $commenterRole . ' ' . $this->commentedBy->name . '.')
            ->line('**Report:** ' . $this->report->title)
            ->line('**Project:** ' . ($this->report->project->name ?? 'Unknown Project'))
            ->line('**Comment by:** ' . $this->commentedBy->name . ' (' . ucfirst($this->commentedBy->role) . ')')
            ->line('**Comment:** ' . $this->comment->comment)
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
            'commented_by' => $this->commentedBy->name,
            'commented_by_role' => $this->commentedBy->role,
            'comment' => $this->comment->comment,
        ];
    }
}
