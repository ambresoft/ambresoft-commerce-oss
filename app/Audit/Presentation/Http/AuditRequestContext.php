<?php

namespace App\Audit\Presentation\Http;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Audit\Infrastructure\AuditWriteFailure;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final readonly class AuditRequestContext
{
    public function __construct(private AuditContext $context, private AuditLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->start([
            'source' => $request->is('admin*') ? 'admin_ui' : 'web',
            'ip_address' => config('audit.ip_address') ? $request->ip() : null,
            'user_agent' => config('audit.user_agent') ? mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $request->userAgent() ?? '') ?? '', 0, 254) : null,
        ]);
        $this->context->actor($request->user() ? (string) $request->user()->getAuthIdentifier() : null);
        $id = $this->context->data()['request_id'];
        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $id);
            if ($response->getStatusCode() >= 400 && (in_array($response->getStatusCode(), [403, 429], true) || ! $request->isMethodSafe())) {
                $this->failure($request, $response->getStatusCode());
            }
            if ($response->getStatusCode() >= 500 && $request->routeIs('login.store', 'register.store', 'admin.login.store', 'admin.register.store', 'admin.passkeys.login')) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }
            if ($request->routeIs('admin.*') && $response->getStatusCode() < 400 && $request->session()->get('errors') !== null && in_array('errors', $request->session()->get('_flash.new', []), true) && ! in_array('feature_impact', $request->session()->get('_flash.new', []), true)) {
                $this->failure($request, 422);
            }

            return $response;
        } catch (AuditWriteFailure $failure) {
            if ($request->routeIs('login.store', 'register.store', 'admin.login.store', 'admin.register.store', 'admin.passkeys.login')) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }
            throw $failure;
        } catch (Throwable $failure) {
            $status = $failure instanceof AuthorizationException ? 403 : ($failure instanceof ValidationException ? 422 : ($failure instanceof HttpExceptionInterface ? $failure->getStatusCode() : 500));
            if (in_array($status, [403, 429], true) || ! $request->isMethodSafe()) {
                if (! $request->routeIs('login.store') || in_array($status, [403, 429], true)) {
                    $this->failure($request, $status);
                }
            }
            throw $failure;
        } finally {
            $this->context->clear();
        }
    }

    private function failure(Request $request, int $status): void
    {
        if ($this->context->failureRecorded()) {
            return;
        }
        $this->context->markFailure();
        $this->logger->record(new AuditEvent(match ($status) {
            403 => 'security.denied', 429 => 'security.throttled', default => 'operation.failed'
        }, 'request', status: $status === 403 ? 'denied' : 'failure', metadata: ['route' => $request->route()?->getName() ?? 'unmatched', 'reason' => (string) $status]));
    }
}
