<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class ReportUpdated extends AppNotification
{
    /**
     * Create a new notification instance.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function __construct(
        public Report $report,
        public User $updatedBy,
        public array $changes
    ) {
        //
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->buildMail(
            $notifiable,
            'Report Updated: ' . $this->report->title,
            'Report details updated',
            $this->updatedBy->name . ' edited a report.',
            [
                'Report' => $this->report->title,
                'Project' => $this->report->project->name ?? 'Unknown Project',
                'Updated by' => $this->nameWithRole($this->updatedBy),
                ...$this->formatChanges(),
            ],
            'View Report',
            '/reports/' . $this->report->uuid
        );
    }

    /**
     * Format the changes as detail rows: field => "old → new".
     *
     * @return array<string, string>
     */
    private function formatChanges(): array
    {
        $formattedChanges = [];

        foreach ($this->changes as $field => $change) {
            $fieldName = ucfirst(str_replace('_', ' ', $field));

            $formattedChanges[$fieldName] = match ($field) {
                'description' => Str::limit((string) $change['new'], 200),
                'title' => $change['old'] . ' → ' . $change['new'],
                default => $this->formatValue($change['old']) . ' → ' . $this->formatValue($change['new']),
            };
        }

        return $formattedChanges;
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'None';
        }

        return ucwords(str_replace('_', ' ', (string) $value));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $fields = array_map(fn ($field) => str_replace('_', ' ', $field), array_keys($this->changes));

        return [
            'title' => 'Report updated: ' . $this->report->title,
            'message' => $this->updatedBy->name . ' changed the ' . implode(', ', $fields) . '.',
            'url' => '/reports/' . $this->report->uuid,
            'report_id' => $this->report->id,
            'report_title' => $this->report->title,
            'project_name' => $this->report->project?->name,
            'updated_by' => $this->updatedBy->name,
            'updated_by_role' => $this->updatedBy->role,
            'changes' => $this->changes,
        ];
    }
}
