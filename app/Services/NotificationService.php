<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
use App\Notifications\CommentAdded;
use App\Notifications\ProjectAssigned;
use App\Notifications\ProjectCreated;
use App\Notifications\ProjectUpdated;
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
     * Tell the admins and the company's manager that a project was created.
     * Staff put on it hear through notifyProjectAssigned instead.
     */
    public function notifyProjectCreated(Project $project, User $createdBy): void
    {
        $recipients = User::where('id', '!=', $createdBy->id)
            ->whereNull('deactivated_at')
            ->where(function ($query) use ($project) {
                $query->whereIn('role', ['admin', 'super_admin'])
                    ->orWhere(function ($manager) use ($project) {
                        $manager->where('role', 'manager')->whereNotNull('company_id')->where('company_id', $project->company_id);
                    });
            })
            ->get();

        $this->send($recipients, new ProjectCreated($project, $createdBy));
    }

    /**
     * Notify staff when they are added to a project.
     *
     * @param  array<int, int|string>  $clientIds
     */
    public function notifyProjectAssigned(Project $project, User $assignedBy, array $clientIds): void
    {
        $recipients = User::whereIn('id', $clientIds)
            ->where('id', '!=', $assignedBy->id)
            ->whereNull('deactivated_at')
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
     * Everyone involved in a project except the person who acted: all admins and
     * super admins, the manager of the project's company, and the staff assigned to
     * the project. Deactivated people are never notified.
     */
    private function getProjectRecipients(?Project $project, User $actor, array $alsoNotifyIds = []): Collection
    {
        $projectId = $project?->id;
        $companyId = $project?->company_id;
        $alsoNotifyIds = array_values(array_filter($alsoNotifyIds));

        return User::where('id', '!=', $actor->id)
            ->whereNull('deactivated_at')
            ->where(function ($query) use ($projectId, $companyId, $alsoNotifyIds) {
                $query->whereIn('role', ['admin', 'super_admin'])
                    ->orWhere(function ($manager) use ($companyId) {
                        $manager->where('role', 'manager')->whereNotNull('company_id')->where('company_id', $companyId);
                    })
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
     * Everyone involved in a report except the person who acted:
     * the project's audience plus the reporter.
     */
    private function getReportRecipients(Report $report, User $actor): Collection
    {
        return $this->getProjectRecipients($report->project, $actor, [$report->reported_by]);
    }
}
