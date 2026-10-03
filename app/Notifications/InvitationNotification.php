<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Set up your Pilot License Monitor account')
            ->line('The records office has created an account for you to track your licenses and certificates.')
            ->action('Set your password', route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]))
            ->line('This link expires in 30 minutes. Ask the records office for a new one if it has expired.');
    }
}
