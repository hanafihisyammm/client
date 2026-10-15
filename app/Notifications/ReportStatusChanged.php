<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;
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
     * Wording for this recipient. Resolved means "waiting for review": the reporter and the
     * manager are asked to confirm the fix, everyone else is told it is waiting for them.
     *
     * @return array{title: string, message: string}
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
                'title' => 'Please review: ' . $title,
                'message' => 'This issue has been resolved. Please review and confirm whether the issue is fixed.' . $this->reasonSuffix(),
            ];
        }

        if ($this->newStatus === Report::STATUS_RESOLVED) {
            return $this->standardWording($actor . ' resolved this issue. Waiting for the reporter or manager to review.');
        }

        if ($this->newStatus === Report::STATUS_CLOSED && $this->oldStatus === Report::STATUS_RESOLVED && $byReviewer) {
            return $this->standardWording($actor . ' confirmed the fix. The issue is now closed.');
        }

        if ($this->newStatus === Report::STATUS_REOPENED && $this->oldStatus === Report::STATUS_RESOLVED && $byReviewer) {
            return $this->standardWording($actor . ' rejected the fix and reopened the issue.');
        }

        return $this->standardWording(
            $actor . ' changed the status from ' . $this->formatStatus($this->oldStatus) . ' to ' . $this->formatStatus($this->newStatus) . '.'
        );
    }

    private function canConfirm(User $user): bool
    {
        return $user->id === $this->report->reported_by
            || ($user->isManager() && $user->company_id !== null && $user->company_id === $this->report->project?->company_id);
    }

    private function standardWording(string $message): array
    {
        return [
            'title' => 'Report ' . strtolower($this->formatStatus($this->newStatus)) . ': ' . $this->report->title,
            'message' => $message . $this->reasonSuffix(),
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
