<?php

namespace App\Http\Controllers;

use App\Models\Label;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LabelController extends Controller
{
    public function index(Project $project)
    {
        $user = Auth::user();

        if (!$user->canManageProjects() && !$user->isDeveloper()) {
            abort(403, 'Unauthorized');
        }

        return response()->json([
            'labels' => $project->labels()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Project $project)
    {
        $user = Auth::user();

        if (!$user->canManageProjects()) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'description' => 'nullable|string|max:255',
        ]);

        $label = $project->labels()->create($validated);

        return response()->json([
            'message' => 'Label created successfully.',
            'label' => $label,
        ], 201);
    }

    public function update(Request $request, Project $project, Label $label)
    {
        $user = Auth::user();

        if (!$user->canManageProjects()) {
            abort(403, 'Unauthorized');
        }

        if ($label->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'description' => 'nullable|string|max:255',
        ]);

        $label->update($validated);

        return response()->json([
            'message' => 'Label updated successfully.',
            'label' => $label,
        ]);
    }

    public function destroy(Project $project, Label $label)
    {
        $user = Auth::user();

        if (!$user->canManageProjects()) {
            abort(403, 'Unauthorized');
        }

        if ($label->project_id !== $project->id) {
            abort(404);
        }

        $label->delete();

        return response()->json([
            'message' => 'Label deleted successfully.',
        ]);
    }
}
