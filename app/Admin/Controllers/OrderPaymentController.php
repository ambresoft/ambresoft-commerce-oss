<?php

namespace App\Admin\Controllers;

use App\Marketing\Infrastructure\GiftCardRedemptions;
use App\Payment\Infrastructure\OrderPayments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Payments of one order: staff record collected money and re-check online attempts with the provider. */
final class OrderPaymentController
{
    public function store(Request $request, OrderPayments $payments, string $order): RedirectResponse
    {
        abort_unless(DB::table('orders')->where('id', $order)->exists(), 404);
        $data = $request->validate(['amount' => ['required', 'string', 'max:30'], 'note' => ['nullable', 'string', 'max:1000']]);
        $payments->record((string) $request->user()->id, $order, trim($data['amount']), $data['note'] ?? null);

        return redirect('/admin/orders/'.$order)->with('status', __('payments.recorded'));
    }

    /** Pays part or all of what is due with a gift card; the amount is decided on the server from the balance. */
    public function giftCard(Request $request, GiftCardRedemptions $redemptions, string $order): RedirectResponse
    {
        abort_unless(DB::table('orders')->where('id', $order)->exists(), 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $amount = $redemptions->redeem((string) $request->user()->id, $order, $data['code']);

        return redirect('/admin/orders/'.$order)->with('status', __('giftcards.redeemed', ['amount' => $amount]));
    }

    public function refresh(Request $request, OrderPayments $payments, string $order, string $payment): RedirectResponse
    {
        $state = $payments->refresh((string) $request->user()->id, $order, $payment);

        return redirect('/admin/orders/'.$order)->with('status', match ($state) {
            'captured' => __('payments.refresh_captured'), 'pending', 'processing' => __('payments.refresh_pending'), 'created' => __('payments.refresh_open'),
            'failed' => __('payments.refresh_failed'), 'cancelled' => __('payments.refresh_cancelled'), default => __('payments.refresh_unavailable'),
        });
    }
}
