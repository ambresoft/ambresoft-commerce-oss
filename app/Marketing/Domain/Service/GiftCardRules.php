<?php

namespace App\Marketing\Domain\Service;

use App\Shared\Domain\Service\Decimal;

/**
 * Gift card lifecycle and arithmetic. A card is pending (issued, not yet usable), active, cancelled, or used. "Used"
 * belongs to the system: a card becomes used when its balance reaches zero and is never chosen by staff. A used card
 * is final, because it has nothing left to give.
 */
final class GiftCardRules
{
    public const STATUSES = ['pending', 'active', 'cancelled', 'used'];

    /** What staff may choose. */
    public const MANUAL = ['pending', 'active', 'cancelled'];

    /** Without characters that are easily misread (0/O, 1/I). */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const CODE_PATTERN = '/^GC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/D';

    /**
     * @param  callable(int): int  $pick  Returns a uniformly random index below the given bound.
     */
    public static function code(callable $pick): string
    {
        $group = fn (): string => implode('', array_map(fn (): string => self::ALPHABET[$pick(strlen(self::ALPHABET))], range(1, 4)));

        return 'GC-'.$group().'-'.$group().'-'.$group();
    }

    /** What a person typed, as the stored form: no surrounding space, capitals. Not validated; see isCode(). */
    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function isCode(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }

    public static function canChange(string $from, string $to): bool
    {
        return $from !== 'used' && in_array($to, self::MANUAL, true);
    }

    public static function canRedeem(string $status): bool
    {
        return $status === 'active';
    }

    /** Why a card of this status cannot pay (a giftcards translation key), or null when it can. */
    public static function refusal(string $status): ?string
    {
        return match ($status) {
            'active' => null, 'used' => 'not_usable_used', 'cancelled' => 'not_usable_cancelled', default => 'not_usable_pending',
        };
    }

    /** What a card pays towards an order: as much of the amount due as the balance covers, never more. */
    public static function applicable(string $balance, string $outstanding): string
    {
        $amount = Decimal::compare($balance, $outstanding) < 0 ? $balance : $outstanding;

        return Decimal::compare($amount, '0') > 0 ? Decimal::trim($amount) : '0';
    }

    /** Zero balance means used; anything else keeps the status it had. */
    public static function afterRedemption(string $status, string $balance): string
    {
        return Decimal::compare($balance, '0') <= 0 ? 'used' : $status;
    }

    /** The code with all but its last group hidden, for places where the full code must not appear. */
    public static function masked(string $code): string
    {
        return 'GC-••••-••••-'.substr($code, -4);
    }
}
