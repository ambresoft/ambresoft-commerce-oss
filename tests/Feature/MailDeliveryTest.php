<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Application\Command\OutgoingMail;
use App\Order\Infrastructure\ConfiguredMailTransport;
use App\Order\Infrastructure\PhpMailTransport;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class MailDeliveryTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    /** @param array<string, mixed> $fields */
    private function save(array $fields): TestResponse
    {
        return $this->put('/admin/settings/general', ['version' => (int) DB::table('general_settings')->value('lock_version'), 'out_of_stock_action' => 'N', ...$fields]);
    }

    private function fresh(): GeneralSettings
    {
        app()->forgetInstance(GeneralSettings::class);

        return app(GeneralSettings::class);
    }

    private function mail(array $changes = []): OutgoingMail
    {
        return new OutgoingMail(...array_replace(['fromAddress' => 'orders@shop.example', 'fromName' => 'Shop', 'to' => 'maria@example.test', 'subject' => 'Γεια', 'html' => '<p>Hi <img src="cid:store-logo"></p>', 'text' => 'Hi', 'replyTo' => null, 'inline' => [['cid' => 'store-logo', 'mime' => 'image/png', 'content' => 'PNGDATA']]], $changes));
    }

    public function test_smtp_settings_are_saved_with_an_encrypted_write_only_password(): void
    {
        $this->save(['mail_method' => 'smtp', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => '587', 'smtp_encryption' => 'tls', 'smtp_auth' => true, 'smtp_username' => 'shop@gmail.com', 'smtp_password' => 'app-secret-123'])->assertSessionHasNoErrors();
        $row = DB::table('general_settings')->sole();
        self::assertSame(['smtp', 'smtp.gmail.com', 587, 'tls', true], [$row->mail_method, $row->smtp_host, (int) $row->smtp_port, $row->smtp_encryption, (bool) $row->smtp_auth]);
        self::assertStringNotContainsString('app-secret-123', (string) $row->smtp_password, 'Stored encrypted');
        self::assertSame('app-secret-123', $this->fresh()->mailDelivery()['password']);
        $this->get('/admin/settings/general')->assertInertia(fn (Assert $page) => $page->where('settings.smtp_password_set', true)->missing('settings.smtp_password'));
        self::assertStringNotContainsString('app-secret-123', (string) $this->get('/admin/settings/general')->getContent());
        // Neither the password nor the username reaches the audit ledger.
        $audits = DB::table('audit_logs')->where('entity_type', 'general_settings')->pluck('changes')->implode(' ');
        self::assertStringContainsString('smtp.gmail.com', $audits);
        self::assertStringNotContainsString('shop@gmail.com', $audits);
        self::assertStringNotContainsString((string) $row->smtp_password, $audits);
        // An empty password keeps the saved one; "remove" clears it (and authentication then needs a new one).
        $this->save(['smtp_port' => '', 'smtp_password' => ''])->assertSessionHasNoErrors();
        self::assertSame(['app-secret-123', null], [$this->fresh()->mailDelivery()['password'], $this->fresh()->mailDelivery()['port']]);
        $this->save(['smtp_password_clear' => true])->assertSessionHasErrors('smtp_password');
        $this->save(['smtp_auth' => false, 'smtp_password_clear' => true])->assertSessionHasNoErrors();
        self::assertNull(DB::table('general_settings')->value('smtp_password'));
    }

    public function test_delivery_settings_are_validated(): void
    {
        $this->save(['mail_method' => 'smtp', 'smtp_host' => ''])->assertSessionHasErrors('smtp_host');
        $this->save(['mail_method' => 'smtp', 'smtp_host' => 'mail.example.test', 'smtp_auth' => true])->assertSessionHasErrors(['smtp_username', 'smtp_password']);
        $this->save(['mail_method' => 'smtp', 'smtp_host' => "evil\r\nhost"])->assertSessionHasErrors('smtp_host');
        $this->save(['mail_method' => 'smtp', 'smtp_host' => 'mail.example.test', 'smtp_port' => '70000'])->assertSessionHasErrors('smtp_port');
        $this->save(['mail_method' => 'smtp', 'smtp_host' => 'mail.example.test', 'smtp_encryption' => 'starttls'])->assertSessionHasErrors('smtp_encryption');
        $this->save(['mail_method' => 'carrier-pigeon'])->assertSessionHasErrors('mail_method');
        // Only sendmail-compatible programs: an arbitrary path would run any program on the server.
        foreach (['/bin/sh', '/usr/bin/php', 'sendmail', '/usr/sbin/../../bin/sendmail', '/usr/sbin/sendmail -t; rm -rf /'] as $path) {
            $this->save(['mail_method' => 'sendmail', 'sendmail_path' => $path])->assertSessionHasErrors('sendmail_path');
        }
        $this->save(['mail_method' => 'sendmail', 'sendmail_path' => '/usr/bin/msmtp'])->assertSessionHasNoErrors();
        self::assertSame(['sendmail', '/usr/bin/msmtp'], [DB::table('general_settings')->value('mail_method'), DB::table('general_settings')->value('sendmail_path')]);
    }

    public function test_the_configured_method_selects_the_transport_on_every_send(): void
    {
        $phpCalls = 0;
        $php = new PhpMailTransport(function () use (&$phpCalls): bool {
            $phpCalls++;

            return true;
        });
        $recorder = new class implements TransportInterface
        {
            /** @var list<array<string, mixed>> */
            public array $configs = [];

            /** @var list<string> */
            public array $messages = [];

            public array $config = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->messages[] = $message->toString();

                return null;
            }

            public function __toString(): string
            {
                return 'recorder://';
            }
        };
        $transport = new ConfiguredMailTransport(app(GeneralSettings::class), $php, function (array $config) use ($recorder): TransportInterface {
            $recorder->configs[] = $config;

            return $recorder;
        });
        self::assertTrue($transport->send($this->mail())->accepted);
        self::assertSame([1, []], [$phpCalls, $recorder->configs]);

        $this->save(['mail_method' => 'smtp', 'smtp_host' => 'smtp.example.test', 'smtp_encryption' => 'ssl', 'smtp_auth' => true, 'smtp_username' => 'shop', 'smtp_password' => 'pw-1'])->assertSessionHasNoErrors();
        $transport = new ConfiguredMailTransport($this->fresh(), $php, function (array $config) use ($recorder): TransportInterface {
            $recorder->configs[] = $config;

            return $recorder;
        });
        self::assertTrue($transport->send($this->mail())->accepted);
        self::assertSame(['smtp', 'smtp.example.test', 'ssl', 'shop', 'pw-1'], [$recorder->configs[0]['method'], $recorder->configs[0]['host'], $recorder->configs[0]['encryption'], $recorder->configs[0]['username'], $recorder->configs[0]['password']]);
        // The logo travels as an embedded part referenced by its Content-ID.
        self::assertMatchesRegularExpression('/Content-ID: <[^>]+>/', $recorder->messages[0]);
        self::assertStringNotContainsString('src="cid:store-logo"', quoted_printable_decode($recorder->messages[0]), 'Symfony rewrites the reference to the generated Content-ID');
        // Header safety applies to SMTP too, before anything is sent.
        self::assertSame('invalid_header', $transport->send($this->mail(['subject' => "Hi\r\nBcc: x@example.test"]))->error);
        self::assertCount(1, $recorder->messages);
        self::assertSame(1, $phpCalls);
    }

    public function test_the_real_transports_are_built_from_the_settings(): void
    {
        $build = fn (array $config) => (new \ReflectionMethod(ConfiguredMailTransport::class, 'transport'))->invoke(new ConfiguredMailTransport(app(GeneralSettings::class), new PhpMailTransport), $config);
        $base = ['method' => 'smtp', 'host' => 'smtp.example.test', 'port' => null, 'auth' => true, 'username' => 'shop', 'password' => 'pw', 'sendmail' => '/usr/sbin/sendmail'];
        foreach (['ssl' => [465, true], 'tls' => [587, false], 'none' => [25, false]] as $encryption => [$port, $implicitTls]) {
            $smtp = $build([...$base, 'encryption' => $encryption]);
            self::assertInstanceOf(EsmtpTransport::class, $smtp);
            /** @var SocketStream $stream */
            $stream = $smtp->getStream();
            self::assertSame(['smtp.example.test', $port, $implicitTls], [$stream->getHost(), $stream->getPort(), $stream->isTLS()]);
            self::assertSame(['shop', 'pw'], [$smtp->getUsername(), $smtp->getPassword()]);
        }
        self::assertSame(2525, $build([...$base, 'encryption' => 'tls', 'port' => 2525])->getStream()->getPort());
        self::assertInstanceOf(SendmailTransport::class, $build([...$base, 'method' => 'sendmail']));
        // An SMTP configuration without its password is reported instead of attempted.
        DB::table('general_settings')->update(['mail_method' => 'smtp', 'smtp_host' => 'smtp.example.test', 'smtp_auth' => true, 'smtp_username' => 'shop', 'smtp_password' => null]);
        self::assertSame('smtp_incomplete', (new ConfiguredMailTransport($this->fresh(), new PhpMailTransport(fn (): bool => true)))->send($this->mail())->error);
    }
}
