<?php

namespace App\Admin\Controllers;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Notification\Infrastructure\AdminNotifications;
use App\Notification\Infrastructure\NotificationStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class NotificationController
{
    public function __construct(private AdminNotifications $notifications) {}

    private function user(Request $request): UserRecord
    {
        $user = $request->user();
        abort_unless($user instanceof UserRecord && $this->notifications->eligible($user), 403);

        return $user;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $input = $request->validate(['before' => ['nullable', 'regex:/^[1-9][0-9]{0,17}$/D']]);

        return response()->json($this->notifications->listing((string) $user->getKey(), $input['before'] ?? null))->header('Cache-Control', 'no-store');
    }

    public function change(Request $request, ?string $notification = null): JsonResponse
    {
        $user = $this->user($request);
        $input = $request->validate(['action' => ['required', 'in:read,delete']]);
        abort_if($notification === null && $input['action'] !== 'read', 422);
        $this->notifications->change((string) $user->getKey(), $input['action'], $notification);

        return response()->json($this->notifications->listing((string) $user->getKey()))->header('Cache-Control', 'no-store');
    }

    public function stream(Request $request, NotificationStream $stream): StreamedResponse
    {
        return $stream->response($request, $this->user($request));
    }
}
