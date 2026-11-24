<?php

namespace Tests\Feature;

use App\Customer\Infrastructure\CustomerInvitation;
use App\Identity\Infrastructure\Auth\StaffInvitation;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Order\Infrastructure\OrderEmailRenderer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class EmailLogoTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    public function test_all_auth_notifications_and_commerce_templates_share_the_email_logo_and_store_fallback(): void
    {
        Storage::fake('local');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $user = $this->staff();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=');
        foreach (['store', 'email'] as $kind) {
            $content = $png.$kind;
            Storage::disk('local')->put("images/$kind.png", $content);
            $media = MediaFileRecord::create(['disk' => 'local', 'storage_key' => "images/$kind.png", 'mime_type' => 'image/png', 'byte_size' => strlen($content), 'width' => 1, 'height' => 1]);
            DB::table('company_settings')->where('id', 1)->update([$kind === 'store' ? 'logo_media_id' : 'logo_email_media_id' => $media->id]);
        }
        $renderer = app(OrderEmailRenderer::class);
        // Exercise the real mail channel, so the rendered MIME attachments are checked too.
        foreach (['email', 'store', null] as $expected) {
            foreach ([new StaffInvitation('https://example.test/admin/register?token=a&signature=b'), new CustomerInvitation('https://example.test/register?token=a&signature=b', 'Shop'), new VerifyEmail, new ResetPassword('token')] as $notification) {
                $sent = app(MailChannel::class)->send($user, $notification);
                $email = $sent->getSymfonySentMessage()->getOriginalMessage();
                if ($expected === null) {
                    self::assertCount(0, $email->getAttachments());
                    self::assertStringNotContainsString('src="cid:', $email->getHtmlBody());
                } else {
                    self::assertCount(1, $email->getAttachments());
                    self::assertSame($png.$expected, $email->getAttachments()[0]->getBody());
                    self::assertStringContainsString('src="cid:', $email->getHtmlBody());
                }
                self::assertStringNotContainsString('&amp;', $email->getTextBody());
            }
            $mail = $renderer->render('Test', '<p>Body</p>', $renderer->storeVariables('en'), 'en');
            self::assertCount($expected === null ? 0 : 1, $mail['inline']);
            if ($expected !== null) {
                self::assertSame($png.$expected, $mail['inline'][0]['content']);
                self::assertStringContainsString('data:image/png;base64,'.base64_encode($png.$expected), $renderer->preview($mail['html']));
                Storage::disk('local')->delete("images/$expected.png");
            }
        }
    }
}
