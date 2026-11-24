<?php

namespace App\Catalog\Domain\Service;

/** Plain review content rules and the rating summary arithmetic shared by every consumer. */
final class ReviewRules
{
    public const MIN_RATING = 1;

    public const MAX_RATING = 5;

    public const TITLE_MAX = 150;

    public const BODY_MAX = 5000;

    public const NOTE_MAX = 2000;

    /** Trims and unifies line breaks; blank text becomes null. */
    public function normalize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        return $text === '' ? null : $text;
    }

    /** Meaningful text contains at least one letter or digit (punctuation or symbols alone are not a review). */
    public function meaningful(string $text): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $text) === 1;
    }

    /**
     * Summary of approved ratings: count, average rounded half up to one decimal (exact integer arithmetic) and the
     * per-star counts. No reviews means no average, never a zero rating.
     *
     * @param  array<int, int>  $counts  stars => number of approved reviews
     * @return array{count: int, average: ?string, distribution: array{1: int, 2: int, 3: int, 4: int, 5: int}}
     */
    public function summary(array $counts): array
    {
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($counts as $stars => $count) {
            if (isset($distribution[$stars]) && $count > 0) {
                $distribution[$stars] += $count;
            }
        }
        $total = array_sum($distribution);
        if ($total === 0) {
            return ['count' => 0, 'average' => null, 'distribution' => $distribution];
        }
        $sum = 0;
        foreach ($distribution as $stars => $count) {
            $sum += $stars * $count;
        }
        $tenths = intdiv(20 * $sum + $total, 2 * $total);

        return ['count' => $total, 'average' => intdiv($tenths, 10).'.'.($tenths % 10), 'distribution' => $distribution];
    }

    /** Public author label: first name and last initial, never the full name or e-mail. */
    public function authorName(string $firstName, string $lastName): string
    {
        $first = trim($firstName);
        $initial = mb_strtoupper(mb_substr(trim($lastName), 0, 1));

        return $initial === '' ? $first : $first.' '.$initial.'.';
    }
}
