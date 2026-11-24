<?php

namespace App\Customer\Infrastructure;

/**
 * One definition of "the same email or phone" for the blacklist. Emails are trimmed and lower case. Phones are
 * reduced to digits with an international "00" prefix removed, so "+30 210 123 4567" and "0030 2101234567" match.
 * No country is inferred: a national number only matches the same national form.
 */
final class ContactNormalizer
{
    public static function email(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    public static function phone(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        $digits = preg_replace('/^00/', '', $digits) ?? '';

        return $digits === '' ? null : $digits;
    }

    /** The same normalization as phone(), for a SQL column reference. */
    public static function phoneSql(string $column): string
    {
        return "NULLIF(regexp_replace(regexp_replace(COALESCE($column, ''), '[^0-9]', '', 'g'), '^00', ''), '')";
    }

    /** SQL predicate: the customers row `$customers` has an active fully blacklisted email or phone (profile or address). */
    public static function blacklistedSql(string $customers = 'customers'): string
    {
        return "EXISTS (SELECT 1 FROM customer_blacklist_entries b WHERE b.released_at IS NULL AND b.scope = 'full' AND ("
            ."(b.kind = 'email' AND b.value = $customers.email)"
            ." OR (b.kind = 'phone' AND (b.value = ".self::phoneSql($customers.'.phone')
            .' OR EXISTS (SELECT 1 FROM customer_addresses ba WHERE ba.customer_id = '.$customers.'.id AND '.self::phoneSql('ba.phone').' = b.value)))))';
    }
}
