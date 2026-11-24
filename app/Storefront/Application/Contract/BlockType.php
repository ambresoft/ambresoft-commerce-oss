<?php

namespace App\Storefront\Application\Contract;

/** Trusted code supplies definitions and public payloads; database values never select executable paths. */
interface BlockType
{
    public function id(): string;

    /** UI label, fields, templates, defaults, allowed pages and protected-action policy.
     * @return array<string, mixed> */
    public function definition(): array;

    /** Validate and normalize content settings. Throw on invalid input.
     * @param array<string, mixed> $settings
     * @return array<string, mixed> */
    public function validate(array $settings): array;

    /** Resolve all instances together. Empty payloads omit a block. Never return internal/admin data.
     * @param array<string, array<string, mixed>> $blocks
     * @param array{locale:string, customer:?string} $context
     * @return array<string, array<string, mixed>> */
    public function resolveMany(array $blocks, array $context): array;
}
