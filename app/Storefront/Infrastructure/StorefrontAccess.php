<?php

namespace App\Storefront\Infrastructure;

use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Store closure keeps visitors out; verified staff can preview the existing public storefront. */
final class StorefrontAccess
{
    public function allowed(Request $request): bool
    {
        if (app(GeneralSettings::class)->all()['storefront_open']) {
            return true;
        }
        $user = $request->user();

        return $user !== null && $user->hasVerifiedEmail() && Gate::forUser($user)->allows('access-admin');
    }
}
