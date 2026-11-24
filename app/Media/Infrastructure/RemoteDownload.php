<?php

namespace App\Media\Infrastructure;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Bounded public downloads with DNS pinning, no redirects or private network access. */
class RemoteDownload
{
    public static function validUrl(string $url): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $host = trim($parts['host'] ?? '', '[]');
        $port = $parts['scheme'] === 'https' ? 443 : 80;
        if (($parts['port'] ?? $port) !== $port || $host === '') {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) ? self::publicAddress($host) : (bool) filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && str_contains($host, '.') && ! str_ends_with(strtolower($host), '.localhost');
    }

    private static function publicAddress(string $address): bool
    {
        return (bool) filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE);
    }

    /** @return list<string> */
    protected function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_filter(array_map(static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: [])));
    }

    public function fetch(string $url, string $destination, float $timeout, int $maxBytes): int
    {
        if (! self::validUrl($url) || ! function_exists('curl_init') || $timeout <= 0) {
            throw new RuntimeException('Invalid remote file request.');
        }
        $deadline = microtime(true) + $timeout;
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $addresses = $this->addresses($host);
        if ($addresses === [] || array_filter($addresses, static fn (string $ip): bool => ! self::publicAddress($ip))) {
            throw new RuntimeException('Non-public file host.');
        }
        $timeout = $deadline - microtime(true);
        if ($timeout <= 0) {
            throw new RuntimeException('File download budget exhausted.');
        }
        $ip = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        $port = parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80;
        // Force cURL: the stream handler ignores CURLOPT_RESOLVE. Pin the checked DNS
        // address, retaining the hostname for TLS; never use environment proxies.
        $response = Http::setHandler(new CurlHandler)->withoutRedirecting()->connectTimeout(min(5, $timeout))->timeout(min(15, $timeout))->withOptions([
            'sink' => $destination,
            'proxy' => '',
            'verify' => true,
            'decode_content' => false,
            'protocols' => ['http', 'https'],
            'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$ip]],
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                    throw new RuntimeException('Remote file exceeds size limit.');
                }
            },
            'progress' => static function (float $total, float $downloaded) use ($maxBytes): void {
                if ($total > $maxBytes || $downloaded > $maxBytes) {
                    throw new RuntimeException('Remote file exceeds size limit.');
                }
            },
        ])->get($url);
        if ($response->status() !== 200) {
            throw new RuntimeException('Remote file request failed.');
        }
        clearstatcache(true, $destination);
        $size = filesize($destination);
        if (! $size || $size > $maxBytes) {
            throw new RuntimeException('Invalid remote file size.');
        }

        return $size;
    }
}
