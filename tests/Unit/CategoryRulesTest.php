<?php

namespace Tests\Unit;

use App\Catalog\Domain\Exception\InvalidCategoryChange;
use App\Catalog\Domain\Model\Category;
use App\Catalog\Domain\Service\CategoryAgeRestrictions;
use App\Catalog\Domain\Service\CategoryRules;
use App\Shared\Domain\Model\LocalizedContent;
use PHPUnit\Framework\TestCase;

final class CategoryRulesTest extends TestCase
{
    public function test_exact_translation_lookup_preserves_intentionally_empty_text(): void
    {
        $category = new Category('9', null, 1, 'active', null, 1, ['en' => new LocalizedContent('en', ['name' => 'Category', 'description' => ''])]);
        self::assertSame('', $category->translation('en')->fields['description']);
        self::assertNull($category->translation('el'));
    }

    public function test_cycle_rule_is_framework_free(): void
    {
        $this->expectException(InvalidCategoryChange::class);
        (new CategoryRules)->assertParent('1', ['1', '2', '3'], false);
    }

    public function test_system_category_cannot_be_edited_even_by_a_privileged_caller(): void
    {
        $this->expectException(InvalidCategoryChange::class);
        (new CategoryRules)->assertEditable(new Category('1', null, 0, 'disabled', 'uncategorized', 1));
    }

    public function test_age_policy_uses_strictest_enabled_ancestor_and_closest_source_on_ties(): void
    {
        $policy = new CategoryAgeRestrictions;
        $root = new Category('1', null, 1, 'active', null, 1, ageVerification: true, ageLimit: 21);
        $child = new Category('2', '1', 1, 'active', null, 1, ageVerification: false, ageLimit: 99);
        $leaf = new Category('3', '2', 1, 'active', null, 1, ageVerification: true, ageLimit: 18);
        self::assertSame(['verification' => true, 'ageLimit' => 21, 'sourceCategoryId' => '1'], $policy->forPath([$root, $child, $leaf]));
        $tie = new Category('4', '3', 1, 'active', null, 1, ageVerification: true, ageLimit: 21);
        self::assertSame('4', $policy->forPath([$root, $child, $leaf, $tie])['sourceCategoryId']);
        self::assertSame(['verification' => false, 'ageLimit' => null, 'sourceCategoryId' => null], $policy->forPath([]));
    }
}
