<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ReportViewController extends Controller
{
    public function create()
    {
        $user = Auth::user();

        // Get available projects based on user role
        if ($user->isClient() || $user->isDeveloper()) {
            // Clients and developers can only see projects they're assigned to
            $projectIds = \DB::table('project_users')
                ->where('user_id', $user->id)
                ->pluck('project_id');
            // Every assigned project is listed; the page only lets active ones be chosen
            $projects = Project::whereIn('id', $projectIds)->select('id', 'name', 'prefix', 'status')->orderBy('name')->get();

            // If user has no projects assigned, prevent report creation
            if ($projects->isEmpty()) {
                return Inertia::render('reports/no-projects', [
                    'userRole' => $user->role,
                    'message' => 'You must be assigned to a project before you can create reports. Please contact your administrator.'
                ]);
            }
        } else {
            // Admins, super admins, and managers see every project; the page only lets active ones be chosen
            $projects = Project::select('id', 'name', 'prefix', 'status')->orderBy('name')->get();
        }

        return Inertia::render('reports/create', [
            'userRole' => $user->role,
            'userId' => $user->id,
            'projects' => $projects
        ]);
    }

    public function show(Report $report)
    {
        $user = Auth::user();

        // Authorization check for clients and developers
        if ($user->isClient() || $user->isDeveloper()) {
            // Check if user can view this report
            $canView = false;

            // User can view their own reports
            if ($report->reported_by === $user->id) {
                $canView = true;
            } else {
                // User can view reports from projects they're assigned to
                $userProjectIds = \DB::table('project_users')
                    ->where('user_id', $user->id)
                    ->pluck('project_id');

                if ($userProjectIds->contains($report->project_id)) {
                    $canView = true;
                }
            }

            if (!$canView) {
                abort(403, 'Unauthorized to view this report');
            }
        }
        
        $report->load([
            'project:id,name',
            'reporter:id,name,email,role',
            'assignee:id,name,email,role',
            'attachments',
            'activityLogs.user:id,name,email,role',
            'comments.user:id,name,email,role'
        ]);
        
        // Convert to array to ensure proper serialization for Inertia.js
        $reportData = $report->toArray();
        $reportData['activityLogs'] = $report->activityLogs->toArray();
        $reportData['comments'] = $report->comments->toArray();
        $reportData['attachments'] = $report->attachments->toArray();
        
        return Inertia::render('reports/show', [
            'report' => $reportData,
            'userRole' => $user->role,
            'userId' => $user->id,
            // Clients who may confirm or reject the fix once the issue is resolved
            'canReview' => $report->canBeReviewedBy($user),
        ]);
    }

    public function history(Report $report)
    {
        $user = Auth::user();

        // Authorization check for clients and developers
        if ($user->isClient() || $user->isDeveloper()) {
            $canView = false;

            // User can view their own reports
            if ($report->reported_by === $user->id) {
                $canView = true;
            } else {
                // User can view reports from projects they're assigned to
                $userProjectIds = \DB::table('project_users')
                    ->where('user_id', $user->id)
                    ->pluck('project_id');

                if ($userProjectIds->contains($report->project_id)) {
                    $canView = true;
                }
            }

            if (!$canView) {
                abort(403, 'Unauthorized to view this report');
            }
        }
        
        $report->load([
            'project:id,name',
            'reporter:id,name,email,role',
            'assignee:id,name,email,role',
            'activityLogs.user:id,name,email,role',
            'comments.user:id,name,email,role'
        ]);
        
        // Convert to array to ensure proper serialization for Inertia.js
        $reportData = $report->toArray();
        $reportData['activityLogs'] = $report->activityLogs->toArray();
        $reportData['comments'] = $report->comments->toArray();
        
        return Inertia::render('reports/history', [
            'report' => $reportData,
            'userRole' => $user->role,
            'userId' => $user->id
        ]);
    }
}
