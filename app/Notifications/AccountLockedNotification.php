<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** FR-005 — tell the account owner their account was locked. */
class AccountLockedNotification extends Notification
{
    public function __construct(private readonly int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your account was locked')
            ->line("After several failed sign-in attempts, your account is locked for {$this->minutes} minutes.")
            ->line("If this wasn't you, reset your password and tell the records office.")
            ->action('Reset password', route('password.request'));
    }
}
