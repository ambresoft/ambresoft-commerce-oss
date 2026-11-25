<?php

namespace App\Identity\Presentation\Http\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Auth\StaffPasskeys;
use App\Identity\Presentation\Http\SessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class StaffPasskeyController
{
    public function loginOptions(Request $request, StaffPasskeys $passkeys): JsonResponse
    {
        return response()->json($passkeys->loginOptions($request));
    }

    public function login(Request $request, StaffPasskeys $passkeys, SessionLifecycle $sessions): JsonResponse
    {
        $sessions->begin($request, $passkeys->login($request));

        return response()->json(['redirect' => route('admin.dashboard', [], false)]);
    }

    public function index(Request $request, AdminNavigation $navigation): Response
    {
        Gate::authorize('access-admin');

        return Inertia::render('Auth/Passkeys', ['navigation' => $navigation->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => route('admin.dashboard', [], false)], ['label' => __('auth.passkeys'), 'href' => null]],
            'passkeys' => DB::table('staff_passkeys')->where('user_id', $request->user()->id)->orderBy('id')->get(['id', 'created_at'])]);
    }

    public function options(Request $request, StaffPasskeys $passkeys): JsonResponse
    {
        Gate::authorize('access-admin');
        $request->validate(['password' => ['required', 'string', 'max:128', 'current_password:web']]);

        return response()->json($passkeys->creationOptions($request, $request->user(), 'self'));
    }

    public function store(Request $request, StaffPasskeys $passkeys): JsonResponse
    {
        Gate::authorize('access-admin');
        DB::transaction(function () use ($request, $passkeys): void {
            DB::select('SELECT pg_advisory_xact_lock(2142512)');
            $user = $request->user()->fresh();
            Gate::forUser($user)->authorize('access-admin');
            $passkeys->enroll($request, $user, 'self');
        });

        return response()->json(['redirect' => route('admin.passkeys.index', [], false)]);
    }

    public function destroy(Request $request, string $passkey): JsonResponse
    {
        Gate::authorize('access-admin');
        $request->validate(['password' => ['required', 'string', 'max:128', 'current_password:web']]);
        DB::transaction(function () use ($request, $passkey): void {
            $deleted = DB::table('staff_passkeys')->where('user_id', $request->user()->id)->where('id', $passkey)->delete();
            if ($deleted) {
                app(AuditLogger::class)->record(new AuditEvent('auth.passkey_revoked', 'user', (string) $request->user()->id));
            }
        });

        return response()->json(['redirect' => route('admin.passkeys.index', [], false)]);
    }
}
