<?php

/**
 * List Sidra Desk issues (the `reports` table) from the database the app's .env points at.
 * Read-only: it never changes anything.
 *
 * Run from the app's root folder:
 *   php .claude/skills/list-issues/scripts/list_issues.php [options]
 *
 * Options (all optional, combine freely; lists are comma-separated):
 *   --status=open,in_progress   open | in_progress | resolved | closed | reopened | active | finished | all
 *                               (default: active = open, in_progress, reopened)
 *   --project=web               project name or prefix contains this (case-insensitive)
 *   --company=acme              project's company name contains this
 *   --priority=high,urgent      low | medium | high | urgent
 *   --type=bug                  bug | feature
 *   --severity=critical,major   critical | major | minor | trivial
 *   --reporter=ben              reporter name or email contains this
 *   --label=frontend            has a label whose name contains this
 *   --search=checkout           title, description or a page URL contains this
 *   --overdue                   only issues past their due date that are not resolved or closed
 *   --since=2026-10-01          created on or after this date (also: 7d, 2w, today)
 *   --key=AW-6                  one issue in full (description, page URLs, labels, recent activity)
 *   --sort=created              created (newest first, default) | priority | due | updated
 *   --limit=50                  at most this many rows (default 50)
 *   --details                   add page URLs and labels to each row
 *   --json                      machine-readable output instead of a table
 */

use App\Models\Report;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

$root = getcwd();
if (! file_exists($root . '/artisan') || ! file_exists($root . '/bootstrap/app.php')) {
    fwrite(STDERR, "Run this from the Laravel app's root folder (the one containing artisan).\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// ---- Options ---------------------------------------------------------------

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (! str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
    [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
    $options[$name] = $value;
}

$known = ['status', 'project', 'company', 'priority', 'type', 'severity', 'reporter', 'label', 'search', 'overdue', 'since', 'key', 'sort', 'limit', 'details', 'json'];
foreach (array_keys($options) as $name) {
    if (! in_array($name, $known, true)) {
        fwrite(STDERR, "Unknown option --{$name}. Known: --" . implode(', --', $known) . "\n");
        exit(1);
    }
}

$list = fn (string $name) => isset($options[$name]) && $options[$name] !== true
    ? array_values(array_filter(array_map(fn ($v) => strtolower(trim($v)), explode(',', $options[$name]))))
    : [];
$text = fn (string $name) => isset($options[$name]) && $options[$name] !== true ? trim($options[$name]) : null;
$like = fn (string $value) => '%' . mb_strtolower($value) . '%';
$asJson = isset($options['json']);

// Say whose data this is, so local and production numbers are never confused
if (! $asJson) {
    $database = config('database.connections.' . config('database.default') . '.database');
    echo "Database: {$database} (" . config('app.env') . ")\n";
}

// ---- One issue in full -----------------------------------------------------

if ($key = $text('key')) {
    $report = Report::with(['project.company', 'reporter', 'labels', 'activityLogs' => fn ($q) => $q->latest('id')->limit(10), 'activityLogs.user'])
        ->whereRaw('LOWER(issue_key) = ?', [mb_strtolower($key)])
        ->first();

    if (! $report) {
        fwrite(STDERR, "No issue with key {$key}.\n");
        exit(1);
    }

    $data = [
        'key' => $report->issue_key,
        'title' => $report->title,
        'type' => $report->type,
        'status' => $report->status,
        'priority' => $report->priority,
        'severity' => $report->type === Report::TYPE_BUG ? $report->severity : null,
        'project' => $report->project?->name,
        'company' => $report->project?->company?->name,
        'reporter' => $report->reporter ? "{$report->reporter->name} <{$report->reporter->email}>" : null,
        'created' => $report->created_at?->toDateTimeString(),
        'updated' => $report->updated_at?->toDateTimeString(),
        'due' => $report->due_date?->toDateString(),
        'overdue' => $report->isOverdue(),
        'page_urls' => $report->page_urls ?? [],
        'labels' => $report->labels->pluck('name')->all(),
        'resolution_summary' => $report->resolution_summary,
        'description' => $report->description,
        'recent_activity' => $report->activityLogs->map(fn ($log) => [
            'at' => $log->created_at?->toDateTimeString(),
            'by' => $log->user?->name,
            'action' => $log->action,
        ])->all(),
        'link' => rtrim(config('app.url'), '/') . '/reports/' . $report->uuid,
    ];

    if ($asJson) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }

    echo "\n## {$data['key']}: {$data['title']}\n\n";
    foreach (['type', 'status', 'priority', 'severity', 'project', 'company', 'reporter', 'created', 'updated', 'due', 'link'] as $field) {
        if ($data[$field] !== null && $data[$field] !== '') {
            echo '- **' . ucfirst($field) . ':** ' . $data[$field] . ($field === 'due' && $data['overdue'] ? ' (overdue)' : '') . "\n";
        }
    }
    echo '- **Page URLs:** ' . ($data['page_urls'] ? "\n" . implode("\n", array_map(fn ($u) => "  - {$u}", $data['page_urls'])) : 'none') . "\n";
    echo '- **Labels:** ' . ($data['labels'] ? implode(', ', $data['labels']) : 'none') . "\n";
    echo "\n### Description\n\n{$data['description']}\n";
    if ($data['resolution_summary']) {
        echo "\n### Resolution\n\n{$data['resolution_summary']}\n";
    }
    if ($data['recent_activity']) {
        echo "\n### Recent activity (newest first)\n\n";
        foreach ($data['recent_activity'] as $entry) {
            echo "- {$entry['at']} {$entry['by']}: {$entry['action']}\n";
        }
    }
    exit(0);
}

// ---- Filters ---------------------------------------------------------------

$query = Report::query()->with(['project.company', 'reporter', 'labels']);

$statuses = $list('status') ?: ['active'];
if (! in_array('all', $statuses, true)) {
    $expanded = [];
    foreach ($statuses as $status) {
        $expanded = array_merge($expanded, match ($status) {
            'active' => ['open', 'in_progress', 'reopened'],
            'finished', 'done' => ['resolved', 'closed'],
            'in progress', 'in-progress', 'inprogress' => ['in_progress'],
            default => [$status],
        });
    }
    $unknown = array_diff($expanded, Report::STATUSES);
    if ($unknown) {
        fwrite(STDERR, 'Unknown status: ' . implode(', ', $unknown) . '. Use: ' . implode(', ', Report::STATUSES) . ", active, finished, all\n");
        exit(1);
    }
    $query->whereIn('status', array_unique($expanded));
}

foreach (['priority', 'type', 'severity'] as $field) {
    if ($values = $list($field)) {
        $query->whereIn($field, $values);
    }
}

if ($project = $text('project')) {
    $query->whereHas('project', fn ($q) => $q->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like($project)])
        ->orWhereRaw('LOWER(prefix) LIKE ?', [$like($project)])));
}
if ($company = $text('company')) {
    $query->whereHas('project.company', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like($company)]));
}
if ($reporter = $text('reporter')) {
    $query->whereHas('reporter', fn ($q) => $q->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like($reporter)])
        ->orWhereRaw('LOWER(email) LIKE ?', [$like($reporter)])));
}
if ($label = $text('label')) {
    $query->whereHas('labels', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like($label)]));
}
if ($search = $text('search')) {
    $query->where(fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', [$like($search)])
        ->orWhereRaw('LOWER(description) LIKE ?', [$like($search)])
        ->orWhereRaw('LOWER(CAST(page_urls AS TEXT)) LIKE ?', [$like($search)]));
}
if (isset($options['overdue'])) {
    $query->overdue();
}
if ($since = $text('since')) {
    try {
        $from = match (true) {
            $since === 'today' => Carbon::today(),
            (bool) preg_match('/^(\d+)d$/', $since, $m) => Carbon::today()->subDays((int) $m[1]),
            (bool) preg_match('/^(\d+)w$/', $since, $m) => Carbon::today()->subWeeks((int) $m[1]),
            default => Carbon::parse($since)->startOfDay(),
        };
    } catch (Throwable) {
        fwrite(STDERR, "Could not read --since={$since}. Use a date (2026-10-01), 7d, 2w or today.\n");
        exit(1);
    }
    $query->where('created_at', '>=', $from);
}

$total = (clone $query)->count();

match ($text('sort') ?? 'created') {
    'priority' => $query->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->latest('created_at'),
    'due' => $query->orderByRaw('due_date IS NULL')->orderBy('due_date')->latest('created_at'),
    'updated' => $query->latest('updated_at'),
    default => $query->latest('created_at'),
};

$limit = max(1, (int) ($text('limit') ?? 50));
$reports = $query->limit($limit)->get();
$details = isset($options['details']);

$rows = $reports->map(fn (Report $r) => [
    'key' => $r->issue_key,
    'title' => $r->title,
    'type' => $r->type,
    'status' => $r->status,
    'priority' => $r->priority,
    'severity' => $r->type === Report::TYPE_BUG ? $r->severity : null,
    'project' => $r->project?->name,
    'reporter' => $r->reporter?->name,
    'created' => $r->created_at?->toDateString(),
    'due' => $r->due_date?->toDateString(),
    'overdue' => $r->isOverdue(),
    'page_urls' => $r->page_urls ?? [],
    'labels' => $r->labels->pluck('name')->all(),
])->all();

if ($asJson) {
    echo json_encode(['total' => $total, 'shown' => count($rows), 'issues' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// ---- Table -----------------------------------------------------------------

if ($total === 0) {
    echo "No issues match.\n";
    exit(0);
}

$cell = fn ($value) => str_replace(['|', "\n"], ['\\|', ' '], (string) $value);
$headers = ['Key', 'Title', 'Type', 'Status', 'Priority', 'Severity', 'Project', 'Reporter', 'Created', 'Due'];
if ($details) {
    array_push($headers, 'Page URLs', 'Labels');
}
echo '| ' . implode(' | ', $headers) . " |\n";
echo '|' . str_repeat('---|', count($headers)) . "\n";
foreach ($rows as $row) {
    $title = mb_strlen($row['title']) > 60 ? mb_substr($row['title'], 0, 59) . '…' : $row['title'];
    $cells = [
        $row['key'], $title, $row['type'], Report::STATUS_LABELS[$row['status']] ?? $row['status'], $row['priority'],
        $row['severity'] ?? '–', $row['project'] ?? '–', $row['reporter'] ?? '–', $row['created'],
        $row['due'] ? $row['due'] . ($row['overdue'] ? ' ⚠ overdue' : '') : '–',
    ];
    if ($details) {
        $cells[] = $row['page_urls'] ? implode(' , ', $row['page_urls']) : '–';
        $cells[] = $row['labels'] ? implode(', ', $row['labels']) : '–';
    }
    echo '| ' . implode(' | ', array_map($cell, $cells)) . " |\n";
}

$byStatus = collect($rows)->countBy('status')->map(fn ($n, $s) => (Report::STATUS_LABELS[$s] ?? $s) . ": {$n}")->implode(', ');
echo "\nShowing " . count($rows) . " of {$total}" . ($total > count($rows) ? " (raise --limit to see more)" : '') . ". By status (shown): {$byStatus}.\n";
