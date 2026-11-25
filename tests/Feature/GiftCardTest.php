<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Marketing\Infrastructure\GiftCardMail;
use App\Marketing\Infrastructure\GiftCardRedemptions;
use App\Marketing\Infrastructure\GiftCards;
use App\Order\Application\Contract\OrderMailTransport;
use App\Payment\Domain\Service\PaymentRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\Concerns\StaffIdentities;
use Tests\Fakes\RecordingMailTransport;
use Tests\TestCase;

final class GiftCardTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->fixtureStore();
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => 'shop@store.example', 'order_email_from_name' => 'The Shop']);
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Switches the signed-in staff member; the session is cleared so the previous one cannot linger. */
    private function as(UserRecord $user): static
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function actor(): string
    {
        return (string) $this->actor->id;
    }

    private function mail(): RecordingMailTransport
    {
        $transport = app(OrderMailTransport::class);
        self::assertInstanceOf(RecordingMailTransport::class, $transport);

        return $transport;
    }

    /** @return array<string, mixed> */
    private function definition(array $changes = []): array
    {
        return array_replace(['recipient_name' => 'Nikos', 'sender_name' => 'Test User', 'recipient_email' => 'nikos@example.test', 'message' => 'Enjoy!', 'amount' => '50.00', 'locale' => 'en', 'status' => 'active', 'notify' => false], $changes);
    }

    private function card(array $changes = []): string
    {
        return app(GiftCards::class)->save($this->actor(), null, $this->definition($changes));
    }

    private function row(string $id): object
    {
        return DB::table('gift_cards')->where('id', $id)->sole();
    }

    /** An unpaid order of the given total (one line), in the fixture store. */
    private function order(string $total = '100', array $options = []): int
    {
        return $this->sale('2026-10-10 10:00', [['A', 'Phone', 1, $total]], [], array_replace(['payment' => 'cod'], $options));
    }

    /** The SQLSTATE the database answers with, or "ok". */
    private function sqlState(callable $write): string
    {
        try {
            DB::transaction($write);
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        return 'ok';
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            self::fail('Expected a validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));
        }
    }

    public function test_cards_are_issued_listed_edited_and_versioned(): void
    {
        $this->get('/admin/gift-cards')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/GiftCards/Index')->where('filters.sort', 'created_at')->where('giftCards.total', 0));
        $this->get('/admin/gift-cards/create')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/GiftCards/Form')->where('giftCard', null)->where('currency.code', 'EUR'));
        $this->post('/admin/gift-cards', $this->definition())->assertSessionHasNoErrors();
        $card = DB::table('gift_cards')->sole();
        self::assertMatchesRegularExpression('/^GC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $card->number);
        self::assertSame(['EUR', '50.000000', '50.000000', 'active', 1], [$card->currency_code, $card->amount, $card->balance, $card->status, (int) $card->lock_version]);
        $issued = DB::table('gift_card_transactions')->sole();
        self::assertSame(['issued', '50.000000', '50.000000'], [$issued->type, $issued->amount, $issued->balance_after]);

        $this->get('/admin/gift-cards/'.$card->id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/GiftCards/Form')
            ->where('giftCard.code', $card->number)->where('giftCard.amount', '50.00')->where('giftCard.balance', '€50.00')->where('giftCard.amount_locked', false)->where('history.total', 1));
        self::assertFalse((bool) $card->notify, 'The choice made in the form is what is saved');
        $this->get('/admin/gift-cards/'.$card->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('giftCard.notify', false));
        $this->get('/admin/gift-cards?search=nikos')->assertInertia(fn (Assert $p) => $p->where('giftCards.total', 1)->where('giftCards.data.0.balance', '€50.00')->where('giftCards.data.0.partly_used', false)->where('giftCards.data.0.notify', false));
        $this->get('/admin/gift-cards?search=nobody')->assertInertia(fn (Assert $p) => $p->where('giftCards.total', 0));
        $this->get('/admin/gift-cards?status=pending')->assertInertia(fn (Assert $p) => $p->where('giftCards.total', 0));
        $this->get('/admin/gift-cards?sort=amount')->assertSessionHasErrors('sort');

        // Until some of it is used, the amount is still editable and the issue record follows it.
        $this->put('/admin/gift-cards/'.$card->id, $this->definition(['version' => 1, 'amount' => '75', 'recipient_name' => 'Nikolaos']))->assertSessionHasNoErrors();
        $after = $this->row((string) $card->id);
        self::assertSame(['Nikolaos', '75.000000', '75.000000', 2], [$after->recipient_name, $after->amount, $after->balance, (int) $after->lock_version]);
        self::assertSame('75.000000', DB::table('gift_card_transactions')->sole()->balance_after);
        $this->put('/admin/gift-cards/'.$card->id, $this->definition(['version' => 1]))->assertSessionHasErrors('version');
        $this->get('/admin/gift-cards/999999/edit')->assertNotFound();
    }

    public function test_validation_protects_names_email_amount_language_and_status(): void
    {
        $cards = app(GiftCards::class);
        foreach ([['recipient_name', ''], ['sender_name', '  '], ['recipient_email', 'not-an-email'], ['amount', '0'], ['amount', '-5'], ['amount', '10.123'], ['amount', 'ten'], ['amount', '1e3'],
            ['locale', 'xx'], ['status', 'used'], ['status', 'bogus'], ['message', str_repeat('x', 2001)]] as [$field, $value]) {
            $this->invalid(fn () => $cards->save($this->actor(), null, $this->definition([$field => $value])), $field);
        }
        self::assertSame(0, DB::table('gift_cards')->count());
        // Boundaries that are fine.
        $this->card(['amount' => '0.01', 'message' => str_repeat('x', 2000), 'recipient_email' => 'a@b.test']);
        $this->card(['message' => '']);
        self::assertNull(DB::table('gift_cards')->orderByDesc('id')->value('message'));
        self::assertSame(2, DB::table('gift_cards')->count());
        // Every card has its own code.
        self::assertCount(2, DB::table('gift_cards')->pluck('number')->unique());
    }

    public function test_every_operation_checks_its_own_capability(): void
    {
        $id = $this->card();
        $viewer = $this->staff(['admin.access', 'giftcards.view']);
        $nobody = $this->staff(['admin.access']);
        $this->as($viewer);
        $this->get('/admin/gift-cards')->assertOk();
        $this->get('/admin/gift-cards/'.$id.'/edit')->assertOk();
        $this->get('/admin/gift-cards/create')->assertForbidden();
        $this->post('/admin/gift-cards', $this->definition())->assertForbidden();
        $this->put('/admin/gift-cards/'.$id, $this->definition(['version' => 1]))->assertForbidden();
        $this->patch('/admin/gift-cards/'.$id.'/status', ['version' => 1, 'status' => 'cancelled', 'notify' => false])->assertForbidden();
        $this->post('/admin/gift-cards/'.$id.'/send')->assertForbidden();
        $this->delete('/admin/gift-cards/'.$id, ['version' => 1])->assertForbidden();
        $this->delete('/admin/gift-cards/bulk', ['rows' => [['id' => $id, 'version' => 1]]])->assertForbidden();
        $this->as($nobody);
        $this->get('/admin/gift-cards')->assertForbidden();
        $this->get('/admin/gift-cards/'.$id.'/edit')->assertForbidden();
        // Direct calls are refused too, not only the HTTP layer.
        foreach ([fn () => app(GiftCards::class)->save((string) $viewer->id, null, $this->definition()), fn () => app(GiftCards::class)->delete((string) $viewer->id, [['id' => $id, 'version' => 1]]),
            fn () => app(GiftCardMail::class)->resend((string) $viewer->id, $id), fn () => app(GiftCards::class)->setStatus((string) $viewer->id, $id, 1, 'cancelled', false)] as $operation) {
            try {
                $operation();
                self::fail('Expected an authorization failure');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(['active', 1], [$this->row($id)->status, (int) $this->row($id)->lock_version]);
        $navigation = collect($this->as($viewer)->get('/admin')->viewData('page')['props']['navigation'])->pluck('id')->all();
        self::assertContains('core.gift-cards', $navigation);
        $this->as($nobody);
        self::assertNotContains('core.gift-cards', collect($this->get('/admin')->viewData('page')['props']['navigation'])->pluck('id')->all());
    }

    public function test_status_changes_follow_the_lifecycle_and_used_is_never_chosen(): void
    {
        $id = $this->card(['status' => 'pending']);
        $cards = app(GiftCards::class);
        $this->patch('/admin/gift-cards/'.$id.'/status', ['version' => 1, 'status' => 'active', 'notify' => false])->assertSessionHasNoErrors();
        self::assertSame(['active', 2], [$this->row($id)->status, (int) $this->row($id)->lock_version]);
        // Ticking the box in the list saves it without changing the status, and the editor then shows it ticked.
        $this->patch('/admin/gift-cards/'.$id.'/status', ['version' => 2, 'status' => 'active', 'notify' => true])->assertSessionHasNoErrors();
        self::assertSame([true, 'active', 3], [(bool) $this->row($id)->notify, $this->row($id)->status, (int) $this->row($id)->lock_version]);
        $this->get('/admin/gift-cards/'.$id.'/edit')->assertInertia(fn (Assert $p) => $p->where('giftCard.notify', true));
        $this->patch('/admin/gift-cards/'.$id.'/status', ['version' => 3, 'status' => 'active', 'notify' => false])->assertSessionHasNoErrors();
        $this->patch('/admin/gift-cards/'.$id.'/status', ['version' => 4, 'status' => 'used', 'notify' => false])->assertSessionHasErrors('status');
        $this->patch('/admin/gift-cards/'.$id.'/status', ['version' => 1, 'status' => 'cancelled', 'notify' => false])->assertSessionHasErrors('version');
        $this->patch('/admin/gift-cards/'.$id.'/status', ['version' => 2, 'status' => 'cancelled'])->assertSessionHasErrors('notify');
        $cards->setStatus($this->actor(), $id, 4, 'cancelled', false);
        $cards->setStatus($this->actor(), $id, 5, 'active', false);
        self::assertSame('active', $this->row($id)->status);

        // A card with nothing left is used for good.
        DB::table('gift_cards')->where('id', $id)->update(['balance' => 0, 'status' => 'used']);
        $version = (int) $this->row($id)->lock_version;
        $this->invalid(fn () => $cards->setStatus($this->actor(), $id, $version, 'active', false), 'status');
        $this->invalid(fn () => $cards->save($this->actor(), $id, $this->definition(['version' => $version, 'status' => 'active'])), 'status');
        // Its details may still be corrected while it stays used.
        $cards->save($this->actor(), $id, $this->definition(['version' => $version, 'status' => 'used', 'amount' => '50', 'recipient_name' => 'Fixed name']));
        self::assertSame(['used', 'Fixed name'], [$this->row($id)->status, $this->row($id)->recipient_name]);
    }

    public function test_the_database_keeps_codes_and_balances_consistent(): void
    {
        $id = (int) $this->card();
        $attempt = fn (array $changes): string => $this->sqlState(fn () => DB::table('gift_cards')->where('id', $changes['id'])->update($changes['set']));
        self::assertSame('23514', $attempt(['id' => $id, 'set' => ['balance' => '50.01']]), 'Balance above the face value');
        self::assertSame('23514', $attempt(['id' => $id, 'set' => ['balance' => '-1']]), 'Negative balance');
        self::assertSame('23514', $attempt(['id' => $id, 'set' => ['balance' => '0']]), 'Empty but not "used"');
        self::assertSame('23514', $attempt(['id' => $id, 'set' => ['status' => 'used']]), '"used" with money left');
        self::assertSame('23514', $attempt(['id' => $id, 'set' => ['number' => 'gc-lower-case-code']]), 'Code shape');
        self::assertSame('23514', $attempt(['id' => $id, 'set' => ['amount' => '0']]));
        self::assertSame('ok', $attempt(['id' => $id, 'set' => ['balance' => '0', 'status' => 'used']]));
        $other = $this->card();
        self::assertSame('23505', $attempt(['id' => $other, 'set' => ['number' => $this->row((string) $id)->number]]), 'Codes are unique');
        // A gift card payment must name its card, and only gift card payments may.
        $order = $this->order();
        $insert = fn (array $row): string => $this->sqlState(fn () => DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $order, 'state' => 'captured', 'amount' => 10, 'currency_code' => 'EUR', 'created_at' => now(), 'updated_at' => now(), ...$row]));
        self::assertSame('23514', $insert(['kind' => 'gift_card']));
        self::assertSame('23514', $insert(['kind' => 'manual', 'gift_card_id' => $other]));
        self::assertSame('23514', $insert(['kind' => 'gift_card', 'gift_card_id' => $other, 'state' => 'created']));
        self::assertSame('ok', $insert(['kind' => 'gift_card', 'gift_card_id' => $other]));
    }

    public function test_the_card_is_emailed_with_its_code_only_when_asked_and_only_when_active(): void
    {
        $this->card(['notify' => false]);
        $this->card(['status' => 'pending', 'notify' => true]);
        self::assertCount(0, $this->mail()->sent);
        self::assertSame(0, DB::table('gift_card_emails')->count());

        $id = $this->card(['notify' => true, 'message' => "Happy birthday!\n{{store_name}} <b>bold</b>", 'sender_name' => 'Ann {{x}}']);
        $card = $this->row($id);
        self::assertCount(1, $this->mail()->sent);
        $mail = $this->mail()->sent[0];
        self::assertSame(['shop@store.example', 'The Shop', 'nikos@example.test'], [$mail->fromAddress, $mail->fromName, $mail->to]);
        self::assertStringContainsString($card->number, $mail->html);
        self::assertStringContainsString($card->number, $mail->text);
        self::assertStringContainsString('€50.00', $mail->html);
        self::assertStringContainsString('Ann x sent you a gift card for ', $mail->subject, 'Braces cannot smuggle a placeholder into the subject');
        // The message is the sender's text: placeholders stay literal and markup is escaped.
        self::assertStringContainsString('{{store_name}}', html_entity_decode($mail->html));
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $mail->html);
        self::assertStringNotContainsString('<b>bold</b>', $mail->html);
        $record = DB::table('gift_card_emails')->where('gift_card_id', $id)->sole();
        self::assertSame(['delivery', 'nikos@example.test', 'sent', 1], [$record->kind, $record->recipient, $record->state, (int) $record->attempts]);
        self::assertNotNull($record->sent_at);

        // Becoming active sends it; changing a pending card without asking does not.
        $pending = $this->card(['status' => 'pending']);
        app(GiftCards::class)->setStatus($this->actor(), $pending, 1, 'active', false);
        self::assertCount(1, $this->mail()->sent);
        app(GiftCards::class)->setStatus($this->actor(), $pending, 2, 'pending', false);
        app(GiftCards::class)->setStatus($this->actor(), $pending, 3, 'active', true);
        self::assertCount(2, $this->mail()->sent);
        self::assertStringContainsString($this->row($pending)->number, $this->mail()->sent[1]->html);
    }

    public function test_cancellation_notice_never_repeats_the_code_and_resend_needs_an_active_card(): void
    {
        $id = $this->card(['notify' => true]);
        $code = $this->row($id)->number;
        app(GiftCards::class)->setStatus($this->actor(), $id, 1, 'cancelled', true);
        self::assertCount(2, $this->mail()->sent);
        $notice = $this->mail()->sent[1];
        self::assertStringNotContainsString($code, $notice->html);
        self::assertStringNotContainsString($code, $notice->text);
        self::assertStringContainsString(substr($code, -4), $notice->html);
        self::assertSame('cancelled', DB::table('gift_card_emails')->orderByDesc('id')->value('kind'));

        $this->invalid(fn () => app(GiftCardMail::class)->resend($this->actor(), $id), 'status');
        app(GiftCards::class)->setStatus($this->actor(), $id, 2, 'active', false);
        $this->post('/admin/gift-cards/'.$id.'/send')->assertSessionHasNoErrors();
        self::assertCount(3, $this->mail()->sent);
        self::assertStringContainsString($code, $this->mail()->sent[2]->html);
        $this->get('/admin/gift-cards/'.$id.'/edit')->assertInertia(fn (Assert $p) => $p->has('emails', 3)->where('emails.0.state', 'sent'));
    }

    public function test_a_mail_problem_never_undoes_the_card_and_is_visible(): void
    {
        $this->mail()->accept = false;
        $id = $this->card(['notify' => true]);
        self::assertSame('active', $this->row($id)->status);
        $record = DB::table('gift_card_emails')->sole();
        self::assertSame(['failed', 'mail_rejected'], [$record->state, $record->last_error]);
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => null]);
        $this->mail()->accept = true;
        app(GiftCardMail::class)->resend($this->actor(), $id);
        self::assertSame('sender_missing', DB::table('gift_card_emails')->orderByDesc('id')->value('last_error'));
        self::assertCount(1, $this->mail()->sent, 'Only the rejected attempt reached the transport');
        // Delivery is claimed once: running a delivered record again sends nothing.
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => 'shop@store.example']);
        $sent = app(GiftCardMail::class)->deliver((string) DB::table('gift_card_emails')->orderByDesc('id')->value('id'));
        self::assertTrue($sent);
        self::assertFalse(app(GiftCardMail::class)->deliver((string) DB::table('gift_card_emails')->orderByDesc('id')->value('id')));
    }

    public function test_the_email_is_written_in_the_chosen_language(): void
    {
        $id = $this->card(['notify' => true, 'locale' => 'el']);
        self::assertStringContainsString('δωροεπιταγή', mb_strtolower($this->mail()->sent[0]->subject));
        self::assertSame((int) DB::table('locales')->where('code', 'el')->value('id'), (int) $this->row($id)->locale_id);
    }

    public function test_a_card_pays_part_of_an_order_and_its_balance_goes_down_with_it(): void
    {
        $id = $this->card(['amount' => '30']);
        $code = $this->row($id)->number;
        $order = $this->order('100');
        $this->post('/admin/orders/'.$order.'/gift-card', ['code' => strtolower('  '.$code.' ')])->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $s): bool => str_contains($s, '€30.00'));

        $payment = DB::table('order_payments')->where('order_id', $order)->sole();
        self::assertSame(['gift_card', 'captured', '30.000000', (string) $id, 'EUR', null], [$payment->kind, $payment->state, $payment->amount, (string) $payment->gift_card_id, $payment->currency_code, $payment->payment_method_id]);
        self::assertSame($this->actor(), (string) $payment->recorded_by);
        $card = $this->row($id);
        self::assertSame(['used', '0.000000'], [$card->status, $card->balance]);
        $use = DB::table('gift_card_transactions')->where('type', 'redeemed')->sole();
        self::assertSame([(string) $order, '30.000000', '0.000000'], [(string) $use->order_id, $use->amount, $use->balance_after]);
        $due = DB::table('order_payments')->where('order_id', $order)->get(['state', 'amount'])->map(fn ($p) => ['state' => $p->state, 'amount' => rtrim(rtrim($p->amount, '0'), '.')])->all();
        self::assertSame('partially_paid', PaymentRules::orderStatus($due, '100.000000'));
        self::assertSame('70', PaymentRules::outstanding($due, '100'));

        // The order page shows the payment without exposing the code.
        $page = $this->get('/admin/orders/'.$order)->assertOk()->assertInertia(fn (Assert $p) => $p->where('payment.status', 'partially_paid')->where('payment.can_gift_card', true)
            ->where('payment.records.0.kind', 'gift_card')->where('payment.records.0.method', 'Gift card ending '.substr($code, -4)));
        self::assertStringNotContainsString($code, $page->getContent());
        // Used up, it cannot pay again.
        $this->post('/admin/orders/'.$order.'/gift-card', ['code' => $code])->assertSessionHasErrors('code');
        self::assertSame(1, DB::table('gift_card_transactions')->where('type', 'redeemed')->count());
    }

    public function test_a_larger_card_pays_only_what_is_due_and_keeps_the_rest(): void
    {
        $id = $this->card(['amount' => '150']);
        $code = $this->row($id)->number;
        $first = $this->order('100');
        $second = $this->order('80');
        $redemptions = app(GiftCardRedemptions::class);
        self::assertSame('€100.00', $redemptions->redeem($this->actor(), (string) $first, $code));
        self::assertSame(['active', '50.000000'], [$this->row($id)->status, $this->row($id)->balance]);
        self::assertSame('€50.00', $redemptions->redeem($this->actor(), (string) $second, $code));
        self::assertSame(['used', '0.000000'], [$this->row($id)->status, $this->row($id)->balance]);
        // Spent exactly once: the payments add up to the face value and no more.
        self::assertSame(0, bccomp((string) DB::table('order_payments')->where('gift_card_id', $id)->sum('amount'), '150', 6));
        self::assertSame(2, DB::table('gift_card_transactions')->where('type', 'redeemed')->count());
        $this->invalid(fn () => $redemptions->redeem($this->actor(), (string) $second, $code), 'code');
        // The order that was paid in full has nothing due.
        $this->invalid(fn () => $redemptions->redeem($this->actor(), (string) $first, $this->row($this->card())->number), 'code');
        self::assertSame(2, DB::table('order_payments')->where('kind', 'gift_card')->count());
    }

    public function test_only_usable_cards_in_the_orders_currency_pay_live_orders(): void
    {
        $redemptions = app(GiftCardRedemptions::class);
        $order = $this->order('100');
        $try = fn (string $code, ?int $on = null) => $this->invalid(fn () => $redemptions->redeem($this->actor(), (string) ($on ?? $order), $code), 'code');
        $try('GC-NOPE-NOPE-NOPE');
        $try('not a code');
        $try('');
        foreach (['pending', 'cancelled'] as $status) {
            $card = $this->card(['status' => $status]);
            $try($this->row($card)->number);
        }
        $deleted = $this->card();
        app(GiftCards::class)->delete($this->actor(), [['id' => $deleted, 'version' => 1]]);
        $try($this->row($deleted)->number);
        // A card never converts: a USD order cannot use a EUR card.
        $dollars = $this->order('100', ['currency' => 'USD']);
        $try($this->row($this->card())->number, $dollars);
        // A cancelled order cannot receive money.
        $void = $this->order('100', ['status' => 'cancelled']);
        $try($this->row($this->card())->number, $void);
        self::assertSame(0, DB::table('order_payments')->count());
        self::assertSame(0, DB::table('gift_card_transactions')->where('type', 'redeemed')->count());
    }

    public function test_applying_a_card_needs_both_order_payment_and_gift_card_access(): void
    {
        $id = $this->card();
        $code = $this->row($id)->number;
        $order = $this->order();
        foreach ([['admin.access', 'orders.view', 'orders.payments'], ['admin.access', 'orders.view', 'giftcards.view']] as $abilities) {
            $this->as($this->staff($abilities));
            $this->post('/admin/orders/'.$order.'/gift-card', ['code' => $code])->assertForbidden();
        }
        self::assertSame('50.000000', $this->row($id)->balance);
        $this->as($this->staff(['admin.access', 'orders.view', 'orders.payments']));
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $p) => $p->where('payment.can_gift_card', false));
        $this->as($this->staff(['admin.access', 'orders.view', 'orders.payments', 'giftcards.view']));
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $p) => $p->where('payment.can_gift_card', true));
        $this->post('/admin/orders/'.$order.'/gift-card', ['code' => $code])->assertSessionHasNoErrors();
        $this->post('/admin/orders/999999/gift-card', ['code' => $code])->assertNotFound();
        $this->post('/admin/orders/'.$order.'/gift-card', [])->assertSessionHasErrors('code');
    }

    public function test_a_card_that_covers_the_order_completes_its_payment_and_runs_the_status_after_payment(): void
    {
        $id = $this->card(['amount' => '120']);
        $order = $this->order('100');
        DB::table('payment_methods')->where('id', $this->ids['cod'])->update(['paid_status_id' => $this->ids['completed']]);
        app(GiftCardRedemptions::class)->redeem($this->actor(), (string) $order, $this->row($id)->number);
        self::assertSame($this->ids['completed'], (int) DB::table('orders')->where('id', $order)->value('status_id'));
        self::assertSame('20.000000', $this->row($id)->balance);
        // Paid by the card, the order counts as a sale on the day the card paid it.
        self::assertSame(1, DB::table('order_payments')->where('order_id', $order)->where('kind', 'gift_card')->whereNotNull('completed_at')->count());
    }

    public function test_deleting_archives_the_card_and_keeps_its_history_and_code(): void
    {
        $id = $this->card(['amount' => '40']);
        $code = $this->row($id)->number;
        $order = $this->order('100');
        app(GiftCardRedemptions::class)->redeem($this->actor(), (string) $order, $code);
        $other = $this->card();
        $this->delete('/admin/gift-cards/'.$id, ['version' => 1])->assertSessionHasErrors('version');
        $this->delete('/admin/gift-cards/'.$id, ['version' => (int) $this->row($id)->lock_version])->assertSessionHasNoErrors();
        self::assertNotNull($this->row($id)->deleted_at);
        $this->get('/admin/gift-cards')->assertInertia(fn (Assert $p) => $p->where('giftCards.total', 1)->where('giftCards.data.0.id', $other));
        $this->get('/admin/gift-cards/'.$id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p->where('giftCard.deleted_at', fn ($v) => $v !== null)->where('history.total', 2));
        self::assertSame(1, DB::table('order_payments')->where('gift_card_id', $id)->count(), 'The order keeps its payment');
        $this->invalid(fn () => app(GiftCards::class)->save($this->actor(), $id, $this->definition(['version' => 2])), 'version');
        // The code stays reserved.
        self::assertSame('23505', $this->sqlState(fn () => DB::table('gift_cards')->where('id', $other)->update(['number' => $code])));

        $a = $this->card();
        $b = $this->card();
        $this->delete('/admin/gift-cards/bulk', ['rows' => [['id' => $a, 'version' => 1], ['id' => $b, 'version' => 9]]])->assertSessionHasErrors('version');
        self::assertNull($this->row($a)->deleted_at, 'A stale row stops the whole batch');
        $this->delete('/admin/gift-cards/bulk', ['rows' => [['id' => $a, 'version' => 1], ['id' => $b, 'version' => 1]]])->assertSessionHasNoErrors();
        self::assertNotNull($this->row($a)->deleted_at);
        self::assertNotNull($this->row($b)->deleted_at);
    }

    public function test_gift_card_secrets_and_personal_data_stay_out_of_the_audit_trail(): void
    {
        $id = $this->card(['recipient_email' => 'private.person@example.test', 'message' => 'a secret note', 'notify' => true]);
        $code = $this->row($id)->number;
        app(GiftCardRedemptions::class)->redeem($this->actor(), (string) $this->order(), $code);
        $trail = json_encode(DB::table('audit_logs')->get()->all());
        self::assertStringNotContainsString($code, $trail);
        self::assertStringNotContainsString('private.person@example.test', $trail);
        self::assertStringNotContainsString('a secret note', $trail);
        self::assertGreaterThan(0, DB::table('audit_logs')->where('entity_type', 'gift_card')->count());
        self::assertNull(DB::table('audit_logs')->where('entity_type', 'gift_card')->value('entity_label'), 'Not even a label is derived from the card');
    }
}
