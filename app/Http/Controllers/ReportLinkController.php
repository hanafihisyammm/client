<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportLink;
use App\Models\ReportActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportLinkController extends Controller
{
    public function index(Report $report)
    {
        $outgoingLinks = $report->outgoingLinks()
            ->with(['targetReport:id,uuid,title,status,priority', 'creator:id,name'])
            ->get();

        $incomingLinks = $report->incomingLinks()
            ->with(['sourceReport:id,uuid,title,status,priority', 'creator:id,name'])
            ->get();

        return response()->json([
            'outgoing_links' => $outgoingLinks,
            'incoming_links' => $incomingLinks,
            'link_types' => ReportLink::LINK_TYPES,
        ]);
    }

    public function store(Request $request, Report $report)
    {
        $user = Auth::user();

        // Only admins, managers, and developers can link bugs
        if (!$user->isAdmin() && !$user->isManager() && !$user->isDeveloper()) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'target_report_uuid' => 'required|string|exists:reports,uuid',
            'link_type' => 'required|string|in:' . implode(',', array_keys(ReportLink::LINK_TYPES)),
        ]);

        $targetReport = Report::where('uuid', $validated['target_report_uuid'])->first();

        // Cannot link to itself
        if ($targetReport->id === $report->id) {
            return response()->json([
                'message' => 'Cannot link a report to itself',
            ], 422);
        }

        // Check if link already exists
        $existingLink = ReportLink::where('source_report_id', $report->id)
            ->where('target_report_id', $targetReport->id)
            ->where('link_type', $validated['link_type'])
            ->first();

        if ($existingLink) {
            return response()->json([
                'message' => 'This link already exists',
            ], 422);
        }

        $link = ReportLink::create([
            'source_report_id' => $report->id,
            'target_report_id' => $targetReport->id,
            'link_type' => $validated['link_type'],
            'created_by' => $user->id,
        ]);

        // Log activity
        ReportActivityLog::create([
            'report_id' => $report->id,
            'user_id' => $user->id,
            'action' => 'linked_report',
            'new_values' => [
                'linked_report_id' => $targetReport->id,
                'linked_report_title' => $targetReport->title,
                'link_type' => $validated['link_type'],
            ],
        ]);

        $link->load(['targetReport:id,uuid,title,status,priority', 'creator:id,name']);

        return response()->json([
            'message' => 'Issue linked successfully.',
            'link' => $link,
        ], 201);
    }

    public function destroy(Report $report, ReportLink $link)
    {
        $user = Auth::user();

        // Only the creator or admin can delete links
        if ($link->created_by !== $user->id && !$user->isAdmin()) {
            abort(403, 'Unauthorized');
        }

        if ($link->source_report_id !== $report->id) {
            abort(404);
        }

        $targetReport = $link->targetReport;
        $linkType = $link->link_type;

        $link->delete();

        // Log activity
        ReportActivityLog::create([
            'report_id' => $report->id,
            'user_id' => $user->id,
            'action' => 'unlinked_report',
            'old_values' => [
                'linked_report_id' => $targetReport->id,
                'linked_report_title' => $targetReport->title,
                'link_type' => $linkType,
            ],
        ]);

        return response()->json([
            'message' => 'Link removed successfully.',
        ]);
    }
}
