<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
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
