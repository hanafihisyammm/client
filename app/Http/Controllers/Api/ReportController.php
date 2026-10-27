<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportAttachment;
use App\Models\ReportComment;
use App\Models\ReportActivityLog;
use App\Services\NotificationService;
use App\Support\Images\AttachmentImageProcessor;
use App\Support\Images\InvalidImageException;
use App\Support\Images\ProcessedImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    /** Size limits for issue attachments, in kilobytes: screen recordings get more room */
    private const MAX_ATTACHMENT_KB = 5120;
    private const MAX_VIDEO_KB = 25600;

    /** Video types an issue may carry; they are stored exactly as uploaded */
    private const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];


    /** Excel and CSV files; a CSV may read as plain text ("txt"), see plainTextOnlyAsCsv() */
    private const SPREADSHEET_EXTENSIONS = 'xlsx,xls,csv,txt';

    /** How many pages one issue can name */
    private const MAX_PAGE_URLS = 10;

    /** Names and messages for the page URLs in validation errors */
    private const PAGE_URL_ATTRIBUTES = ['page_urls' => 'page URLs', 'page_urls.*' => 'page URL'];
    private const PAGE_URL_MESSAGES = [
        'page_urls.required' => 'Add the URL of the page where the issue happens.',
        'page_urls.min' => 'Add the URL of the page where the issue happens.',
        'page_urls.*.distinct' => 'This page URL is listed twice.',
    ];

    /**
     * Rules for the full web addresses (as copied from the browser) of the pages an issue
     * happens on. $presence is "required" for a new issue, "sometimes" to keep the stored
     * list when it is left out, or "nullable" for an issue that has none yet.
     */
    private static function pageUrlRules(string $presence): array
    {
        $list = ['array', 'max:' . self::MAX_PAGE_URLS];

        return [
            'page_urls' => match ($presence) {
                'required' => ['required', ...$list, 'min:1'],
                'sometimes' => ['sometimes', 'required', ...$list, 'min:1'],
                default => ['nullable', ...$list],
            },
            'page_urls.*' => ['required', 'string', 'max:2048', 'url:http,https', 'distinct'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $query = Report::with(['reporter:id,name,email', 'attachments', 'project', 'labels'])
            ->orderBy('created_at', 'desc');

        // Managers and staff only see reports they raised or that are in their projects
        $query->visibleTo($user);

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('project') && $request->project !== 'all') {
            $query->where('project_id', $request->project);
        }

        if ($request->has('severity') && $request->severity !== 'all') {
            // Features may still hold the severity they had as a bug, so only bugs count
            $query->where('type', Report::TYPE_BUG)->where('severity', $request->severity);
        }

        if ($request->has('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $reports = $query->paginate($request->get('per_page', 15));

        return response()->json($reports);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            // The pages the issue was seen on, so it can be found and debugged
            ...self::pageUrlRules('required'),
            'project_id' => 'required|exists:projects,id',
            // "Today" is the Malaysian calendar day, not the UTC one
            'due_date' => 'nullable|date|after_or_equal:' . Report::businessToday(),
            'priority' => 'required|in:low,medium,high,urgent',
            'type' => 'nullable|in:' . implode(',', Report::TYPES),
            'severity' => 'nullable|in:critical,major,minor,trivial',
            'estimated_hours' => 'nullable|numeric|min:0',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'exists:labels,id',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => $this->attachmentRules(),
        ], self::PAGE_URL_MESSAGES, self::PAGE_URL_ATTRIBUTES);

        $user = Auth::user();
        
        // Get project name for the legacy project column
        $project = \App\Models\Project::find($validated['project_id']);
        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        // Managers can only use their company's projects, staff only the projects they are assigned to
        if (!$project->isVisibleTo($user)) {
            return response()->json(['message' => 'You do not have access to this project.'], 403);
        }

        // New issues can only be raised against a project that is still active
        if (!$project->isActive()) {
            return response()->json([
                'message' => 'Issues can only be created in an active project.',
                'errors' => ['project_id' => ['This project is ' . $project->status . '. Choose an active project.']],
            ], 422);
        }

        $type = $validated['type'] ?? Report::TYPE_BUG;

        // Images are checked and converted before anything is saved, so a bad file
        // stops the request with a clear message instead of leaving a half-made issue
        $attachments = $this->prepareAttachments($request->file('attachments') ?? []);
        $storedPaths = [];

        DB::beginTransaction();
        try {
            $report = Report::create([
                'title' => $validated['title'],
                'description' => $validated['description'],
                'page_urls' => array_values($validated['page_urls']),
                'project' => $project->name,
                'project_id' => $validated['project_id'],
                'due_date' => $validated['due_date'] ?? null,
                'priority' => $validated['priority'],
                'type' => $type,
                // Severity describes how badly something is broken, so only bugs have one
                'severity' => $type === Report::TYPE_BUG ? ($validated['severity'] ?? 'minor') : null,
                'estimated_hours' => $validated['estimated_hours'] ?? null,
                'reported_by' => Auth::id(),
                'status' => Report::STATUS_OPEN,
            ]);

            // Attach labels if provided
            if (!empty($validated['label_ids'])) {
                $report->labels()->attach($validated['label_ids']);
            }

            $this->saveAttachments($report, $attachments, $storedPaths);

            DB::commit();

            // Send notifications for new report
            $report->load('project');
            app(NotificationService::class)->notifyReportCreated($report, $user);

            return response()->json([
                'message' => 'Issue created successfully.',
                'report' => $report->load(['reporter:id,name,email', 'attachments'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            // The issue was not created, so its files must not be left behind either
            Storage::disk('public')->delete($storedPaths);

            return response()->json(['message' => 'Failed to create report'], 500);
        }
    }

    public function show(Report $report): JsonResponse
    {
        $user = Auth::user();
        
        if (!$report->isVisibleTo($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $report->load([
            'reporter:id,name,email,role',
            'attachments',
            'activityLogs.user:id,name,email,role',
            'comments.user:id,name,email,role',
            'comments.attachments',
            'labels',
            'timeEntries.user:id,name,email',
            'outgoingLinks.targetReport:id,uuid,title,status,priority',
            'outgoingLinks.creator:id,name',
            'incomingLinks.sourceReport:id,uuid,title,status,priority',
            'incomingLinks.creator:id,name',
        ]);

        // Add computed values
        $report->total_time_spent = $report->getTotalTimeSpent();

        return response()->json(['report' => $report, 'abilities' => $report->abilitiesFor($user)]);
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        $user = Auth::user();

        $action = $request->input('action');
        $message = 'Issue updated successfully.';

        $abilities = $report->abilitiesFor($user);
        if (!$abilities['view']) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Status changes: first check the move exists in the workflow, then that this user may make it
        $targetStatus = match ($action) {
            'start_progress' => Report::STATUS_IN_PROGRESS,
            'resolve' => Report::STATUS_RESOLVED,
            'close', 'close_report' => Report::STATUS_CLOSED,
            'reopen' => Report::STATUS_REOPENED,
            'transition' => $request->input('new_status'),
            default => null,
        };

        if (is_string($targetStatus) && in_array($targetStatus, Report::STATUSES, true)) {
            if (!$report->canTransitionTo($targetStatus)) {
                return response()->json([
                    'message' => 'An issue cannot move from ' . $report->getStatusLabel() . ' to ' . Report::STATUS_LABELS[$targetStatus] . '.',
                    'valid_transitions' => $report->getValidTransitions(),
                ], 400);
            }

            $needed = $report->abilityNeededFor($targetStatus);
        } else {
            $needed = match ($action) {
                'update_report' => 'edit',
                'add_comment', 'add_attachments' => 'comment',
                default => null,
            };
        }

        if ($needed !== null && !$abilities[$needed]) {
            $resolvedItThemselves = $needed === 'confirm' && $report->resolved_by === $user->id;

            return response()->json([
                'message' => $resolvedItThemselves
                    ? 'You resolved this issue, so someone else has to confirm the fix.'
                    : 'You are not allowed to do that.',
            ], 403);
        }

        if (!$report->isVisibleTo($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Clients can only act on reports they created, except that any client on the
        // project may review a resolved report (confirm the fix or reopen it)
        switch ($action) {
            // Start progress on an issue (open/reopened -> in_progress)
            case 'start_progress':
                if (!$report->canTransitionTo(Report::STATUS_IN_PROGRESS)) {
                    return response()->json(['message' => 'Cannot start progress from current status'], 400);
                }

                $report->startProgress($user);

                $message = 'Issue moved to In Progress.';
                break;

            // Resolve an issue (in_progress -> resolved)
            case 'resolve':
                if (!$report->canTransitionTo(Report::STATUS_RESOLVED)) {
                    return response()->json(['message' => 'Cannot resolve from current status'], 400);
                }

                $validated = $request->validate([
                    'resolution_summary' => 'nullable|string|max:2000'
                ]);

                $report->resolve($user, $validated['resolution_summary'] ?? 'Issue resolved');

                $report->recordResolution();

                $message = 'Issue marked as resolved.';
                break;

            // Close an issue (resolved -> closed) - Client confirms fix works
            case 'close':
                if (!$report->canTransitionTo(Report::STATUS_CLOSED)) {
                    return response()->json(['message' => 'Cannot close from current status'], 400);
                }

                $report->close($user);

                $message = 'Issue closed successfully.';
                break;

            // Reopen an issue (resolved/closed -> reopened) - Client says fix didn't work
            case 'reopen':
                if (!$report->canTransitionTo(Report::STATUS_REOPENED)) {
                    return response()->json(['message' => 'Cannot reopen from current status'], 400);
                }

                $validated = $request->validate([
                    'comment' => 'nullable|string|max:2000'
                ]);

                $report->reopen($user, $validated['comment'] ?? null);

                // Add comment explaining why reopened
                if (!empty($validated['comment'])) {
                    ReportComment::create([
                        'report_id' => $report->id,
                        'user_id' => $user->id,
                        'comment' => $validated['comment'],
                    ]);
                }

                $message = 'Issue reopened successfully.';
                break;

            // Generic status transition
            case 'transition':
                $validated = $request->validate([
                    'new_status' => ['required', Rule::in(Report::STATUSES)],
                    'resolution_summary' => 'nullable|string|max:2000'
                ]);

                if (!$report->canTransitionTo($validated['new_status'])) {
                    return response()->json([
                        'message' => 'An issue cannot move from ' . $report->getStatusLabel() . ' to ' . (Report::STATUS_LABELS[$validated['new_status']] ?? $validated['new_status']) . '.',
                        'valid_transitions' => $report->getValidTransitions()
                    ], 400);
                }

                $report->transitionTo($validated['new_status'], $user, $validated['resolution_summary'] ?? null);

                // Same follow-up as the dedicated "resolve" action
                if ($validated['new_status'] === Report::STATUS_RESOLVED) {
                    $report->recordResolution();
                }

                $message = match ($validated['new_status']) {
                    Report::STATUS_RESOLVED => 'Issue marked as resolved.',
                    Report::STATUS_CLOSED => 'Issue closed successfully.',
                    Report::STATUS_REOPENED => 'Issue reopened successfully.',
                    Report::STATUS_IN_PROGRESS => 'Issue moved to In Progress.',
                    default => 'Issue moved back to Open.',
                };
                break;

            case 'update_report':
                // Different validation rules for clients vs admins
                $rules = [
                    'title' => 'required|string|max:255',
                    'description' => 'required|string',
                    // Left out, the page URLs stay as they are. Issues from before they were asked for
                    // may stay without any, but once an issue has one it cannot be left with none.
                    ...self::pageUrlRules(empty($report->page_urls) ? 'nullable' : 'sometimes'),
                    'due_date' => 'nullable|date',
                    'priority' => 'required|in:low,medium,high,urgent',
                    'type' => 'nullable|in:' . implode(',', Report::TYPES),
                    'severity' => 'nullable|in:critical,major,minor,trivial',
                    'estimated_hours' => 'nullable|numeric|min:0',
                    'label_ids' => 'nullable|array',
                    'label_ids.*' => 'exists:labels,id',
                ];

                // Only admins and super_admins can update status
                if ($user->isAdmin() || $user->role === 'super_admin') {
                    $rules['status'] = ['required', Rule::in(Report::STATUSES)];
                }

                $validated = $request->validate($rules, self::PAGE_URL_MESSAGES, self::PAGE_URL_ATTRIBUTES);

                // The due date can be cleared or left as it is, even if it has already passed,
                // but a new date has to be today or later (in Malaysia), as when creating an issue
                $newDueDate = !empty($validated['due_date']) ? \Illuminate\Support\Carbon::parse($validated['due_date'])->toDateString() : null;
                if ($newDueDate !== null && $newDueDate !== $report->due_date?->toDateString() && $newDueDate < Report::businessToday()) {
                    return response()->json([
                        'message' => 'The due date must be today or later.',
                        'errors' => ['due_date' => ['The due date must be today or later.']],
                    ], 422);
                }

                // Capture old values for activity log
                $oldValues = [
                    'title' => $report->title,
                    'description' => $report->description,
                    'page_urls' => $report->page_urls,
                    'due_date' => $report->due_date,
                    'priority' => $report->priority,
                    'type' => $report->type,
                    'severity' => $report->severity,
                    'estimated_hours' => $report->estimated_hours,
                ];

                $type = $validated['type'] ?? $report->type;

                $pageUrls = array_key_exists('page_urls', $validated)
                    ? (empty($validated['page_urls']) ? null : array_values($validated['page_urls']))
                    : $report->page_urls;

                // A feature has no severity, whatever the form sends. A bug uses the one chosen,
                // else the one it already has (including from before it became a feature), else Minor.
                $severity = $type === Report::TYPE_BUG
                    ? ($validated['severity'] ?? $report->storedSeverity() ?? 'minor')
                    : null;

                // Build update data
                $updateData = [
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'page_urls' => $pageUrls,
                    'due_date' => $newDueDate,
                    'priority' => $validated['priority'],
                    'type' => $type,
                    'estimated_hours' => $validated['estimated_hours'] ?? $report->estimated_hours,
                ];

                // Turning a bug into a feature leaves its severity stored, so it can come back
                if ($type === Report::TYPE_BUG) {
                    $updateData['severity'] = $severity;
                }

                $newValues = [
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'page_urls' => $pageUrls,
                    'due_date' => $newDueDate,
                    'priority' => $validated['priority'],
                    'type' => $type,
                    'severity' => $severity,
                    'estimated_hours' => $validated['estimated_hours'] ?? $report->estimated_hours,
                ];

                // Check for status change for notifications
                $statusChanged = false;
                $oldStatus = $report->status;
                $newStatus = $oldStatus;

                // Only update status if provided (admins/super_admins only)
                if (isset($validated['status'])) {
                    $oldValues['status'] = $report->status;
                    $updateData['status'] = $validated['status'];
                    $newValues['status'] = $validated['status'];
                    $statusChanged = ($oldStatus !== $validated['status']);
                    $newStatus = $validated['status'];
                }

                $report->update($updateData);

                // Update labels if provided
                if (isset($validated['label_ids'])) {
                    $report->labels()->sync($validated['label_ids']);
                }

                // Log the update activity with changes
                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => 'updated',
                    'old_values' => $oldValues,
                    'new_values' => $newValues,
                ]);

                // Work out which details actually changed
                $changes = [];
                foreach ($newValues as $field => $newValue) {
                    $oldValue = $oldValues[$field];
                    if ($field === 'due_date') {
                        $oldValue = $oldValue?->toDateString();
                        $newValue = $newValue ? \Illuminate\Support\Carbon::parse($newValue)->toDateString() : null;
                    }
                    // Hours come back from the database as "2.50" but arrive from the form as 2.5
                    $same = is_numeric($oldValue) && is_numeric($newValue)
                        ? (float) $oldValue === (float) $newValue
                        : $oldValue === $newValue;
                    if (!$same) {
                        $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
                    }
                }

                // Send one notification: a status change on its own, otherwise the list of edits
                $report->load('project');
                if ($statusChanged && count($changes) === 1) {
                    app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, $newStatus);
                } elseif (!empty($changes)) {
                    app(NotificationService::class)->notifyReportUpdated($report, $user, $changes);
                }

                $message = 'Issue updated successfully.';
                break;

            case 'close_report':
                // Reports can be closed from most statuses
                if ($report->status === 'closed') {
                    return response()->json(['message' => 'Report is already closed'], 400);
                }

                $oldStatus = $report->status;
                $report->update([
                    'status' => 'closed',
                    'closed_at' => now()
                ]);
                
                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => 'closed',
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => ['status' => 'closed'],
                ]);

                // Send notification for status change
                $report->load('project');
                app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, 'closed');

                $message = 'Issue closed successfully.';
                break;

            case 'add_comment':
                // Text, files, or both: a screenshot or spreadsheet alone is a comment too
                $validated = $request->validate([
                    'comment' => 'nullable|required_without:images|string|max:2000',
                    'images' => 'nullable|array|max:5',
                    'images.*' => $this->commentFileRules(),
                ]);

                $images = $this->prepareAttachments($request->file('images') ?? [], 'images');
                $storedPaths = [];

                try {
                    $comment = DB::transaction(function () use ($report, $user, $validated, $images, &$storedPaths) {
                        $comment = ReportComment::create([
                            'report_id' => $report->id,
                            'user_id' => $user->id,
                            'comment' => trim($validated['comment'] ?? ''),
                        ]);

                        $this->saveAttachments($report, $images, $storedPaths, $comment);

                        return $comment;
                    });
                } catch (\Throwable $exception) {
                    // The comment was not saved, so its files must not be left behind either
                    Storage::disk('public')->delete($storedPaths);

                    throw $exception;
                }

                // Send notification for new comment
                $report->load('project', 'reporter');
                app(NotificationService::class)->notifyCommentAdded($report, $comment, $user);

                $message = 'Comment added successfully.';
                break;

            case 'add_attachments':
                $request->validate([
                    'attachments' => 'required|array|min:1|max:5',
                    'attachments.*' => $this->attachmentRules(),
                ]);

                $storedPaths = [];
                $this->saveAttachments($report, $this->prepareAttachments($request->file('attachments') ?? []), $storedPaths);

                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => 'added_attachments',
                ]);

                $message = 'Attachments uploaded successfully.';
                break;

            default:
                return response()->json(['message' => 'Invalid action'], 400);
        }

        $freshReport = $report->fresh()->load([
            'reporter:id,name,email,role',
            'attachments',
            'activityLogs.user:id,name,email,role',
            'comments.user:id,name,email,role',
            'comments.attachments',
            'labels',
            'timeEntries.user:id,name,email',
            'outgoingLinks.targetReport:id,uuid,title,status,priority',
            'outgoingLinks.creator:id,name',
            'incomingLinks.sourceReport:id,uuid,title,status,priority',
            'incomingLinks.creator:id,name',
        ]);
        $freshReport->total_time_spent = $freshReport->getTotalTimeSpent();

        return response()->json([
            'message' => $message,
            'report' => $freshReport,
            // What this user may do now that the status has changed
            'abilities' => $freshReport->abilitiesFor($user),
        ]);
    }

    /**
     * What may be attached to an issue: images, PDFs and Excel or CSV spreadsheets up to 5 MB, and
     * MP4, WebM or MOV videos up to 25 MB. The type is judged from the file's content, not its name.
     * Images are then checked again when they are decoded.
     */
    private function attachmentRules(): array
    {
        return [
            'file',
            'mimes:jpeg,jpg,png,gif,webp,pdf,mp4,webm,mov,' . self::SPREADSHEET_EXTENSIONS,
            'max:' . self::MAX_VIDEO_KB,
            function (string $attribute, mixed $file, \Closure $fail) {
                if (!in_array($file->getMimeType(), self::VIDEO_MIMES, true) && $file->getSize() > self::MAX_ATTACHMENT_KB * 1024) {
                    $fail('Images, PDFs and spreadsheets must be 5 MB or smaller.');
                }
            },
            $this->plainTextOnlyAsCsv(),
        ];
    }

    /**
     * What may be posted with a comment: images, and Excel (.xlsx, .xls) or CSV spreadsheets, up to 5 MB.
     */
    private function commentFileRules(): array
    {
        return [
            'file',
            'mimes:jpeg,jpg,png,gif,webp,' . self::SPREADSHEET_EXTENSIONS,
            'max:' . self::MAX_ATTACHMENT_KB,
            $this->plainTextOnlyAsCsv(),
        ];
    }

    /**
     * A CSV often reads as plain text, so plain text is only taken under a .csv name.
     */
    private function plainTextOnlyAsCsv(): \Closure
    {
        return function (string $attribute, mixed $file, \Closure $fail) {
            if ($file->guessExtension() === 'txt' && strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $fail('Plain text files can only be attached as CSV (.csv) files.');
            }
        };
    }

    /**
     * Check every uploaded file and get it ready to store. Images are validated by their real
     * content, scaled down if very large and converted to WebP; PDFs, videos and spreadsheets are left exactly as they are.
     * Nothing is written yet.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{file: UploadedFile, image: ?ProcessedImage}>
     *
     * @throws ValidationException naming the file that cannot be used
     */
    private function prepareAttachments(array $files, string $field = 'attachments'): array
    {
        $processor = app(AttachmentImageProcessor::class);
        $prepared = [];

        foreach ($files as $file) {
            try {
                $prepared[] = ['file' => $file, 'image' => $processor->handles($file) ? $processor->process($file) : null];
            } catch (InvalidImageException $exception) {
                throw ValidationException::withMessages([
                    $field => ['"' . $file->getClientOriginalName() . '" ' . $exception->getMessage()],
                ]);
            }
        }

        return $prepared;
    }

    /**
     * Store prepared attachments on the public disk and record them against the report,
     * and against the comment they were posted with, if any.
     *
     * @param  array<int, array{file: UploadedFile, image: ?ProcessedImage}>  $attachments
     * @param  array<int, string>  $storedPaths  Filled with every path written, for cleaning up after a failure
     */
    private function saveAttachments(Report $report, array $attachments, array &$storedPaths, ?ReportComment $comment = null): void
    {
        $disk = Storage::disk('public');
        $directory = 'reports/' . $report->id;

        foreach ($attachments as ['file' => $file, 'image' => $image]) {
            $originalName = $file->getClientOriginalName();

            if ($image) {
                $filename = Str::random(40) . '.' . $image->extension;
                $storagePath = $directory . '/' . $filename;
                $stored = $disk->put($storagePath, $image->contents);

                // "screenshot.png" is now a WebP file, so its name says so too
                if ($image->converted) {
                    $originalName = pathinfo($originalName, PATHINFO_FILENAME) . '.' . $image->extension;
                }
            } else {
                // Named by what the content really is, so the file is never served as something else.
                // Plain text only gets this far as a CSV, so it is kept as one.
                $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
                if ($extension === 'txt') {
                    $extension = 'csv';
                }
                $filename = Str::random(40) . '.' . $extension;
                $storagePath = $file->storeAs($directory, $filename, 'public');
                $stored = $storagePath !== false;
            }

            if (!$stored) {
                $disk->delete($storedPaths);

                throw new \RuntimeException('Could not store the attachment "' . $file->getClientOriginalName() . '".');
            }

            $storedPaths[] = $storagePath;

            ReportAttachment::create([
                'report_id' => $report->id,
                'report_comment_id' => $comment?->id,
                'filename' => $filename,
                'original_filename' => $originalName,
                'mime_type' => $image ? $image->mimeType : (str_ends_with($filename, '.csv') ? 'text/csv' : $file->getMimeType()),
                'file_size' => $image ? $image->size() : $file->getSize(),
                'storage_path' => $storagePath,
            ]);
        }
    }

    public function destroy(Report $report): JsonResponse
    {
        $user = Auth::user();

        if (!$report->abilitiesFor($user)['delete']) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        foreach ($report->allAttachments as $attachment) {
            Storage::disk('public')->delete($attachment->storage_path);
        }

        app(NotificationService::class)->notifyReportDeleted($report, $user);

        $report->delete();

        return response()->json(['message' => 'Issue deleted successfully.']);
    }
}
