<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
use App\Notifications\AccountCreated;
use App\Notifications\CommentAdded;
use App\Notifications\ProjectAssigned;
use App\Notifications\ProjectUpdated;
use App\Notifications\ReportCreated;
use App\Notifications\ReportDeleted;
use App\Notifications\ReportStatusChanged;
use App\Notifications\ReportUpdated;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    /**
     * Notify relevant users when a project is updated.
     */
    public function notifyProjectUpdated(Project $project, User $updatedBy, array $changes): void
    {
        $this->send($this->getProjectRecipients($project, $updatedBy), new ProjectUpdated($project, $updatedBy, $changes));
    }

    /**
     * Notify clients when they are added to a project.
     *
     * @param  array<int, int|string>  $clientIds
     */
    public function notifyProjectAssigned(Project $project, User $assignedBy, array $clientIds): void
    {
        $recipients = User::whereIn('id', $clientIds)
            ->where('id', '!=', $assignedBy->id)
            ->get();

        $this->send($recipients, new ProjectAssigned($project, $assignedBy));
    }

    /**
     * Welcome a user whose account was created by an admin.
     */
    public function notifyAccountCreated(User $newUser, User $createdBy): void
    {
        $newUser->notify(new AccountCreated($createdBy));
    }

    /**
     * Notify relevant users when a report status changes.
     */
    public function notifyReportStatusChanged(Report $report, User $updatedBy, string $oldStatus, string $newStatus, ?string $reason = null): void
    {
        $report->load('project');

        $this->send(
            $this->getReportRecipients($report, $updatedBy),
            new ReportStatusChanged($report, $updatedBy, $oldStatus, $newStatus, $reason)
        );
    }

    /**
     * Notify relevant users when a new report is created.
     */
    public function notifyReportCreated(Report $report, User $createdBy): void
    {
        $report->load('project');

        $this->send($this->getReportRecipients($report, $createdBy), new ReportCreated($report, $createdBy));
    }

    /**
     * Notify relevant users when a report's details are edited.
     */
    public function notifyReportUpdated(Report $report, User $updatedBy, array $changes): void
    {
        $report->load('project');

        $this->send($this->getReportRecipients($report, $updatedBy), new ReportUpdated($report, $updatedBy, $changes));
    }

    /**
     * Notify relevant users when a report is deleted. Call this before deleting the report.
     */
    public function notifyReportDeleted(Report $report, User $deletedBy): void
    {
        $report->load('project');

        $this->send(
            $this->getReportRecipients($report, $deletedBy),
            new ReportDeleted($report->title, $report->project?->name, $deletedBy)
        );
    }

    /**
     * Notify relevant users when a comment is added to a report.
     */
    public function notifyCommentAdded(Report $report, ReportComment $comment, User $commentedBy): void
    {
        $report->load('project');

        $this->send($this->getReportRecipients($report, $commentedBy), new CommentAdded($report, $comment, $commentedBy));
    }

    private function send(Collection $recipients, \Illuminate\Notifications\Notification $notification): void
    {
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }

    /**
     * Everyone involved in a project except the person who acted:
     * all admins and super admins, plus the clients assigned to the project.
     */
    private function getProjectRecipients(?Project $project, User $actor, ?int $reporterId = null): Collection
    {
        $projectId = $project?->id;

        return User::where('id', '!=', $actor->id)
            ->where(function ($query) use ($projectId, $reporterId) {
                $query->whereIn('role', ['admin', 'super_admin'])
                    ->orWhereIn('id', function ($sub) use ($projectId) {
                        $sub->select('user_id')
                            ->from('project_users')
                            ->where('project_id', $projectId);
                    });

                if ($reporterId) {
                    $query->orWhere('id', $reporterId);
                }
            })
            ->get();
    }

    /**
     * Everyone involved in a report except the person who acted:
     * the project's audience plus the reporter, even if they have since left the project.
     */
    private function getReportRecipients(Report $report, User $actor): Collection
    {
        return $this->getProjectRecipients($report->project, $actor, $report->reported_by);
    }
}
