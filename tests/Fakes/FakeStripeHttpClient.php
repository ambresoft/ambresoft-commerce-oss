<?php

namespace Tests\Fakes;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/**
 * A stand-in for Stripe's HTTP layer used with the official SDK (`ApiRequestor::setHttpClient`), so tests never reach
 * the network. It models the few Checkout Session endpoints the add-on uses: sessions are created idempotently by
 * `Idempotency-Key`, can be retrieved with an expanded PaymentIntent, listed and expired. Tests edit `$sessions` to
 * simulate what the customer did at Stripe.
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>, headers: array<string, string>}> */
    public array $requests = [];

    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var array<string, string> Idempotency key => session ID */
    private array $keys = [];

    /** 'connection' throws a transport error on session creation; 'invalid' answers 400; 'auth' answers 401 everywhere. */
    public ?string $failure = null;

    public int $connectionFailures = 0;

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $named = [];
        foreach ($headers as $line) {
            [$name, $value] = array_pad(explode(':', (string) $line, 2), 2, '');
            $named[strtolower(trim($name))] = trim($value);
        }
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => (array) $params, 'headers' => $named];
        if ($this->failure === 'auth') {
            return $this->error(401, 'invalid_request_error', 'Invalid API Key provided: sk_test_***');
        }
        if ($method === 'post' && $path === '/v1/checkout/sessions') {
            if ($this->failure === 'connection') {
                $this->connectionFailures++;
                throw new ApiConnectionException('Could not connect to Stripe');
            }
            if ($this->failure === 'invalid') {
                return $this->error(400, 'invalid_request_error', 'Amount must be at least 50 cents', 'amount_too_small');
            }
            $key = $named['idempotency-key'] ?? bin2hex(random_bytes(8));
            $id = $this->keys[$key] ??= 'cs_test_'.substr(md5($key), 0, 24);
            $params = (array) $params;
            $this->sessions[$id] ??= [
                'id' => $id, 'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'open', 'payment_status' => 'unpaid',
                'client_reference_id' => $params['client_reference_id'] ?? null, 'currency' => $params['line_items'][0]['price_data']['currency'] ?? null,
                'amount_total' => $params['line_items'][0]['price_data']['unit_amount'] ?? null, 'payment_intent' => null, 'livemode' => false,
                'url' => 'https://checkout.stripe.com/c/pay/'.$id, 'metadata' => $params['metadata'] ?? [],
            ];

            return $this->json($this->sessions[$id]);
        }
        if ($method === 'post' && preg_match('#^/v1/checkout/sessions/([^/]+)/expire$#', $path, $m) === 1) {
            if (! isset($this->sessions[$m[1]])) {
                return $this->error(404, 'invalid_request_error', 'No such checkout.session', 'resource_missing');
            }
            if ($this->sessions[$m[1]]['status'] === 'open') {
                $this->sessions[$m[1]]['status'] = 'expired';
            }

            return $this->json($this->sessions[$m[1]]);
        }
        if ($method === 'get' && preg_match('#^/v1/checkout/sessions/([^/]+)$#', $path, $m) === 1) {
            if (! isset($this->sessions[$m[1]])) {
                return $this->error(404, 'invalid_request_error', 'No such checkout.session', 'resource_missing');
            }
            $session = $this->sessions[$m[1]];
            $expand = (array) (((array) $params)['expand'] ?? []);
            if (in_array('payment_intent', $expand, true) && is_string($session['payment_intent'])) {
                $session['payment_intent'] = ['id' => $session['payment_intent'], 'object' => 'payment_intent', 'status' => $session['payment_intent_status'] ?? 'succeeded'];
            }

            return $this->json($session);
        }
        if ($method === 'get' && $path === '/v1/checkout/sessions') {
            return $this->json(['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/checkout/sessions']);
        }

        return $this->error(404, 'invalid_request_error', 'Unrecognized request URL ('.$method.' '.$path.')');
    }

    /** The session of the latest creation request, as sent by the CMS. */
    public function lastCreate(): ?array
    {
        $created = array_values(array_filter($this->requests, fn (array $r): bool => $r['method'] === 'post' && $r['path'] === '/v1/checkout/sessions'));

        return $created === [] ? null : end($created);
    }

    public function count(string $method, string $path): int
    {
        return count(array_filter($this->requests, fn (array $r): bool => $r['method'] === $method && $r['path'] === $path));
    }

    /** Marks a session as paid at Stripe, the way a completed card payment looks. */
    public function pay(string $id, string $intent = 'pi_test_123'): void
    {
        $this->sessions[$id] = [...$this->sessions[$id], 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => $intent, 'payment_intent_status' => 'succeeded'];
    }

    /** @return array{0: string, 1: int, 2: array<string, string>} */
    private function json(array $body): array
    {
        return [json_encode($body, JSON_THROW_ON_ERROR), 200, ['Request-Id' => 'req_test']];
    }

    /** @return array{0: string, 1: int, 2: array<string, string>} */
    private function error(int $status, string $type, string $message, ?string $code = null): array
    {
        return [json_encode(['error' => array_filter(['type' => $type, 'message' => $message, 'code' => $code])], JSON_THROW_ON_ERROR), $status, ['Request-Id' => 'req_test']];
    }
}
