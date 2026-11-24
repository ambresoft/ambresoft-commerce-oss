<?php

namespace App\Admin\Controllers;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LocaleController
{
    public function __invoke(Request $request, LocaleCatalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in($catalog->active()->pluck('code')->all())],
            // A local admin URL only, never an external/intended redirect.
            'return_to' => ['required', 'string', 'max:2000', 'regex:~^/admin(?:[/?][^\r\n\\\\]*)?$~D'],
        ]);
        /** @var UserRecord $user */
        $user = $request->user();
        $user->preferred_locale_id = $catalog->active()->firstWhere('code', $data['locale'])->id;
        $user->save();
        $request->session()->regenerate();

        return redirect($data['return_to']);
    }
}
