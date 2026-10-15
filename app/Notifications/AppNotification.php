<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Number of times a queued delivery is attempted before it is marked as failed.
     */
    public int $tries = 3;

    /**
     * Drop a queued delivery if the report/project was deleted before it was sent.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Get the notification's delivery channels. Activity is not emailed: it shows in the app
     * and as a phone / desktop alert.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        // Also as an alert on the phones and computers where the user turned alerts on
        if ($notifiable instanceof User && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * The phone / desktop alert: the same title, message and link as the in-app notification.
     */
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);
        $url = (string) ($data['url'] ?? '');

        $message = (new WebPushMessage)
            ->title(Str::limit((string) ($data['title'] ?? config('app.name')), 120))
            ->body(Str::limit((string) ($data['message'] ?? ''), 200))
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-96.png')
            ->data([
                // Only a path on this site; the service worker opens nothing else
                'url' => str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : '/dashboard',
                'id' => $this->id,
                'unread' => $notifiable instanceof User ? $notifiable->unreadNotifications()->count() : null,
            ])
            // Kept by the push service for a day if the device is off, then dropped
            ->options(['TTL' => 86400, 'urgency' => 'normal']);

        // A newer alert about the same notification replaces the older one
        if ($this->id) {
            $message->tag($this->id);
        }

        return $message;
    }

    /**
     * In-app notifications are stored immediately; only the alert waits for the queue worker.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /**
     * Build an email (only invitations are emailed) on the shared branded layout: a heading, one intro sentence,
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
