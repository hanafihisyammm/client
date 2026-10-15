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
    /**
     * What may be attached to an issue: images and PDFs, up to 5 MB each. The type is judged
     * from the file's content, not its name. Images are then checked again when they are decoded.
     */
    private const ATTACHMENT_RULES = 'file|mimes:jpeg,jpg,png,gif,webp,pdf|max:5120';

    /** What may be posted with a comment: images only, with the same size limit */
    private const COMMENT_IMAGE_RULES = 'file|mimes:jpeg,jpg,png,gif,webp|max:5120';

    /** The address of the page an issue was seen on: a full web address, as copied from the browser */
    private const PAGE_URL_RULES = ['required', 'string', 'max:2048', 'url:http,https'];

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
            // The page the issue was seen on, so it can be found and debugged
            'page_url' => self::PAGE_URL_RULES,
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
            'attachments.*' => self::ATTACHMENT_RULES,
        ], [], ['page_url' => 'page URL']);

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
                'page_url' => $validated['page_url'],
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
                    // Left out, the page URL stays as it is. Issues from before it was asked for
                    // may stay without one, but once an issue has a URL it cannot be cleared.
                    'page_url' => $report->page_url === null
                        ? ['nullable', ...array_slice(self::PAGE_URL_RULES, 1)]
                        : ['sometimes', ...self::PAGE_URL_RULES],
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

                $validated = $request->validate($rules, [], ['page_url' => 'page URL']);

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
                    'page_url' => $report->page_url,
                    'due_date' => $report->due_date,
                    'priority' => $report->priority,
                    'type' => $report->type,
                    'severity' => $report->severity,
                    'estimated_hours' => $report->estimated_hours,
                ];

                $type = $validated['type'] ?? $report->type;

                // A feature has no severity, whatever the form sends. A bug uses the one chosen,
                // else the one it already has (including from before it became a feature), else Minor.
                $severity = $type === Report::TYPE_BUG
                    ? ($validated['severity'] ?? $report->storedSeverity() ?? 'minor')
                    : null;

                // Build update data
                $updateData = [
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'page_url' => $validated['page_url'] ?? $report->page_url,
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
                    'page_url' => $validated['page_url'] ?? $report->page_url,
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
                // Text, images, or both: a screenshot alone is a comment too
                $validated = $request->validate([
                    'comment' => 'nullable|required_without:images|string|max:2000',
                    'images' => 'nullable|array|max:5',
                    'images.*' => self::COMMENT_IMAGE_RULES,
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
                    // The comment was not saved, so its images must not be left behind either
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
                    'attachments.*' => self::ATTACHMENT_RULES,
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
     * Check every uploaded file and get it ready to store. Images are validated by their real
     * content, scaled down if very large and converted to WebP; PDFs are left exactly as they are.
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
                $filename = Str::random(40) . '.' . $file->getClientOriginalExtension();
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
                'mime_type' => $image ? $image->mimeType : $file->getMimeType(),
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
