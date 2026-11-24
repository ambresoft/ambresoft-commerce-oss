<?php

namespace App\Notification\Infrastructure;

use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class NotificationStream
{
    public function __construct(private AdminNotifications $notifications) {}

    /** @param array<string,string> $data */
    public static function frame(string $event, string $id, array $data): string
    {
        return 'id: '.$id."\nevent: ".$event."\ndata: ".json_encode($data, JSON_THROW_ON_ERROR)."\n\n";
    }

    public function response(Request $request, UserRecord $user): StreamedResponse
    {
        $cursor = (string) $request->header('Last-Event-ID', '0');
        abort_unless(preg_match('/^\d{1,18}$/D', $cursor) === 1, 422);
        $session = $request->session();
        $sessionId = $session->getId();
        $password = $user->getAuthPassword();

        return response()->stream(function () use ($cursor, $user, $session, $sessionId, $password): void {
            $slot = null;
            $executionLimit = (int) ini_get('max_execution_time');
            $emit = static function (string $text): void {
                echo $text;
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };
            try {
                $emit('retry: '.max(3000, (int) config('admin_notifications.retry_ms'))."\n\n");
                for ($i = 0; $i < max(1, (int) config('admin_notifications.max_connections')); $i++) {
                    if (DB::selectOne('SELECT pg_try_advisory_lock(2142599, ?) AS acquired', [$i])->acquired) {
                        $slot = $i;
                        break;
                    }
                }
                if ($slot === null) {
                    $emit("event: stream.busy\ndata: {}\n\n");

                    return;
                }
                $lifetime = min(60, max(0, (int) config('admin_notifications.lifetime_seconds')));
                $check = max(1, (int) config('admin_notifications.check_seconds'));
                $heartbeat = max(3, (int) config('admin_notifications.heartbeat_seconds'));
                @set_time_limit($lifetime + 5);
                $start = microtime(true);
                $lastHeartbeat = $start;
                $first = true;
                do {
                    if (connection_aborted()) {
                        break;
                    }
                    $fresh = UserRecord::find($user->getKey());
                    if ($fresh === null || $fresh->getAuthPassword() !== $password || ! $this->notifications->eligible($fresh) || ! $session->getHandler()->read($sessionId)) {
                        $emit("event: stream.revoked\ndata: {}\n\n");
                        break;
                    }
                    $state = $this->notifications->state((string) $user->getKey());
                    $revision = (string) $state->revision;
                    if ($first || $revision !== $cursor) {
                        // A revision is a committed recipient-scoped cursor, not a timestamp or sequence allocated before commit.
                        $event = $first ? 'notifications.sync' : ((int) $state->created_revision > (int) $cursor ? 'notification.created' : 'notifications.changed');
                        $emit(self::frame($event, $revision, ['revision' => $revision]));
                        $cursor = $revision;
                        $first = false;
                    }
                    if (microtime(true) - $lastHeartbeat >= $heartbeat) {
                        $emit(": heartbeat\n\n");
                        $lastHeartbeat = microtime(true);
                    }
                    if (microtime(true) - $start >= $lifetime) {
                        break;
                    }
                    // Small sleeps notice disconnects promptly, without increasing database query frequency.
                    for ($i = 0; $i < $check * 4 && microtime(true) - $start < $lifetime && ! connection_aborted(); $i++) {
                        usleep(250000);
                    }
                } while (microtime(true) - $start < $lifetime);
                if (! connection_aborted()) {
                    $emit("event: stream.rotate\ndata: {}\n\n");
                }
            } catch (Throwable $error) {
                report($error);
                $emit("event: stream.error\ndata: {}\n\n");
            } finally {
                @set_time_limit($executionLimit);
                if ($slot !== null) {
                    try {
                        DB::select('SELECT pg_advisory_unlock(2142599, ?)', [$slot]);
                    } catch (Throwable $error) {
                        report($error);
                    }
                }
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store, no-transform', 'X-Accel-Buffering' => 'no']);
    }
}
