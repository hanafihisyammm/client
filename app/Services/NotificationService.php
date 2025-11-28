<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
use App\Notifications\CommentAdded;
use App\Notifications\ProjectUpdated;
use App\Notifications\ReportCreated;
use App\Notifications\ReportStatusChanged;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    /**
     * Notify relevant users when a project is updated.
     */
    public function notifyProjectUpdated(Project $project, User $updatedBy, array $changes): void
    {
        // Ensure users relationship is loaded
        $project->load('users');
        $recipients = $this->getProjectRecipients($project, $updatedBy);
        
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ProjectUpdated($project, $updatedBy, $changes));
        }
    }

    /**
     * Notify relevant users when a report status changes.
     */
    public function notifyReportStatusChanged(Report $report, User $updatedBy, string $oldStatus, string $newStatus): void
    {
        // Ensure project relationship is loaded with users
        $report->load('project.users');
        $recipients = $this->getReportRecipients($report, $updatedBy);
        
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ReportStatusChanged($report, $updatedBy, $oldStatus, $newStatus));
        }
    }

    /**
     * Notify relevant users when a new report is created.
     */
    public function notifyReportCreated(Report $report, User $createdBy): void
    {
        // Ensure project relationship is loaded with users
        $report->load('project.users');
        $recipients = $this->getReportRecipients($report, $createdBy);
        
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ReportCreated($report, $createdBy));
        }
    }

    /**
     * Notify relevant users when a comment is added to a report.
     */
    public function notifyCommentAdded(Report $report, ReportComment $comment, User $commentedBy): void
    {
        // Ensure project relationship is loaded
        $report->load('project.users');
        $recipients = $this->getCommentRecipients($report, $commentedBy);
        
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new CommentAdded($report, $comment, $commentedBy));
        }
    }

    /**
     * Get recipients for project notifications.
     * - If admin makes changes: notify all clients on the project
     * - If client makes changes: notify all admins and super admins
     */
    private function getProjectRecipients(Project $project, User $updatedBy): \Illuminate\Database\Eloquent\Collection
    {
        if ($updatedBy->isAdmin()) {
            // Admin updated project - notify all clients on this project
            return $project->users()->where('users.role', 'client')->get();
        } else {
            // Client updated project - notify all admins and super admins
            return User::whereIn('role', ['admin', 'super_admin'])->get();
        }
    }

    /**
     * Get recipients for report notifications.
     * - If admin makes changes: notify the reporter (if client) and all clients on the project
     * - If client makes changes: notify all admins, super admins, and assigned admin (if any)
     */
    private function getReportRecipients(Report $report, User $updatedBy): \Illuminate\Database\Eloquent\Collection
    {
        $recipients = collect();

        if ($updatedBy->isAdmin()) {
            // Admin created/updated report
            
            // Notify the reporter if they are a client and not the updater
            if ($report->reporter && $report->reporter->isClient() && $report->reporter->id !== $updatedBy->id) {
                $recipients->push($report->reporter);
            }
            
            // Notify all clients on this project (excluding the reporter to avoid duplicates)
            if ($report->project_id) {
                $projectClients = \App\Models\Project::find($report->project_id)
                    ?->users()
                    ->where('users.role', 'client')
                    ->where('users.id', '!=', $report->reporter?->id ?? 0)
                    ->get();
                    
                if ($projectClients) {
                    $recipients = $recipients->merge($projectClients);
                }
            }
            
        } else {
            // Client created/updated report - notify all admins and super admins
            $admins = User::whereIn('role', ['admin', 'super_admin'])->get();
            $recipients = $recipients->merge($admins);
            
            // Also notify assigned admin if exists and not already included
            if ($report->assignee && $report->assignee->isAdmin()) {
                $recipients->push($report->assignee);
            }
        }

        // Remove the updater from recipients to avoid self-notification
        $filtered = $recipients->filter(function ($user) use ($updatedBy) {
            return $user->id !== $updatedBy->id;
        })->unique('id');
        
        // Convert back to Eloquent Collection
        return new \Illuminate\Database\Eloquent\Collection($filtered->values());
    }

    /**
     * Get recipients for comment notifications.
     * - If admin comments: notify the report owner (if client) and other project members
     * - If client comments: notify all admins, super admins, and assigned admin (if any)
     */
    private function getCommentRecipients(Report $report, User $commentedBy): \Illuminate\Database\Eloquent\Collection
    {
        $recipients = collect();

        if ($commentedBy->isAdmin()) {
            // Admin commented - notify the reporter (if client) and other project clients
            
            // Notify the reporter if they are a client and not the commenter
            if ($report->reporter && $report->reporter->isClient() && $report->reporter->id !== $commentedBy->id) {
                $recipients->push($report->reporter);
            }
            
            // Notify other clients on this project (excluding the reporter to avoid duplicates)
            if ($report->project_id) {
                $projectClients = \App\Models\Project::find($report->project_id)
                    ?->users()
                    ->where('users.role', 'client')
                    ->where('users.id', '!=', $report->reporter?->id ?? 0)
                    ->where('users.id', '!=', $commentedBy->id)
                    ->get();
                    
                if ($projectClients) {
                    $recipients = $recipients->merge($projectClients);
                }
            }
            
        } else {
            // Client commented - notify all admins and super admins
            $admins = User::whereIn('role', ['admin', 'super_admin'])
                ->where('id', '!=', $commentedBy->id)
                ->get();
            $recipients = $recipients->merge($admins);
            
            // Also notify assigned admin if exists and not already included
            if ($report->assignee && $report->assignee->isAdmin() && $report->assignee->id !== $commentedBy->id) {
                $recipients->push($report->assignee);
            }
        }

        // Remove duplicates
        $filtered = $recipients->unique('id');
        
        // Convert back to Eloquent Collection
        return new \Illuminate\Database\Eloquent\Collection($filtered->values());
    }
}