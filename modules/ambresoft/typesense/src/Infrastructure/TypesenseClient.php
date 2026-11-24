<?php

namespace Ambresoft\Typesense\Infrastructure;

use App\Search\Application\Contract\SearchUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Typesense REST API over Laravel's HTTP client. The API key travels only in a request header from this server;
 * it is never logged, never part of a message and never sent to the browser.
 *
 * Connection errors, 429 and 5xx answers are retried on the next node with a growing pause, a bounded number of times
 * (storefront searches at most once, so a slow engine does not hold pages up). Refused credentials and invalid
 * requests are not retried.
 */
final class TypesenseClient
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return $this->nodes() !== [] && trim((string) ($this->config['api_key'] ?? '')) !== '';
    }

    public function alias(): string
    {
        $alias = (string) ($this->config['alias'] ?? 'products');

        return preg_match('/^[a-z][a-z0-9_]{0,40}$/D', $alias) === 1 ? $alias : 'products';
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $json
     * @param  'search'|'admin'|'import'  $kind
     */
    public function send(string $method, string $path, array $query = [], ?array $json = null, ?string $raw = null, string $kind = 'admin'): Response
    {
        if (! $this->configured()) {
            throw new SearchUnavailable('unavailable', 'Typesense is not configured (TYPESENSE_HOST / TYPESENSE_NODES and TYPESENSE_API_KEY).');
        }
        $nodes = $this->nodes();
        $attempts = 1 + ($kind === 'search' ? min(1, (int) $this->config['retries']) : max(0, (int) $this->config['retries']));
        $timeout = (float) ($kind === 'import' ? $this->config['import_timeout'] : ($kind === 'search' ? $this->config['timeout'] : max(10, (float) $this->config['timeout'])));
        $problem = 'unreachable';
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                usleep((int) $this->config['retry_ms'] * 1000 * $attempt);
            }
            $url = $nodes[$attempt % count($nodes)].$path;
            try {
                $request = Http::withHeaders(['X-TYPESENSE-API-KEY' => (string) $this->config['api_key'], 'Accept' => 'application/json'])
                    ->connectTimeout((float) $this->config['connect_timeout'])->timeout($timeout);
                if ($raw !== null) {
                    $request = $request->withBody($raw, 'text/plain');
                } elseif ($json !== null) {
                    $request = $request->withBody(json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'application/json');
                }
                $response = $request->send($method, $url.($query === [] ? '' : '?'.http_build_query($query)));
            } catch (ConnectionException) {
                $problem = 'unreachable or too slow';

                continue;
            }
            if ($response->status() === 429 || $response->serverError()) {
                $problem = 'busy (HTTP '.$response->status().')';

                continue;
            }
            if (in_array($response->status(), [401, 403], true)) {
                throw new SearchUnavailable('unavailable', 'Typesense refused the API key.');
            }

            return $response;
        }

        throw new SearchUnavailable('unavailable', 'Typesense is '.$problem.'.');
    }

    /**
     * A JSON request whose answer must be 2xx (404 → `not_initialized`, other refusals → `rejected`).
     *
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $json
     * @param  'search'|'admin'|'import'  $kind
     * @return array<mixed>
     */
    public function json(string $method, string $path, array $query = [], ?array $json = null, string $kind = 'admin'): array
    {
        $response = $this->send($method, $path, $query, $json, null, $kind);
        if ($response->status() === 404) {
            throw new SearchUnavailable('not_initialized', 'Typesense has no '.$path.' yet.');
        }
        if (! $response->successful()) {
            throw new SearchUnavailable('rejected', 'Typesense refused the request: '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 300));
        }

        return (array) $response->json();
    }

    /** @return list<string> Base URLs without a trailing slash */
    private function nodes(): array
    {
        $nodes = array_values(array_filter(array_map(fn (string $n): string => rtrim(trim($n), '/'), explode(',', (string) ($this->config['nodes'] ?? '')))));
        if ($nodes === [] && trim((string) ($this->config['host'] ?? '')) !== '') {
            $nodes[] = $this->config['protocol'].'://'.trim((string) $this->config['host']).':'.(int) $this->config['port'].rtrim((string) ($this->config['path'] ?? ''), '/');
        }

        return array_values(array_filter($nodes, fn (string $n): bool => preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~D', $n) === 1));
    }
}
