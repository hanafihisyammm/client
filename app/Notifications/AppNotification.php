<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Number of times the queued email is attempted before it is marked as failed.
     */
    public int $tries = 3;

    /**
     * Drop the queued email if the report/project was deleted before it was sent.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * In-app notifications are stored immediately; only the email waits for the queue worker.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /**
     * Build an email on the shared branded layout: a heading, one intro sentence,
     * a list of details, an optional quoted message and one button.
     *
     * @param  array<string, string|null>  $details  label => value; empty values are left out
     * @param  array<int, string>  $outroLines
     */
    protected function buildMail(
        object $notifiable,
        string $subject,
        string $heading,
        string $intro,
        array $details = [],
        ?string $actionText = null,
        ?string $actionPath = null,
        ?string $quote = null,
        ?string $quoteLabel = null,
        array $outroLines = []
    ): MailMessage {
        $details = collect($details)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => $this->tableCell((string) $value))
            ->all();

        return (new MailMessage)
            ->subject($subject)
            ->markdown('emails.notification', [
                'heading' => $heading,
                'name' => $notifiable->name,
                'intro' => $intro,
                'details' => $details,
                'quote' => $quote ? Str::limit(trim($quote), 600) : null,
                'quoteLabel' => $quoteLabel,
                'outroLines' => $outroLines,
                'actionText' => $actionText,
                'actionUrl' => $actionPath ? url($actionPath) : null,
            ]);
    }

    /**
     * A user's name with a readable role, e.g. "Jane Tan (Super Admin)".
     */
    protected function nameWithRole(User $user): string
    {
        return $user->name . ' (' . $this->humanize($user->role) . ')';
    }

    protected function humanize(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    /**
     * Keep a value on one line and stop it breaking the details table.
     */
    private function tableCell(string $value): string
    {
        $value = Str::limit(trim(preg_replace('/\s+/', ' ', $value)), 200);

        return str_replace('|', '\\|', $value);
    }
}
