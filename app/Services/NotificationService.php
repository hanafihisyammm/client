<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
use App\Notifications\CommentAdded;
use App\Notifications\ProjectAssigned;
use App\Notifications\ProjectUpdated;
use App\Notifications\ReportAssigned;
use App\Notifications\ReportCreated;
use App\Notifications\ReportDeleted;
use App\Notifications\ReportStatusChanged;
use App\Notifications\ReportUpdated;
use App\Notifications\UserInvited;
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
     * Email a new user the link to set their name and password.
     */
    public function notifyUserInvited(User $newUser, User $invitedBy): void
    {
        $newUser->notify(new UserInvited($invitedBy));
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
     * all admins and super admins, plus everyone assigned to the project.
     */
    private function getProjectRecipients(?Project $project, User $actor, array $alsoNotifyIds = []): Collection
    {
        $projectId = $project?->id;
        $alsoNotifyIds = array_values(array_filter($alsoNotifyIds));

        return User::where('id', '!=', $actor->id)
            ->where(function ($query) use ($projectId, $alsoNotifyIds) {
                $query->whereIn('role', ['admin', 'super_admin'])
                    ->orWhereIn('id', function ($sub) use ($projectId) {
                        $sub->select('user_id')
                            ->from('project_users')
                            ->where('project_id', $projectId);
                    });

                if ($alsoNotifyIds) {
                    $query->orWhereIn('id', $alsoNotifyIds);
                }
            })
            ->get();
    }

    /**
     * Notify a user when they are assigned to a report.
     */
    public function notifyReportAssigned(Report $report, User $assignedBy, User $assignee): void
    {
        // Don't notify if assigning to self
        if ($assignedBy->id === $assignee->id) {
            return;
        }

        $report->load('project');

        $assignee->notify(new ReportAssigned($report, $assignedBy));
    }

    /**
     * Everyone involved in a report except the person who acted:
     * the project's audience plus the reporter and the assignee, even if they are not on the project.
     */
    private function getReportRecipients(Report $report, User $actor): Collection
    {
        return $this->getProjectRecipients($report->project, $actor, [$report->reported_by, $report->assigned_to]);
    }
}
