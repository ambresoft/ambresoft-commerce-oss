<?php

namespace Ambresoft\CartReminders\Infrastructure;

use Ambresoft\CartReminders\Domain\ReminderSchedule;
use App\Cart\Application\Command\AbandonedCart;
use App\Cart\Application\Contract\AbandonedCarts;
use App\Shared\Application\Contract\AddonSettingValues;
use App\Shared\Application\Contract\StoreMailer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Sends the reminders that are due; run by the scheduler every 15 minutes. */
final class CartReminders
{
    public const ADDON = 'ambresoft/cart-reminders';

    private const PAGE = 200;

    public function __construct(private AbandonedCarts $carts, private StoreMailer $mailer, private AddonSettingValues $settings) {}

    /** @return int Reminders sent */
    public function sendDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $values = $this->settings->values(self::ADDON);
        $schedule = new ReminderSchedule((int) ($values['delay_hours'] ?? 3), (int) ($values['max_reminders'] ?? 1), (int) ($values['repeat_hours'] ?? 24));
        $sent = 0;
        $after = 0;
        do {
            $page = $this->carts->idleSince($now->subHours($schedule->delayHours), $after, self::PAGE);
            $state = DB::table('ambresoft_cart_reminders')->whereIn('cart_id', array_map(fn (AbandonedCart $c): string => $c->cartId, $page))->get()->keyBy('cart_id');
            foreach ($page as $cart) {
                $after = (int) $cart->cartId;
                $row = $state[$cart->cartId] ?? null;
                // A cart changed after its last reminder is a new abandonment.
                $count = $row !== null && CarbonImmutable::parse($row->cart_updated_at)->equalTo($cart->updatedAt) ? (int) $row->sent : 0;
                $last = $count > 0 ? CarbonImmutable::parse($row->last_sent_at) : null;
                if (! $schedule->due($cart->updatedAt, $count, $last, $now) || ! $this->send($cart)) {
                    continue;
                }
                DB::table('ambresoft_cart_reminders')->upsert([['cart_id' => $cart->cartId, 'sent' => $count + 1, 'last_sent_at' => $now, 'cart_updated_at' => $cart->updatedAt]], ['cart_id'], ['sent', 'last_sent_at', 'cart_updated_at']);
                $sent++;
            }
        } while (count($page) === self::PAGE);

        return $sent;
    }

    private function send(AbandonedCart $cart): bool
    {
        $t = fn (string $key, array $replace = []): string => __('cart-reminders::reminders.'.$key, $replace, $cart->locale);
        $e = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $items = implode('', array_map(fn (array $l): string => '<li style="margin:0 0 6px">'.$e($l['name']).' × '.$l['quantity'].'</li>', $cart->lines));
        $html = '<h1 style="font-size:22px; margin:0 0 20px">'.$e($t('mail_greeting', ['name' => $cart->firstName])).'</h1>'
            .'<p style="margin:0 0 16px">'.$e($t('mail_intro')).'</p><ul style="margin:0 0 24px; padding-left:20px">'.$items.'</ul>'
            .'<p style="margin:0 0 24px; text-align:center"><a href="'.$e($cart->cartUrl).'" style="display:inline-block; padding:14px 24px; background:#111827; color:#ffffff; border-radius:6px; font-weight:bold; text-decoration:none">'.$e($t('mail_button')).'</a></p>'
            .'<p style="margin:0; color:#6b7280; font-size:13px">'.$e($t('mail_outro')).'</p>';
        $text = $t('mail_greeting', ['name' => $cart->firstName])."\n\n".$t('mail_intro')."\n".implode("\n", array_map(fn (array $l): string => '- '.$l['name'].' × '.$l['quantity'], $cart->lines))
            ."\n\n".$t('mail_button').': '.$cart->cartUrl."\n\n".$t('mail_outro');
        $error = $this->mailer->send($cart->email, $t('mail_subject'), $html, $text, $cart->locale);
        if ($error !== null) {
            Log::warning('cart_reminder_failed', ['cart_id' => $cart->cartId, 'reason' => $error]);
        }

        return $error === null;
    }
}
