<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AuditLogController extends Controller
{
    /**
     * Display the audit log.
     */
    public function index(Request $request)
    {
        // Only admin and super_admin can view the audit log
        if (!Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized');
        }

        $filters = $request->validate([
            'search' => 'nullable|string|max:255',
            'type' => 'nullable|in:all,auth,user,project,report,comment',
            'user' => 'nullable|string|max:20',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id');

        if (!empty($filters['search'])) {
            $search = '%' . mb_strtolower($filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('lower(description) like ?', [$search])
                    ->orWhereRaw('lower(user_name) like ?', [$search])
                    ->orWhereRaw('lower(subject_label) like ?', [$search]);
            });
        }

        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('action', 'like', $filters['type'] . '.%');
        }

        if (!empty($filters['user']) && $filters['user'] !== 'all') {
            $query->where('user_id', (int) $filters['user']);
        }

        // The chosen days are Malaysian calendar days, while created_at is stored in UTC
        $timezone = config('app.business_timezone');

        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'], $timezone)->startOfDay()->utc());
        }

        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'], $timezone)->endOfDay()->utc());
        }

        return Inertia::render('admin/audit-logs/index', [
            'logs' => $query->paginate(25)->withQueryString(),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? 'all',
                'user' => $filters['user'] ?? 'all',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }
}
