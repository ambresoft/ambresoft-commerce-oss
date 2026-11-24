<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use Illuminate\Http\Request;

/** Layout props of the Marketing → Newsletters screens. */
trait NewsletterShell
{
    /** @return array<string, mixed> */
    private function shell(Request $request, string $sectionKey, string $sectionHref, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('newsletters.'.$sectionKey), 'href' => $current === null ? null : $sectionHref]];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
