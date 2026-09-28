<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeAccount extends Notification
{
    /** @return array<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject('Welcome to Scrutium')
            ->view('emails.welcome', [
                'workspace_name' => $notifiable->tenant->name,
                'user_name' => $notifiable->name,
                'user_id' => $notifiable->getKey(),
            ]);
    }
}
