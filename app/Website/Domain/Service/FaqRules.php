<?php

namespace App\Website\Domain\Service;

final class FaqRules
{
    public const TYPES = ['general', 'category', 'product'];

    public const STATUSES = ['active', 'disabled'];

    public const QUESTION_MAX = 500;

    public const ANSWER_MAX = 20000;

    public static function supports(string $type, string $owner): bool
    {
        return in_array($owner, ['category', 'product', 'page'], true)
            && ($type === 'general' || $type === $owner);
    }
}
