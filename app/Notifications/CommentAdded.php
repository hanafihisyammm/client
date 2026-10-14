<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class CommentAdded extends AppNotification
{
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
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->buildMail(
            $notifiable,
            'New Comment: ' . $this->report->title,
            'New comment on a report',
            $this->commentedBy->name . ' added a comment.',
            [
                'Report' => $this->report->title,
                'Project' => $this->report->project->name ?? 'Unknown Project',
                'Comment by' => $this->nameWithRole($this->commentedBy),
            ],
            'View Report',
            '/reports/' . $this->report->uuid,
            $this->comment->summary(),
            'Comment'
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
            'title' => 'New comment: ' . $this->report->title,
            'message' => $this->commentedBy->name . ': ' . Str::limit($this->comment->summary(), 120),
            'url' => '/reports/' . $this->report->uuid,
            'report_id' => $this->report->id,
            'report_title' => $this->report->title,
            'project_name' => $this->report->project?->name,
            'commented_by' => $this->commentedBy->name,
            'commented_by_role' => $this->commentedBy->role,
            'comment' => $this->comment->summary(),
        ];
    }
}
