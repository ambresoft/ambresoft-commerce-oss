<?php

namespace Tests\Unit;

use App\Authorization\Domain\Exception\UnsafeAccessChange;
use App\Authorization\Domain\Service\AccessSafety;
use App\Localization\Domain\Service\TranslationResolver;
use App\Shared\Domain\Contract\Translatable;
use App\Shared\Domain\Model\LocalizedContent;
use PHPUnit\Framework\TestCase;

final class LocalizationAndAccessTest extends TestCase
{
    public function test_translation_contract_preserves_empty_values_and_reports_fallback_by_resolved_locale(): void
    {
        $entity = new class implements Translatable
        {
            public function translation(string $locale): ?LocalizedContent
            {
                return $locale === 'en' ? new LocalizedContent('en', ['name' => 'Example', 'description' => '']) : null;
            }
        };
        $result = (new TranslationResolver)->resolveTranslation($entity, 'el');
        self::assertSame('en', $result->locale);
        self::assertSame('', $result->fields['description']);
        self::assertNull($entity->translation('el'));
    }

    public function test_translation_resolver_returns_null_only_when_all_candidates_are_missing(): void
    {
        $entity = new class implements Translatable
        {
            public function translation(string $locale): ?LocalizedContent
            {
                return null;
            }
        };
        self::assertNull((new TranslationResolver)->resolveTranslation($entity, 'el'));
    }

    public function test_access_invariant_rejects_last_super_demotion_independent_of_http(): void
    {
        $this->expectException(UnsafeAccessChange::class);
        AccessSafety::assertUserChange('2', '1', true, true, false, 1);
    }

    public function test_another_super_allows_demotion_but_never_self_deactivation(): void
    {
        AccessSafety::assertUserChange('2', '1', true, true, false, 2);
        $this->expectException(UnsafeAccessChange::class);
        AccessSafety::assertUserChange('1', '1', false, true, true, 2);
    }
}
