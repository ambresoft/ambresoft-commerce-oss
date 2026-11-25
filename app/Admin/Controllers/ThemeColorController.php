<?php

namespace App\Admin\Controllers;

use App\Storefront\Infrastructure\ThemeColors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ThemeColorController
{
    /** The editor lives in the general settings page (tab "Storefront colors"). */
    public function update(Request $request, ThemeColors $colors): RedirectResponse
    {
        $colors->save((string) $request->user()->getAuthIdentifier(), $request->only(['version', 'auth_header', ...array_keys(ThemeColors::DEFAULTS)]));

        return back()->with('status', __('settings.saved'));
    }
}
