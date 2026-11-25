<?php

namespace App\Catalog\Domain\Model;

/** Moderation state of a product review. Only Approved reviews are public or count towards ratings. */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    /**
     * Approve only what awaits moderation; reject pending or published reviews; anything not already spam can be
     * marked as spam; rejected and spam reviews can return to Pending for another decision.
     */
    public function canBecome(self $target): bool
    {
        return match ($target) {
            self::Approved => $this === self::Pending,
            self::Rejected => $this === self::Pending || $this === self::Approved,
            self::Spam => $this !== self::Spam,
            self::Pending => $this === self::Rejected || $this === self::Spam,
        };
    }

    /** @return list<string> */
    public function targets(): array
    {
        return array_values(array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => $this->canBecome($s))));
    }
}
