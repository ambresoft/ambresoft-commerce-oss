<?php

namespace App\Storefront\Infrastructure;

use App\Settings\Infrastructure\CheckoutSettings;
use App\Settings\Infrastructure\TaxIds;
use App\Shared\Infrastructure\Geography\Countries;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;

/** A recent provider result belongs to this session, cart, customer and settings version; the browser cannot grant it. */
final class InvoiceVerification
{
    public const AADE = 'https://www1.gsis.gr/wsaade/RgWsPublic2/RgWsPublic2';

    public const GLOBAL = 'https://api.vatsense.com/1.0/validate';

    public const VIES = 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService';

    private const EU = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    private const SESSION = 'checkout.invoice_verification';

    private const LIFETIME = 900;

    public function __construct(private CheckoutSettings $settings) {}

    /** @return array<string, list<string>> */
    public static function countryCoverage(): array
    {
        return ['aade' => ['GR'], 'local' => ['GR'], 'vies' => self::EU, 'vatsense' => [...self::EU, 'GB', 'AU', 'NO', 'CH', 'ZA', 'BR']];
    }

    /** @param array{country_codes: list<string>, greek_provider: string, international_provider: string} $config
     * @return list<string> */
    public static function countryCodes(array $config): array
    {
        $coverage = self::countryCoverage();

        return array_values(array_filter($config['country_codes'], fn (string $code): bool => in_array($code, $coverage[$config[$code === 'GR' ? 'greek_provider' : 'international_provider']], true)));
    }

    /** @return array{country_code: string, vat_number: string} */
    private function number(mixed $country, mixed $number): array
    {
        $data = Validator::make(['country_code' => $country, 'vat_number' => $number], [
            'country_code' => ['required', 'string', Rule::in(app(Countries::class)->codes())],
            'vat_number' => ['required', 'string', 'max:40'],
        ])->validate();
        $vat = strtoupper(preg_replace('/[\s.\-]+/u', '', $data['vat_number']) ?? '');
        $prefixes = $country === 'GR' ? ['GR', 'EL'] : [$country];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($vat, $prefix)) {
                $vat = substr($vat, 2);
                break;
            }
        }
        if (preg_match('/^[A-Z0-9]{2,32}$/D', $vat) !== 1 || ($country === 'GR' && ! TaxIds::greek($vat))) {
            $this->fail('invalid');
        }

        return ['country_code' => $data['country_code'], 'vat_number' => $vat];
    }

    /** @param array<string, mixed> $input
     * @return array<string, string|null> */
    public function verify(Request $request, object $cart, ?string $customer, array $input): array
    {
        $number = $this->number($input['country_code'] ?? null, $input['vat_number'] ?? null);
        $config = $this->settings->all();
        if (! $config['enabled'] || ! in_array($number['country_code'], self::countryCodes($config), true)) {
            $this->fail('unavailable');
        }
        $provider = $config[$number['country_code'] === 'GR' ? 'greek_provider' : 'international_provider'];
        if ($provider === 'aade' && $customer === null) {
            $this->fail('sign_in');
        }
        if (($proof = $this->proof($request, $cart, $customer, $number, $config['version'])) !== null) {
            return $proof;
        }
        $request->session()->forget(self::SESSION);
        $credentials = $this->settings->credentials();
        if (($provider === 'aade' && ! $config['aade_configured']) || ($provider === 'vatsense' && ! $config['vatsense_configured'])) {
            $this->fail('unavailable');
        }
        // Bound provider costs across sessions/IPs as well as the route's per-visitor throttle.
        if ($provider !== 'local' && RateLimiter::tooManyAttempts('checkout-vat-provider', 60)) {
            $this->fail('unavailable');
        }
        if ($provider !== 'local') {
            RateLimiter::hit('checkout-vat-provider', 60);
        }
        try {
            $name = match ($provider) {
                'aade' => $this->aade($number['vat_number'], $credentials),
                'vatsense' => $this->global($number, $credentials['vatsense_key']),
                'vies' => $this->vies($number),
                'local' => null, // The merchant deliberately selected the Greek checksum-only policy.
                default => $this->fail('unavailable'),
            };
        } catch (ConnectionException) {
            $this->fail('unavailable');
        }
        $details = [...$number, 'business_name' => $name, 'provider' => $provider, 'verified_at' => now()->toIso8601String()];
        $request->session()->put(self::SESSION, ['cart' => (string) $cart->uuid, 'customer' => $customer, 'version' => $config['version'], 'time' => now()->getTimestamp(), 'details' => $details]);

        return $details;
    }

    /** @param array<string, mixed> $input
     * @return array<string, string|null> */
    public function forOrder(Request $request, object $cart, ?string $customer, array $input): array
    {
        $number = $this->number($input['invoice_country_code'] ?? null, $input['invoice_vat_number'] ?? null);
        $config = $this->settings->all();
        $proof = $config['enabled'] && in_array($number['country_code'], self::countryCodes($config), true) ? $this->proof($request, $cart, $customer, $number, $config['version']) : null;
        if ($proof === null) {
            throw ValidationException::withMessages(['invoice_requested' => __('checkout.verify_first')]);
        }

        return $proof;
    }

    /** @param array{country_code: string, vat_number: string} $number
     * @return array<string, string|null>|null */
    private function proof(Request $request, object $cart, ?string $customer, array $number, int $version): ?array
    {
        $proof = $request->session()->get(self::SESSION);
        if (! is_array($proof) || ($proof['cart'] ?? null) !== (string) $cart->uuid || ($proof['customer'] ?? null) !== $customer || ($proof['version'] ?? null) !== $version
            || ! is_int($proof['time'] ?? null) || $proof['time'] > now()->getTimestamp() || $proof['time'] < now()->getTimestamp() - self::LIFETIME || ! is_array($proof['details'] ?? null)) {
            return null;
        }
        $details = $proof['details'];

        return ($details['country_code'] ?? null) === $number['country_code'] && ($details['vat_number'] ?? null) === $number['vat_number'] ? $details : null;
    }

    /** @param array<string, string> $credentials */
    private function aade(string $vat, array $credentials): ?string
    {
        $escape = fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $username = $escape($credentials['aade_username']);
        $password = $escape($credentials['aade_password']);
        $xml = '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope" xmlns:sec="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd" xmlns:svc="http://rgwspublic2/RgWsPublic2Service" xmlns:reg="http://rgwspublic2/RgWsPublic2"><env:Header><sec:Security><sec:UsernameToken><sec:Username>'.$username.'</sec:Username><sec:Password>'.$password.'</sec:Password></sec:UsernameToken></sec:Security></env:Header><env:Body><svc:rgWsPublic2AfmMethod><svc:INPUT_REC><reg:afm_called_by/><reg:afm_called_for>'.$vat.'</reg:afm_called_for></svc:INPUT_REC></svc:rgWsPublic2AfmMethod></env:Body></env:Envelope>';
        $response = Http::connectTimeout(3)->timeout(15)->withoutRedirecting()->withBody($xml, 'application/soap+xml; charset=UTF-8')->post(self::AADE);
        if (! $response->successful()) {
            $this->fail('unavailable');
        }
        $document = $this->xml($response->body());
        $results = $document->xpath('//*[local-name()="rg_ws_public2_result_rtType"]') ?: [];
        if (count($results) !== 1) {
            $this->fail('unavailable');
        }
        $result = $results[0];
        if (trim((string) ($result->xpath('./*[local-name()="error_rec"]/*[local-name()="error_code"]')[0] ?? '')) !== '') {
            $this->fail('unavailable');
        }
        $records = $result->xpath('./*[local-name()="basic_rec"]') ?: [];
        if (count($records) !== 1) {
            $this->fail('invalid');
        }
        $value = fn (string $key): string => trim((string) ($records[0]->xpath('./*[local-name()="'.$key.'"]')[0] ?? ''));
        if ($value('afm') !== $vat || $value('deactivation_flag') !== '1' || $value('firm_flag_descr') !== 'ΕΠΙΤΗΔΕΥΜΑΤΙΑΣ' || $value('stop_date') !== '') {
            $this->fail('invalid');
        }

        return $this->name($value('onomasia'));
    }

    /** @param array{country_code: string, vat_number: string} $number */
    private function vies(array $number): ?string
    {
        if (! in_array($number['country_code'], self::EU, true)) {
            $this->fail('unavailable');
        }
        if (strlen($number['vat_number']) > 12) {
            $this->fail('invalid');
        }
        $country = $number['country_code'] === 'GR' ? 'EL' : $number['country_code'];
        $xml = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:vat="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><soap:Body><vat:checkVat><vat:countryCode>'.$country.'</vat:countryCode><vat:vatNumber>'.$number['vat_number'].'</vat:vatNumber></vat:checkVat></soap:Body></soap:Envelope>';
        $response = Http::connectTimeout(3)->timeout(15)->withoutRedirecting()->withHeaders(['SOAPAction' => '""'])->withBody($xml, 'text/xml; charset=UTF-8')->post(self::VIES);
        if (! $response->successful()) {
            $this->fail('unavailable');
        }
        $document = $this->xml($response->body());
        $records = $document->xpath('//*[local-name()="checkVatResponse" and namespace-uri()="urn:ec.europa.eu:taxud:vies:services:checkVat:types"]') ?: [];
        if (count($records) !== 1) {
            $this->fail('unavailable');
        }
        $value = fn (string $key): string => trim((string) ($records[0]->xpath('./*[local-name()="'.$key.'"]')[0] ?? ''));
        if ($value('countryCode') !== $country || $value('vatNumber') !== $number['vat_number']) {
            $this->fail('unavailable');
        }
        if (in_array($value('valid'), ['false', '0'], true)) {
            $this->fail('invalid');
        }
        if (! in_array($value('valid'), ['true', '1'], true)) {
            $this->fail('unavailable');
        }

        return $value('name') === '---' ? null : $this->name($value('name'));
    }

    private function xml(string $body): SimpleXMLElement
    {
        if (strlen($body) > 262144 || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            $this->fail('unavailable');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($document === false || ($document->xpath('//*[local-name()="Fault"]') ?: []) !== []) {
            $this->fail('unavailable');
        }

        return $document;
    }

    /** @param array{country_code: string, vat_number: string} $number */
    private function global(array $number, string $key): ?string
    {
        if (! in_array($number['country_code'], self::countryCoverage()['vatsense'], true)) {
            $this->fail('unavailable');
        }
        $response = Http::withBasicAuth('user', $key)->connectTimeout(3)->timeout(15)->withoutRedirecting()->get(self::GLOBAL, ['vat_number' => $number['country_code'].$number['vat_number']]);
        if (! $response->successful() || strlen($response->body()) > 262144 || $response->json('success') !== true) {
            $this->fail('unavailable');
        }
        if ($response->json('data.valid') === false) {
            $this->fail('invalid');
        }
        if ($response->json('data.valid') !== true) {
            $this->fail('unavailable');
        }
        $country = $response->json('data.company.country_code');
        $vat = $response->json('data.company.vat_number');
        if (! is_string($country) || ! is_string($vat) || $this->number($country, $vat) !== $number) {
            $this->fail('unavailable');
        }

        return $this->name($response->json('data.company.company_name'));
    }

    private function name(mixed $name): ?string
    {
        return is_string($name) && trim($name) !== '' ? mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', trim($name)) ?? '', 0, 200) : null;
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['vat_number' => __('checkout.'.$key)]);
    }
}
