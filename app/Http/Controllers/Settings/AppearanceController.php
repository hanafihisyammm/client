<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Appearance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    /**
     * Show the theme setting.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/appearance');
    }

    /**
     * Save the user's theme: Light, Dark or System.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'appearance' => ['required', Rule::in(Appearance::OPTIONS)],
        ]);

        $request->user()->forceFill(['appearance' => $validated['appearance']])->save();
        Appearance::remember($validated['appearance']);

        return back();
    }
}
