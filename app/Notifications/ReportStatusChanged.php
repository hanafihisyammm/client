<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class ReportStatusChanged extends AppNotification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Report $report,
        public User $updatedBy,
        public string $oldStatus,
        public string $newStatus,
        public ?string $reason = null
    ) {
        //
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $wording = $this->wording($notifiable);

        return $this->buildMail(
            $notifiable,
            $wording['subject'],
            $wording['heading'],
            $wording['intro'],
            [
                'Report' => $this->report->title,
                'Project' => $this->report->project->name ?? 'Unknown Project',
                'Status' => $this->formatStatus($this->oldStatus) . ' → ' . $this->formatStatus($this->newStatus),
                'Updated by' => $this->nameWithRole($this->updatedBy),
            ],
            $wording['action'],
            '/reports/' . $this->report->uuid,
            $this->reason,
            $this->newStatus === Report::STATUS_RESOLVED ? 'Resolution' : 'Reason',
            $wording['outro']
        );
    }

    /**
     * Wording for this recipient. Resolved means "waiting for review": the reporter and the
     * manager are asked to confirm the fix, everyone else is told it is waiting for them.
     *
     * @return array{subject: string, title: string, heading: string, intro: string, message: string, action: string, outro: array<int, string>}
     */
    private function wording(object $notifiable): array
    {
        $title = $this->report->title;
        $actor = $this->updatedBy->name;
        // The reporter and the company's manager are the ones who confirm or reject a fix
        $canConfirm = $notifiable instanceof User && $this->canConfirm($notifiable);
        $byReviewer = $this->canConfirm($this->updatedBy) || $this->updatedBy->isAdmin();

        if ($this->newStatus === Report::STATUS_RESOLVED && $canConfirm) {
            return [
                'subject' => 'Please Review: ' . $title,
                'title' => 'Please review: ' . $title,
                'heading' => 'Issue resolved - please review',
                'intro' => 'This issue has been resolved by ' . $actor . '. Please review and confirm whether the issue is fixed.',
                'message' => 'This issue has been resolved. Please review and confirm whether the issue is fixed.' . $this->reasonSuffix(),
                'action' => 'Review Issue',
                'outro' => ['If it is fixed, confirm it to close the issue. If it is not, reopen it and tell us what is still wrong.'],
            ];
        }

        if ($this->newStatus === Report::STATUS_RESOLVED) {
            return $this->standardWording(
                $actor . ' marked this issue as resolved. It is now waiting for the reporter or the manager to review and confirm the fix.',
                $actor . ' resolved this issue. Waiting for the reporter or manager to review.'
            );
        }

        if ($this->newStatus === Report::STATUS_CLOSED && $this->oldStatus === Report::STATUS_RESOLVED && $byReviewer) {
            return $this->standardWording(
                $actor . ' reviewed the fix and confirmed the issue is resolved. The issue is now closed.',
                $actor . ' confirmed the fix. The issue is now closed.'
            );
        }

        if ($this->newStatus === Report::STATUS_REOPENED && $this->oldStatus === Report::STATUS_RESOLVED && $byReviewer) {
            return $this->standardWording(
                $actor . ' reviewed the fix and reported that the issue is not fixed. It has been reopened.',
                $actor . ' rejected the fix and reopened the issue.'
            );
        }

        return $this->standardWording(
            $actor . ' changed the status of a report.',
            $actor . ' changed the status from ' . $this->formatStatus($this->oldStatus) . ' to ' . $this->formatStatus($this->newStatus) . '.'
        );
    }

    private function canConfirm(User $user): bool
    {
        return $user->id === $this->report->reported_by
            || ($user->isManager() && $user->company_id !== null && $user->company_id === $this->report->project?->company_id);
    }

    private function standardWording(string $intro, string $message): array
    {
        return [
            'subject' => 'Report ' . $this->formatStatus($this->newStatus) . ': ' . $this->report->title,
            'title' => 'Report ' . strtolower($this->formatStatus($this->newStatus)) . ': ' . $this->report->title,
            'heading' => 'Report status updated',
            'intro' => $intro,
            'message' => $message . $this->reasonSuffix(),
            'action' => 'View Report',
            'outro' => [],
        ];
    }

    private function reasonSuffix(): string
    {
        if (!$this->reason) {
            return '';
        }

        return ' ' . ($this->newStatus === Report::STATUS_RESOLVED ? 'Resolution' : 'Reason') . ': ' . Str::limit($this->reason, 120);
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
        $wording = $this->wording($notifiable);

        return [
            'title' => $wording['title'],
            'message' => $wording['message'],
            'url' => '/reports/' . $this->report->uuid,
            'report_id' => $this->report->id,
            'report_title' => $this->report->title,
            'project_name' => $this->report->project?->name,
            'updated_by' => $this->updatedBy->name,
            'updated_by_role' => $this->updatedBy->role,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'reason' => $this->reason,
        ];
    }
}
