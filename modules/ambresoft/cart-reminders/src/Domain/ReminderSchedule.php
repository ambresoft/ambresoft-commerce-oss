<?php

namespace Ambresoft\CartReminders\Domain;

use DateTimeImmutable;

/** When a cart's next reminder is due: after the delay, then every interval, up to the maximum per abandonment. */
final readonly class ReminderSchedule
{
    public function __construct(public int $delayHours, public int $maxReminders, public int $repeatHours) {}

    /**
     * @param  int  $sent  Reminders already sent for this cart's current contents (0: none, or the cart changed since)
     */
    public function due(DateTimeImmutable $cartUpdatedAt, int $sent, ?DateTimeImmutable $lastSentAt, DateTimeImmutable $now): bool
    {
        if ($sent >= $this->maxReminders || $cartUpdatedAt > $now->modify('-'.$this->delayHours.' hours')) {
            return false;
        }

        return $sent === 0 || $lastSentAt === null || $lastSentAt <= $now->modify('-'.$this->repeatHours.' hours');
    }
}
