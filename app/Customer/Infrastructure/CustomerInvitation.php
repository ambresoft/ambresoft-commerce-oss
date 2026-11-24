<?php

namespace App\Customer\Infrastructure;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail inviting a staff-created customer to set the password of their account. */
final class CustomerInvitation extends Notification
{
    public function __construct(public readonly string $url, private string $store) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('account.invitation_subject', ['store' => $this->store]))
            ->line(__('account.invitation_intro', ['store' => $this->store]))
            ->action(__('account.invitation_action'), $this->url)
            ->line(__('account.invitation_expiry', ['days' => CustomerInvitations::DAYS]));
    }
}
