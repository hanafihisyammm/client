<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Report::with(['reporter', 'project']);

        // Managers see their company's issues; staff the ones in their projects, or that they raised
        $query->visibleTo($user);
        // Admin, super_admin, and manager can see all reports (no additional filtering)

        // KPI counts cover every report the user can see, regardless of the filters below
        $stats = $this->stats(clone $query);

        // Apply search and filters
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }

        if ($request->has('project') && $request->project !== 'all') {
            $query->where('project_id', $request->project);
        }

        $reports = $query->orderBy('created_at', 'desc')->get();

        // Every project the user can see, so issues in inactive or completed projects can still be
        // filtered. The page uses the status to decide whether new issues can be created.
        $projects = Project::visibleTo($user)
            ->select('projects.id', 'projects.name', 'projects.status')
            ->orderBy('projects.name')
            ->get();

        return Inertia::render('dashboard', [
            'reports' => $reports,
            'projects' => $projects,
            'userRole' => $user->role,
            'userId' => $user->id,
            'stats' => $stats,
            'filters' => [
                'search' => $request->search ?? '',
                'status' => $request->status ?? 'all',
                'priority' => $request->priority ?? 'all',
                'project' => $request->project ?? 'all',
            ],
        ]);
    }

    /**
     * Count the reports that need attention, by where they are in the workflow.
     *
     * @return array<string, int>
     */
    private function stats($query): array
    {
        $byStatus = (clone $query)
            ->reorder()
            ->setEagerLoads([])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $overdue = (clone $query)->overdue()->count();

        return [
            'open' => (int) ($byStatus[Report::STATUS_OPEN] ?? 0),
            'in_progress' => (int) ($byStatus[Report::STATUS_IN_PROGRESS] ?? 0),
            'resolved' => (int) ($byStatus[Report::STATUS_RESOLVED] ?? 0),
            'reopened' => (int) ($byStatus[Report::STATUS_REOPENED] ?? 0),
            'overdue' => $overdue,
        ];
    }
}
