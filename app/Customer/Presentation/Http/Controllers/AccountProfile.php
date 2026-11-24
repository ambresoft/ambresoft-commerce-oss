<?php

namespace App\Customer\Presentation\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The profile linked to the signed-in identity (EnsureCustomerAccount guarantees it exists and is active). */
final class AccountProfile
{
    public static function of(Request $request): object
    {
        return DB::table('customers')->where('user_id', $request->user()->getKey())->firstOrFail();
    }
}
