<?php

namespace App\Storefront\Presentation\Http;

use App\Customer\Presentation\Http\Controllers\AccountProfile;
use App\Marketing\Infrastructure\NewsletterSubscribers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Account → Newsletters: the customer leaves one of the lists they are on. */
final class AccountNewsletterController
{
    public function destroy(Request $request, NewsletterSubscribers $subscribers, string $list): RedirectResponse
    {
        $customer = AccountProfile::of($request);
        // Only the customer's own subscriptions: by their account or their account's e-mail.
        $ids = DB::table('newsletter_list_subscribers as m')->join('newsletter_subscribers as s', 's.id', '=', 'm.subscriber_id')->where('m.list_id', $list)
            ->where(fn ($q) => $q->where('s.customer_id', $customer->id)->orWhereRaw('lower(s.email) = ?', [mb_strtolower((string) $customer->email)]))->pluck('s.id');
        abort_if($ids->isEmpty(), 404);
        foreach ($ids as $id) {
            $subscribers->unsubscribe((string) $id, $list);
        }

        return to_route('account.newsletters')->with('status', __('account.newsletter_left'));
    }
}
