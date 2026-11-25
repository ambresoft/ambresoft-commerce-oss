import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import { useDateDisplay } from '../../../lib/useDateDisplay';

import AdminLayout from '../../../layouts/admin/AdminLayout';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import {
  actionLabel,
  actorLabel,
  entityLabel,
  fieldLabel,
  sourceLabel,
  valueLabel,
} from '../../../lib/auditPresentation';
import type { AdminShell } from '../../../types/admin';
import type { AuditDetails } from '../../../types/audit';

export default function Show({
  log,
  ...shell
}: AdminShell & { log: AuditDetails }) {
  const t = useTranslation();
  const { date, time } = useDateDisplay();
  const changes = Object.entries(log.changes);
  return (
    <AdminLayout title={t('audit.details')} {...shell}>
      <EditorHeader
        heading={actionLabel(log, t)}
        iconKey="core.logs"
        backHref="/admin/audit-logs"
        backLabel={t('audit.back')}
        description={
          <>
            <p className="mt-2 text-sm text-slate-500">
              {(typeof log.metadata.command === 'string'
                ? t('audit.commands.' + log.metadata.command) ||
                  log.metadata.command
                : log.entity_label) || entityLabel(log.entity_type, t)}
              {log.entity_id && ' · #' + log.entity_id}
            </p>
          </>
        }
        actions={
          <>
            <StatusBadge
              tone={
                log.status === 'success'
                  ? 'success'
                  : log.status === 'denied'
                    ? 'warning'
                    : 'danger'
              }
            >
              {t('audit.' + log.status)}
            </StatusBadge>
          </>
        }
      />
      <section className="panel p-5">
        <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {[
            [t('audit.actor'), actorLabel(log, t)],
            [
              t('audit.time'),
              date.format(new Date(log.occurred_at)) +
                ' ' +
                time.format(new Date(log.occurred_at)),
            ],
            [t('audit.ip_address'), log.ip_address || t('audit.not_available')],
            [t('audit.source'), sourceLabel(log.source, t)],
          ].map(([label, value]) => (
            <div key={label}>
              <dt className="text-sm text-slate-500">{label}</dt>
              <dd className="mt-1 break-words font-medium">{value}</dd>
            </div>
          ))}
        </dl>
      </section>
      <section className="mt-5">
        <AdminCard
          title={t('audit.changes')}
          iconKey="changes"
          bodyClassName="p-0 "
        >
          {changes.length ? (
            <div className="overflow-x-auto">
              <table className="data-table">
                <thead>
                  <tr>
                    {['field', 'before', 'after'].map((key) => (
                      <th key={key}>{t('audit.' + key)}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {changes.map(([field, change]) => (
                    <tr key={field}>
                      <td className="font-medium">{fieldLabel(field, t)}</td>
                      <td className="max-w-lg break-words whitespace-pre-wrap">
                        {valueLabel(change.before, t)}
                      </td>
                      <td className="max-w-lg break-words whitespace-pre-wrap">
                        {valueLabel(change.after, t)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="px-5 pb-5 text-sm text-slate-500">
              {t('audit.no_changes')}
            </p>
          )}
        </AdminCard>
      </section>
      <details className="panel mt-5 p-5">
        <summary className="cursor-pointer font-medium">
          {t('audit.technical')}
        </summary>
        <pre className="mt-4 overflow-auto text-xs whitespace-pre-wrap wrap-break-word">
          {JSON.stringify(
            {
              event_id: log.id,
              action: log.action,
              entity_type: log.entity_type,
              entity_id: log.entity_id,
              occurred_at_utc: log.occurred_at,
              actor_id: log.actor_id,
              actor_roles: log.actor_roles,
              request_id: log.request_id,
              user_agent: log.user_agent,
              ...log.metadata,
            },
            null,
            2,
          )}
        </pre>
      </details>
    </AdminLayout>
  );
}
