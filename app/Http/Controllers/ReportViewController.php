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

        // Every project the user may use is listed; the page only lets active ones be chosen
        $projects = Project::visibleTo($user)
            ->select('projects.id', 'projects.name', 'projects.prefix', 'projects.status')
            ->orderBy('projects.name')
            ->get();

        // Staff with no projects (and managers whose company has none yet) cannot raise issues
        if ($projects->isEmpty() && !$user->isAdmin()) {
            return Inertia::render('reports/no-projects', [
                'userRole' => $user->role,
                'message' => $user->isManager()
                    ? 'Your company has no projects yet. Create a project first, then you can raise issues in it.'
                    : 'You must be assigned to a project before you can create issues. Please contact your manager.',
            ]);
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

        // Only people who may see this issue: admins, the company's manager, and staff involved in it
        if (!$report->isVisibleTo($user)) {
            abort(403, 'Unauthorized to view this report');
        }
        
        $report->load([
            'project:id,name',
            'reporter:id,name,email,role',
            'attachments',
            'activityLogs.user:id,name,email,role',
            'comments.user:id,name,email,role',
            'comments.attachments',
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
            // What this user may do with the issue; the page shows buttons from this
            'abilities' => $report->abilitiesFor($user),
        ]);
    }

    public function history(Report $report)
    {
        $user = Auth::user();

        // Only people who may see this issue: admins, the company's manager, and staff involved in it
        if (!$report->isVisibleTo($user)) {
            abort(403, 'Unauthorized to view this report');
        }
        
        $report->load([
            'project:id,name',
            'reporter:id,name,email,role',
            'activityLogs.user:id,name,email,role',
            'comments.user:id,name,email,role',
            'comments.attachments',
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
