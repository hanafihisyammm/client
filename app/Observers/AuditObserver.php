<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditObserver
{
    /**
     * Attributes that change on their own and say nothing about what the user did.
     */
    private const IGNORED = ['updated_at', 'remember_token', 'next_issue_number', 'response_sla_breached', 'resolution_sla_breached'];

    /**
     * Attributes whose values must never be written to the log.
     */
    private const HIDDEN = ['password', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function created(Model $model): void
    {
        $this->log($model, 'created', 'Created ' . $this->describe($model));
    }

    public function updated(Model $model): void
    {
        $changes = [];

        foreach ($model->getChanges() as $field => $newValue) {
            if (in_array($field, self::IGNORED, true)) {
                continue;
            }

            $changes[$field] = in_array($field, self::HIDDEN, true)
                ? ['old' => '[hidden]', 'new' => '[hidden]']
                : ['old' => $this->format($model->getRawOriginal($field)), 'new' => $this->format($newValue)];
        }

        if (empty($changes)) {
            return;
        }

        $description = 'Updated ' . $this->describe($model);
        if (isset($changes['status']) && empty(array_diff(array_keys($changes), ['status', 'closed_at']))) {
            $description = 'Changed status of ' . $this->describe($model)
                . ' from ' . $this->humanize($changes['status']['old'])
                . ' to ' . $this->humanize($changes['status']['new']);
        }

        $this->log($model, 'updated', $description, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->log($model, 'deleted', 'Deleted ' . $this->describe($model));
    }

    private function log(Model $model, string $event, string $description, ?array $changes = null): void
    {
        AuditLog::record(
            $this->type($model) . '.' . $event,
            $description,
            $this->type($model),
            $model->getKey(),
            $this->label($model),
            $changes
        );
    }

    private function type(Model $model): string
    {
        return match (true) {
            $model instanceof User => 'user',
            $model instanceof Project => 'project',
            $model instanceof Report => 'report',
            $model instanceof ReportComment => 'comment',
            default => Str::snake(class_basename($model)),
        };
    }

    private function label(Model $model): string
    {
        return match (true) {
            $model instanceof User => $model->name . ' (' . $model->email . ')',
            $model instanceof Project => $model->name,
            $model instanceof Report => $model->title,
            $model instanceof ReportComment => $model->report?->title ?? 'Unknown report',
            default => (string) $model->getKey(),
        };
    }

    private function describe(Model $model): string
    {
        if ($model instanceof ReportComment) {
            return 'comment on report "' . $this->label($model) . '"';
        }

        if ($model instanceof User) {
            return 'user ' . $this->label($model);
        }

        return $this->type($model) . ' "' . $this->label($model) . '"';
    }

    private function format(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return is_string($value) ? Str::limit($value, 200) : $value;
    }

    private function humanize(mixed $value): string
    {
        return $value === null || $value === '' ? 'none' : ucfirst(str_replace('_', ' ', (string) $value));
    }
}
