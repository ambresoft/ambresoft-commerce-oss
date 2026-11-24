<?php

namespace Tests\Unit;

use App\Catalog\Domain\Model\ReviewStatus;
use App\Catalog\Domain\Service\ReviewRules;
use PHPUnit\Framework\TestCase;

final class ReviewRulesTest extends TestCase
{
    public function test_status_transitions_follow_the_moderation_workflow(): void
    {
        $allowed = [];
        foreach (ReviewStatus::cases() as $from) {
            $allowed[$from->value] = $from->targets();
        }
        self::assertSame([
            'pending' => ['approved', 'rejected', 'spam'],
            'approved' => ['rejected', 'spam'],
            'rejected' => ['pending', 'spam'],
            'spam' => ['pending'],
        ], $allowed);
    }

    public function test_summary_uses_exact_half_up_rounding_and_no_average_without_reviews(): void
    {
        $rules = new ReviewRules;
        self::assertSame(['count' => 0, 'average' => null, 'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]], $rules->summary([]));
        self::assertSame('4.3', $rules->summary([5 => 1, 4 => 2])['average']);
        self::assertSame('4.7', $rules->summary([5 => 2, 4 => 1])['average']);
        // 4.25 and 4.05 sit exactly on a rounding boundary and round up.
        self::assertSame('4.3', $rules->summary([5 => 1, 4 => 3])['average']);
        self::assertSame('4.1', $rules->summary([5 => 1, 4 => 19])['average']);
        self::assertSame('1.0', $rules->summary([1 => 7])['average']);
        self::assertSame('5.0', $rules->summary([5 => 1000000])['average']);
        // Unknown star values never contribute.
        self::assertSame(['count' => 1, 'average' => '3.0', 'distribution' => [1 => 0, 2 => 0, 3 => 1, 4 => 0, 5 => 0]], $rules->summary([3 => 1, 0 => 4, 6 => 2, 2 => -1]));
    }

    public function test_text_rules_and_public_author_label(): void
    {
        $rules = new ReviewRules;
        self::assertSame("Line one\nLine two", $rules->normalize("  Line one\r\nLine two \n"));
        self::assertNull($rules->normalize(" \r\n\t "));
        self::assertTrue($rules->meaningful('Ναι'));
        self::assertTrue($rules->meaningful('5/5'));
        self::assertFalse($rules->meaningful('?!… ★★★'));
        self::assertSame('Maria E.', $rules->authorName(' Maria ', 'example'));
        self::assertSame('Νίκος Κ.', $rules->authorName('Νίκος', 'κωνσταντίνου'));
        self::assertSame('Cher', $rules->authorName('Cher', ' '));
    }
}
