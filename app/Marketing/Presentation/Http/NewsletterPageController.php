<?php

namespace App\Marketing\Presentation\Http;

use App\Marketing\Infrastructure\NewsletterSubscribers;
use App\Settings\Infrastructure\CompanySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public pages behind signed links in newsletter e-mails: unsubscribe and confirm a subscription. Both only change
 * data on POST, so link scanners that open URLs cannot unsubscribe or confirm anyone.
 */
final class NewsletterPageController
{
    public function unsubscribe(Request $request, NewsletterSubscribers $subscribers, string $subscriber): View
    {
        $list = $request->query('list');
        $list = is_string($list) && ctype_digit($list) ? $list : null;
        $exists = DB::table('newsletter_subscribers')->where('id', $subscriber)->exists();
        if ($request->isMethod('post') && $exists) {
            $subscribers->unsubscribe($subscriber, $list);

            return $this->page('unsubscribed', null);
        }

        return $this->page($exists ? 'unsubscribe' : 'gone', $exists ? $request->fullUrl() : null);
    }

    public function confirm(Request $request, NewsletterSubscribers $subscribers, string $subscriber, string $list): View
    {
        $exists = DB::table('newsletter_list_subscribers')->where('subscriber_id', $subscriber)->where('list_id', $list)->exists();
        if ($request->isMethod('post') && $exists) {
            $subscribers->confirm($subscriber, $list);

            return $this->page('confirmed', null);
        }

        return $this->page($exists ? 'confirm' : 'gone', $exists ? $request->fullUrl() : null);
    }

    private function page(string $state, ?string $action): View
    {
        $company = app(CompanySettings::class)->all();

        return view('newsletter.page', ['state' => $state, 'action' => $action, 'store' => (string) ($company['name'] ?: config('app.name')), 'locale' => app()->getLocale()]);
    }
}
