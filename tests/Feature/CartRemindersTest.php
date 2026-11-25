<?php

namespace Tests\Feature;

use Ambresoft\CartReminders\Infrastructure\CartReminders;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Shared\Application\Contract\AddonSettingValues;
use App\Shared\Application\Contract\StoreMailer;
use App\Shared\Infrastructure\Modules\AddonManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

/** The bundled Abandoned cart reminders add-on and the core add-on settings it is configured through. */
final class CartRemindersTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    /** @var object{sent: list<array{to: string, subject: string, text: string, locale: string}>} */
    private object $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $addons = app(AddonManager::class);
        $addons->installBundled(null, 'ambresoft/cart-reminders');
        $addons->enable(null, 'ambresoft/cart-reminders');
        $this->mail = new class implements StoreMailer
        {
            /** @var list<array{to: string, subject: string, text: string, locale: string}> */
            public array $sent = [];

            public function send(string $to, string $subject, string $html, string $text, string $locale): ?string
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'locale' => $locale];

                return null;
            }
        };
        $this->app->instance(StoreMailer::class, $this->mail);
    }

    /** A customer with an account whose cart holds a product and was last changed at $changed. */
    private function cart(string $email, CarbonImmutable $changed): int
    {
        $user = UserRecord::create(['name' => 'Ria Stone', 'email' => $email, 'password' => 'a shopper passphrase']);
        $customer = DB::table('customers')->insertGetId(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => $email, 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $product = DB::table('products')->insertGetId(['sku' => 'R-'.Str::upper(Str::random(6)), 'price' => '10', 'quantity' => 5, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_translations')->insert(['product_id' => $product, 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Speaker', 'slug' => 'speaker-'.$product]);
        $cart = DB::table('carts')->insertGetId(['uuid' => (string) Str::uuid(), 'customer_id' => $customer, 'created_at' => $changed, 'updated_at' => $changed]);
        DB::table('cart_items')->insert(['cart_id' => $cart, 'product_id' => $product, 'quantity' => 2, 'created_at' => $changed, 'updated_at' => $changed]);

        return $cart;
    }

    public function test_settings_are_edited_behind_the_add_on_and_need_the_capability(): void
    {
        $url = '/admin/addons/ambresoft/cart-reminders/settings';
        $this->actingAs($this->staff(['admin.access']));
        $this->get($url)->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'addons.manage']));
        $this->get('/admin/addons')->assertInertia(fn ($p) => $p->where('addons', fn ($rows) => collect($rows)->firstWhere('name', 'ambresoft/cart-reminders')['settings'][0]['href'] === $url));
        $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Addons/Settings')->where('addon.fields.0.key', 'delay_hours')->where('addon.fields.0.value', '3')->where('addon.fields.1.value', '1'));
        $this->put($url, ['version' => 1, 'settings' => ['delay_hours' => '0', 'max_reminders' => '1', 'repeat_hours' => '24']])->assertSessionHasErrors('settings.delay_hours');
        $this->put($url, ['version' => 1, 'settings' => ['delay_hours' => '5', 'max_reminders' => '2', 'repeat_hours' => '24']])->assertSessionHasNoErrors();
        self::assertSame(['delay_hours' => '5', 'max_reminders' => '2', 'repeat_hours' => '24'], app(AddonSettingValues::class)->values('ambresoft/cart-reminders'));
        // A stale form does not overwrite newer settings.
        $this->put($url, ['version' => 1, 'settings' => ['delay_hours' => '9', 'max_reminders' => '1', 'repeat_hours' => '24']])->assertSessionHasErrors('version');
    }

    public function test_reminders_follow_the_delay_the_maximum_and_the_interval(): void
    {
        $now = CarbonImmutable::parse('2026-11-12 12:00:00');
        $reminders = app(CartReminders::class);
        $cart = $this->cart('ria@example.test', $now->subHours(4));
        $this->cart('recent@example.test', $now->subHours(2));
        // A guest's cart is never reminded.
        DB::table('carts')->insert(['uuid' => (string) Str::uuid(), 'customer_id' => null, 'created_at' => $now->subDay(), 'updated_at' => $now->subDay()]);

        // Defaults: once, 3 hours after the last change.
        self::assertSame(1, $reminders->sendDue($now));
        self::assertSame('ria@example.test', $this->mail->sent[0]['to']);
        self::assertSame('You left something in your cart', $this->mail->sent[0]['subject']);
        self::assertStringContainsString('Speaker × 2', $this->mail->sent[0]['text']);
        // An hour later the other cart is 3 hours old too; the first one is not reminded again.
        self::assertSame(1, $reminders->sendDue($now->addHour()));
        self::assertSame('recent@example.test', $this->mail->sent[1]['to']);
        self::assertSame(0, $reminders->sendDue($now->addDays(3)));

        // Up to two, a day apart: the second waits for the interval.
        DB::table('addon_settings')->where('addon', 'ambresoft/cart-reminders')->update(['settings' => json_encode(['delay_hours' => '3', 'max_reminders' => '2', 'repeat_hours' => '24'])]);
        DB::table('ambresoft_cart_reminders')->where('cart_id', $cart)->update(['last_sent_at' => $now]);
        self::assertSame(0, $reminders->sendDue($now->addHours(23)), 'not yet a day after the first');
        self::assertSame(2, $reminders->sendDue($now->addHours(25)), 'both carts get their second reminder');
        self::assertSame(0, $reminders->sendDue($now->addDays(5)), 'the maximum is reached');

        // Changing the cart starts a new series; emptying it stops reminders.
        DB::table('carts')->where('id', $cart)->update(['updated_at' => $now->addDays(5)]);
        self::assertSame(1, $reminders->sendDue($now->addDays(5)->addHours(4)));
        DB::table('cart_items')->where('cart_id', $cart)->delete();
        DB::table('carts')->where('id', $cart)->update(['updated_at' => $now->addDays(6)]);
        self::assertSame(0, $reminders->sendDue($now->addDays(9)));
    }
}
