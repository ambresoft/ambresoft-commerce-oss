<?php

namespace App\Audit\Infrastructure;

use App\Audit\Domain\Contract\ExpectedPreview;
use App\Audit\Domain\Model\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class AuditLogger
{
    public function __construct(private AuditContext $context) {}

    /** @template T
     * @param  callable(): T  $operation
     * @return T
     */
    public function operation(string $entity, string $actor, callable $operation): mixed
    {
        $previous = $this->context->data();
        $this->context->actor($actor);
        try {
            return $operation();
        } catch (Throwable $failure) {
            if (! $failure instanceof AuditWriteFailure && ! $failure instanceof ExpectedPreview && ! $this->context->failureRecorded()) {
                $this->context->markFailure();
                $denied = $failure instanceof AuthorizationException;
                $this->record(new AuditEvent($denied ? 'security.denied' : 'operation.failed', $entity, status: $denied ? 'denied' : 'failure', metadata: ['reason' => $failure::class]));
            }
            throw $failure;
        } finally {
            if ($previous === []) {
                $this->context->clear();
            } else {
                $failed = $this->context->failureRecorded();
                $this->context->start($previous);
                if ($failed) {
                    $this->context->markFailure();
                }
            }
        }
    }

    /** Operation outcomes; field-level mutations are owned by the database capture policy. */
    public function record(AuditEvent $event): void
    {
        if (! in_array($event->action, ['brand.import_previewed', 'brand.import_completed', 'brand.import_failed', 'brand.import_cancelled', 'brand.template_downloaded', 'brand.import_errors_read', 'product.import_previewed', 'product.import_completed', 'product.import_failed', 'product.import_cancelled', 'product.template_downloaded', 'product.import_errors_read', 'cache.settings_changed', 'cache.cleared', 'addon.settings_action', 'theme_code.saved', 'product.opened', 'category.opened', 'feature.opened', 'auth.login', 'auth.login_failed', 'auth.logout', 'auth.session_created', 'auth.session_revoked', 'auth.passkey_registered', 'auth.passkey_revoked', 'auth.password_reset_requested', 'auth.password_reset_completed', 'auth.email_verified', 'security.denied', 'security.throttled', 'operation.failed', 'audit.retention_purged', 'category.import_previewed', 'category.import_cancelled', 'category.import_errors_read', 'category.template_downloaded', 'category.import_completed', 'category.import_failed', 'region.import_previewed', 'region.import_cancelled', 'region.import_errors_read', 'region.template_downloaded', 'region.import_completed', 'region.import_failed', 'product.exported', 'category.exported', 'brand.exported', 'region.exported', 'category.bulk_completed', 'product.bulk_completed', 'review.bulk_completed', 'customer.invitation_sent', 'job.completed', 'job.failed', 'command.completed', 'command.failed', 'media.read', 'faq_ai_settings.credentials_changed', 'ai_settings.credentials_changed'], true) || ! preg_match('/^[a-z][a-z_]{0,59}$/D', $event->entityType) || ! in_array($event->status, ['success', 'failure', 'denied'], true) || ($event->entityId !== null && (mb_strlen($event->entityId) > 200 || preg_match('/[\x00-\x1f\x7f]/', $event->entityId)))) {
            throw new InvalidArgumentException('Unregistered audit event.');
        }
        // Only bounded, reviewed context keys are accepted. No request bodies or exception messages.
        $metadata = [];
        foreach (['attempted_email', 'route', 'reason', 'ability', 'created', 'updated', 'skipped', 'rows', 'success_count', 'failure_count', 'job', 'command', 'self_service', 'deleted_count', 'retention_days', 'key_configured'] as $key) {
            if (array_key_exists($key, $event->metadata)) {
                $value = $event->metadata[$key];
                if (is_string($value)) {
                    $value = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $value) ?? '', 0, 254);
                }
                if (is_scalar($value) || $value === null) {
                    $metadata[$key] = $value;
                }
            }
        }
        if (isset($metadata['attempted_email']) && (! config('audit.actor_email') || ! filter_var($metadata['attempted_email'], FILTER_VALIDATE_EMAIL))) {
            unset($metadata['attempted_email']);
        }
        try {
            $ctx = $this->context->data();
            DB::table('audit_logs')->insert([
                'actor_id' => $ctx['actor_id'] ?? null, 'actor_email' => $ctx['actor_email'] ?? null,
                'actor_roles' => json_encode($ctx['actor_roles'] ?? [], JSON_THROW_ON_ERROR),
                'action' => $event->action, 'entity_type' => $event->entityType, 'entity_id' => $event->entityId,
                'entity_label' => $event->entityLabel === null ? null : mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $event->entityLabel) ?? '', 0, 200),
                'description' => str_replace(['.', '_'], ' ', $event->action), 'status' => $event->status,
                'changes' => '{}', 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'request_id' => $ctx['request_id'] ?? null, 'ip_address' => $ctx['ip_address'] ?? null,
                'user_agent' => $ctx['user_agent'] ?? null, 'source' => $ctx['source'] ?? 'system',
            ]);
            if ($event->status !== 'success') {
                $this->context->markFailure();
            }
        } catch (Throwable $failure) {
            Log::critical('audit_write_failed', ['action' => $event->action, 'request_id' => $this->context->data()['request_id'] ?? null]);
            throw new AuditWriteFailure('Audit persistence unavailable.', previous: $failure);
        }
    }
}
