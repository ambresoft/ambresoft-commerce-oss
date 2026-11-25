<?php

namespace App\Website\Domain\Service;

/** Framework-free page value rules. */
final class PageRules
{
    public const STATUSES = ['active', 'hidden', 'disabled'];

    /** Open Graph object types offered for social sharing, grouped like the merchant sees them. */
    public const FACEBOOK_TYPES = [
        'activities' => ['activity', 'sport'],
        'businesses' => ['bar', 'company', 'cafe', 'hotel', 'restaurant'],
        'groups' => ['cause', 'sports_league', 'sports_team'],
        'organizations' => ['band', 'government', 'non_profit', 'school', 'university'],
        'people' => ['actor', 'athlete', 'author', 'director', 'musician', 'politician', 'public_figure'],
        'places' => ['city', 'country', 'landmark', 'state_province'],
        'products' => ['album', 'book', 'drink', 'food', 'game', 'movie', 'product', 'song', 'tv_show'],
        'websites' => ['website', 'article', 'blog'],
    ];

    /** Form builder field types. Decorative types collect no value; choice types need variants. */
    public const FIELD_TYPES = ['input', 'textarea', 'select', 'radio', 'checkboxes', 'checkbox', 'date', 'email', 'number', 'phone', 'country', 'region', 'file', 'header', 'separator'];

    public const DECORATIVE_TYPES = ['header', 'separator'];

    public const CHOICE_TYPES = ['select', 'radio', 'checkboxes'];

    /** Fields whose single value can become the e-mail subject. */
    public const SUBJECT_TYPES = ['input', 'select', 'radio', 'email'];

    public const FORM_MARKER = '[form]';

    public static function facebookType(string $value): bool
    {
        foreach (self::FACEBOOK_TYPES as $types) {
            if (in_array($value, $types, true)) {
                return true;
            }
        }

        return false;
    }

    public static function slug(string $value): bool
    {
        return strlen($value) <= 200 && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $value) === 1;
    }
}
