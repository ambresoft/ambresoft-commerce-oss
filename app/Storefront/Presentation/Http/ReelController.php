<?php

namespace App\Storefront\Presentation\Http;

use App\Media\Infrastructure\PrivateVideoStorage;
use App\Storefront\Infrastructure\StorefrontAccess;
use App\Storefront\Infrastructure\StorefrontReels;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ReelController
{
    public function show(Request $request, string $reel, StorefrontReels $reels): JsonResponse
    {
        $this->open();
        $locale = app()->getLocale();
        $customer = $request->user() === null ? null : DB::table('customers')->where('user_id', $request->user()->id)->where('status', 'active')->value('id');
        $detail = $reels->detail($reel, ['locale' => $locale, 'customer' => $customer === null ? null : (string) $customer]) ?? abort(404);
        $token = Crypt::encryptString(json_encode(['reel' => $reel, 'viewer' => $this->viewer($request), 'issued' => now()->timestamp], JSON_THROW_ON_ERROR));

        return response()->json([...$detail, 'view_token' => $token])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function video(string $reel, string $video, StorefrontReels $reels): BinaryFileResponse
    {
        $this->open();
        abort_unless($reels->eligible()->where('id', $reel)->where('video_id', $video)->exists(), 404);

        return app(PrivateVideoStorage::class)->response($video);
    }

    public function view(Request $request, string $reel, StorefrontReels $reels): JsonResponse
    {
        $this->open();
        $input = $request->validate(['token' => ['required', 'string', 'max:2048']]);
        try {
            $token = json_decode(Crypt::decryptString($input['token']), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(422);
        }
        abort_unless(is_array($token) && ($token['reel'] ?? null) === $reel && ($token['viewer'] ?? null) === $this->viewer($request) && is_int($token['issued'] ?? null), 422);
        $elapsed = now()->timestamp - $token['issued'];
        abort_unless($elapsed >= 3 && $elapsed <= 3600, 422);
        DB::transaction(function () use ($request, $reel, $reels): void {
            abort_unless($reels->eligible()->where('id', $reel)->sharedLock()->first(['reels.id']) !== null, 404);
            // A playback signal counts once per reel/viewer/UTC half-hour, even under retries or concurrent tabs.
            DB::table('reel_views')->insertOrIgnore(['reel_id' => $reel, 'user_id' => $request->user()?->id, 'viewer_key' => $this->viewer($request), 'view_bucket' => intdiv(now()->timestamp, 1800), 'viewed_at' => now()]);
        });

        return response()->json(['status' => 'ok'])->header('Cache-Control', 'private, no-store');
    }

    private function open(): void
    {
        abort_unless(app(StorefrontAccess::class)->allowed(request()), 404);
    }

    private function viewer(Request $request): string
    {
        $identity = $request->user() === null ? 'session:'.$request->session()->getId() : 'user:'.$request->user()->id;

        return hash_hmac('sha256', $identity, config('app.key'));
    }
}
