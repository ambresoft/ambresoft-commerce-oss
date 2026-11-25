<?php

namespace App\Identity\Infrastructure\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class StaffInvitation extends Notification
{
    public function __construct(public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('auth.staff_invitation_subject'))
            ->line(__('auth.staff_invitation_intro'))->action(__('auth.staff_invitation_action'), $this->url)
            ->line(__('auth.staff_invitation_expiry'));
    }
}
