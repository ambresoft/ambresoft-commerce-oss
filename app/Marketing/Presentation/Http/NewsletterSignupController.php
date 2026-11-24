<?php

namespace App\Marketing\Presentation\Http;

use App\Marketing\Infrastructure\NewsletterSubscribers;
use App\Storefront\Infrastructure\StorefrontAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public sign-up from a storefront newsletter block. The block (not the visitor) decides which list is joined, the
 * subscription still needs the e-mail confirmation, and the answer never reveals whether an address is already known.
 */
final class NewsletterSignupController
{
    public function __invoke(Request $request, NewsletterSubscribers $subscribers): JsonResponse
    {
        abort_unless(app(StorefrontAccess::class)->allowed(request()), 404);
        $data = $request->validate(['email' => ['required', 'string', 'max:254'], 'form' => ['required', 'uuid'], 'website' => ['nullable', 'string']]);
        // The honeypot field is hidden for people; a filled one is a bot, which gets the same friendly answer.
        if (($data['website'] ?? '') !== '') {
            return response()->json(['status' => 'ok', 'confirm' => false]);
        }
        $list = DB::table('storefront_blocks as b')->join('storefront_block_selections as s', fn ($j) => $j->on('s.block_id', 'b.id')->where('s.field', 'list'))
            ->join('newsletter_lists as l', 'l.id', '=', 's.newsletter_list_id')->where('b.id', $data['form'])->where('b.type', 'newsletter')->where('l.status', 'active')->first(['l.id', 'l.confirmation_id']);
        abort_if($list === null, 404);
        $customer = $request->user() === null ? null : DB::table('customers')->where('user_id', $request->user()->getAuthIdentifier())->value('id');
        try {
            $subscribers->subscribe($data['email'], [(string) $list->id], 'storefront', false, app()->getLocale(), $customer === null ? null : (string) $customer);
        } catch (ValidationException) {
            return response()->json(['message' => __('storefront.newsletter_invalid')], 422);
        }
        $confirm = $list->confirmation_id !== null && DB::table('newsletters')->where('id', $list->confirmation_id)->where('is_active', true)->exists();

        return response()->json(['status' => 'ok', 'confirm' => $confirm]);
    }
}
