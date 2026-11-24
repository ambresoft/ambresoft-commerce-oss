<?php

namespace App\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Notification\Infrastructure\AdminNotifications;
use Carbon\CarbonImmutable;
use donatj\UserAgent\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Dedicated sensitive history; never copies credentials or session identifiers into audit metadata. */
final class AccountSecurity
{
    public const COOKIE = 'cms_security_device';

    public function __construct(private IpLocation $locations) {}

    public function record(Request $request, UserRecord $user, string $kind): void
    {
        if (! $request->hasSession()) {
            return;
        }
        $device = $this->device($request);
        $agent = mb_substr(mb_scrub(str_replace("\0", '', (string) $request->userAgent()), 'UTF-8'), 0, 512);
        $parsed = (new UserAgentParser)->parse($agent);
        $browser = mb_substr($parsed->browser() ?? 'Unknown', 0, 100);
        $platform = mb_substr($parsed->platform() ?? 'Unknown', 0, 100);
        // Versions are deliberately excluded: a browser update is not a new device.
        $key = hash('sha256', $device.'|'.$browser.'|'.$platform);
        $ip = filter_var($request->ip(), FILTER_VALIDATE_IP) ? $request->ip() : null;
        $location = $this->locations->resolve($ip);
        DB::transaction(function () use ($request, $user, $kind, $agent, $browser, $platform, $key, $ip, $location): void {
            // Serializes first-seen checks for simultaneous logins to the same account.
            if (! DB::table('users')->where('id', $user->id)->lockForUpdate()->first(['id'])) {
                return;
            }
            $known = DB::table('identity_security_events')->where('user_id', $user->id)->whereIn('kind', ['registration', 'login']);
            $prior = (clone $known)->exists();
            $area = $request->is('admin/*') ? 'admin' : 'customer';
            $event = DB::table('identity_security_events')->insertGetId([
                'user_id' => $user->id, 'kind' => $kind, 'area' => $area,
                'ip' => $ip, 'device_key' => $key, 'browser' => $browser, 'platform' => $platform, 'user_agent' => $agent,
                ...$location,
                'new_device' => $kind === 'login' && $prior && ! (clone $known)->where('device_key', $key)->exists(),
                'new_ip' => $kind === 'login' && $prior && $ip !== null && ! (clone $known)->where('ip', $ip)->exists(),
                'occurred_at' => now(),
            ]);
            if ($kind === 'password_failed' && $area === 'admin') {
                // Exactly at the threshold: one notice per burst, however many attempts follow in the same window.
                $threshold = max(1, (int) config('account_security.admin_alert_threshold'));
                $recent = DB::table('identity_security_events')->where('user_id', $user->id)->where('kind', 'password_failed')->where('area', 'admin')
                    ->where('occurred_at', '>=', now()->subMinutes((int) config('account_security.failure_window_minutes')))->count();
                if ($recent === $threshold) {
                    app(AdminNotifications::class)->loginFailed((string) $user->id, (string) $event, $recent, $ip);
                }
            }
        });
    }

    /** Issue the same encrypted browser cookie for known and unknown login attempts. */
    public function device(Request $request): string
    {
        $device = request()->attributes->get(self::COOKIE) ?? $request->attributes->get(self::COOKIE) ?? $request->cookie(self::COOKIE);
        if (! is_string($device) || ! Str::isUuid($device)) {
            $device = (string) Str::uuid();
            Cookie::queue(cookie(self::COOKIE, $device, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        }
        $request->attributes->set(self::COOKIE, $device);
        request()->attributes->set(self::COOKIE, $device);

        return $device;
    }

    /** @return array<string, mixed> */
    public static function eventData(object $row): array
    {
        return ['id' => (string) $row->id, 'user_id' => (string) $row->user_id, 'kind' => $row->kind, 'area' => $row->area,
            'ip' => $row->ip, 'browser' => $row->browser, 'platform' => $row->platform, 'country' => $row->country,
            'city' => $row->city, 'timezone' => $row->timezone, 'new_device' => (bool) $row->new_device, 'new_ip' => (bool) $row->new_ip,
            'occurred_at' => CarbonImmutable::parse($row->occurred_at)->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function summary(string $id): array
    {
        $events = DB::table('identity_security_events')->where('user_id', $id);
        $last = (clone $events)->whereIn('kind', ['registration', 'login'])->orderByDesc('id')->first();
        $registration = (clone $events)->where('kind', 'registration')->orderBy('id')->first();
        $failed = (clone $events)->where('kind', 'password_failed');

        return [
            'identity_id' => $id, 'registration' => $registration === null ? null : self::eventData($registration), 'latest' => $last === null ? null : self::eventData($last),
            'failures' => (clone $failed)->count(),
            'recent_failures' => (clone $failed)->where('occurred_at', '>=', now()->subMinutes((int) config('account_security.failure_window_minutes')))->count(),
            'failure_threshold' => (int) config('account_security.failure_threshold'),
            'geoip_available' => is_readable((string) config('account_security.geoip_database')),
            'alerts' => (clone $events)->where(fn ($q) => $q->where('new_device', true)->orWhere('new_ip', true)->orWhere('kind', 'password_failed'))->count(),
        ];
    }
}
