<?php

namespace Tests\Feature;

use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\RemoteImageDownload;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class RemoteImageDownloadTest extends TestCase
{
    /** @param list<string> $addresses */
    private function downloader(array $addresses): RemoteImageDownload
    {
        return new class(app(PrivateImageStorage::class), $addresses) extends RemoteImageDownload
        {
            public function __construct(PrivateImageStorage $images, private array $resolved)
            {
                parent::__construct($images);
            }

            protected function addresses(string $host): array
            {
                return $this->resolved;
            }
        };
    }

    public function test_private_reserved_and_mixed_dns_answers_never_send_a_request(): void
    {
        Http::preventStrayRequests();
        $temporary = tmpfile();
        try {
            foreach ([[], ['127.0.0.1'], ['10.0.0.1'], ['169.254.169.254'], ['100.64.0.1'], ['::1'], ['fc00::1'], ['::ffff:127.0.0.1'], ['93.184.215.14', '192.168.1.1']] as $addresses) {
                try {
                    $this->downloader($addresses)->download('https://images.example.com/a.png', stream_get_meta_data($temporary)['uri'], 15);
                    self::fail('A non-public host must be rejected.');
                } catch (ValidationException $failure) {
                    self::assertArrayHasKey('image_url', $failure->errors());
                }
            }
            Http::assertNothingSent();
        } finally {
            fclose($temporary);
        }
    }

    public function test_transport_pins_public_dns_disables_redirects_and_bounds_streamed_downloads(): void
    {
        Http::preventStrayRequests();
        $url = 'https://images.example.com/a.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII=');
        $options = [];
        Http::fake([$url => function ($request, $received) use (&$options, $png) {
            $options = $received;

            return Http::response($png);
        }]);
        $temporary = tmpfile();
        try {
            $metadata = $this->downloader(['93.184.215.14'])->download($url, stream_get_meta_data($temporary)['uri'], 7);
            self::assertSame(68, $metadata['byte_size']);
            self::assertSame(['images.example.com:443:93.184.215.14'], $options['curl'][CURLOPT_RESOLVE]);
            self::assertFalse($options['allow_redirects']);
            self::assertSame('', $options['proxy']);
            self::assertTrue($options['verify']);
            self::assertGreaterThan(6, $options['timeout']);
            self::assertLessThanOrEqual(7, $options['timeout']);
            // Exercise the installed cURL handler's option validation without a network call.
            $factory = new CurlFactory(1);
            $handle = $factory->create(new Request('GET', $url), $options);
            $factory->release($handle);
            Http::assertSentCount(1);
            $this->expectException(\RuntimeException::class);
            $options['progress'](0, 5242881, 0, 0);
        } finally {
            fclose($temporary);
        }
    }

    public function test_connection_failure_and_expired_download_budget_fail_safely(): void
    {
        Http::preventStrayRequests();
        $url = 'https://images.example.com/a.png';
        Http::fake([$url => Http::failedConnection()]);
        $temporary = tmpfile();
        try {
            foreach ([15, 0] as $timeout) {
                try {
                    $this->downloader(['93.184.215.14'])->download($url, stream_get_meta_data($temporary)['uri'], $timeout);
                    self::fail('Expected a download error.');
                } catch (ValidationException $failure) {
                    self::assertSame(__('category_import.image_failed'), $failure->errors()['image_url'][0]);
                }
            }
            Http::assertSentCount(1);
        } finally {
            fclose($temporary);
        }
    }
}
