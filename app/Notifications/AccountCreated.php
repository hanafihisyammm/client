<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class AccountCreated extends AppNotification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public User $createdBy
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
            'Your Account Has Been Created',
            'Welcome to ' . config('app.name'),
            $this->createdBy->name . ' created an account for you.',
            [
                'Login email' => $notifiable->email,
                'Role' => $this->humanize($notifiable->role),
            ],
            'Log In',
            '/login',
            outroLines: ['Your administrator will share your password with you. You can also set your own with "Forgot password?" on the login page.']
        );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Welcome, ' . $notifiable->name . '!',
            'message' => 'Your account was created by ' . $this->createdBy->name . '. Updates on your projects and reports will appear here.',
            'url' => '/dashboard',
            'created_by' => $this->createdBy->name,
        ];
    }
}
