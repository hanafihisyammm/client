<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class UserInvited extends AppNotification
{
    /**
     * How long an invitation link stays valid.
     */
    public const EXPIRES_IN_DAYS = 7;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public User $invitedBy
    ) {
        //
    }

    /**
     * The invited user cannot log in yet, so this is sent by email only.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Signed without the host so the link still validates behind a proxy or after a domain change
        $link = URL::temporarySignedRoute(
            'invitation.show',
            now()->addDays(self::EXPIRES_IN_DAYS),
            ['user' => $notifiable->uuid],
            absolute: false
        );

        return $this->buildMail(
            $notifiable,
            'You Have Been Invited to ' . config('app.name'),
            'Set up your ' . config('app.name') . ' account',
            $this->invitedBy->name . ' invited you to ' . config('app.name') . '. Choose your password to finish setting up your account.',
            [
                'Login email' => $notifiable->email,
                'Role' => $this->humanize($notifiable->role),
                'Invited by' => $this->nameWithRole($this->invitedBy),
            ],
            'Set Up Password',
            $link,
            outroLines: ['This link expires in ' . self::EXPIRES_IN_DAYS . ' days. If it has expired, ask your administrator to send a new invitation.']
        );
    }
}
