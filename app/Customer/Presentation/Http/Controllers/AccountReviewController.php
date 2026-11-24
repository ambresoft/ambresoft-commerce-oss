<?php

namespace App\Customer\Presentation\Http\Controllers;

use App\Catalog\Infrastructure\ProductReviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Review submission from the account page and the product page: `ProductReviews::submit` with the customer taken from the session. */
final class AccountReviewController
{
    public function store(Request $request, ProductReviews $reviews, string $product): RedirectResponse
    {
        $reviews->submit((string) AccountProfile::of($request)->id, $product, $request->only(['rating', 'title', 'body']));
        // The product page names its own address: with no referrer sent, "back" would be whatever URL the session saw
        // last (a picture, a 404). Only a path on this site is accepted, never another host.
        $return = $request->input('return_to');
        if (is_string($return) && preg_match('#^/(?![/\\\\])[^\s\\\\]{0,2000}$#D', $return) === 1) {
            return redirect($return)->with('status', __('account.review_submitted'));
        }

        return back()->with('status', __('account.review_submitted'));
    }
}
