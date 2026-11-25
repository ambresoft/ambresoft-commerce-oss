import EditorHeader from '../../../components/admin/EditorHeader';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FilterBar, {
  FilterChip,
  SearchField,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import { useListQuery } from '../../../lib/useListQuery';
import {
  actionLabel,
  actorLabel,
  entityLabel,
  sourceLabel,
} from '../../../lib/auditPresentation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { AuditRow } from '../../../types/audit';
import type { SharedProps } from '../../../types/page';

export default function Index({
  logs,
  filters,
  actions = [],
  entities = [],
  ...shell
}: AdminShell & {
  logs: Page<AuditRow>;
  filters: Record<string, string>;
  actions?: string[];
  entities?: string[];
}) {
  const t = useTranslation();
  const { errors } = usePage<SharedProps>().props;
  const apply = useListQuery('/admin/audit-logs', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [advanced, setAdvanced] = useState(filters);
  const active = Object.entries(filters).some(
    ([key, value]) => !['page', 'sort'].includes(key) && !!value,
  );
  const options = (values: string[], label: (value: string) => string) => [
    { value: '', label: t('common.all') },
    ...values.map((value) => ({ value, label: label(value) })),
  ];
  const { date: date, time } = useDateDisplay();
  return (
    <AdminLayout title={t('audit.title')} {...shell}>
      <EditorHeader heading={t('audit.title')} iconKey="core.logs" />
      <section className="list-card" aria-label={t('audit.title')}>
        <FilterBar
          resetHref="/admin/audit-logs"
          active={active}
          search={
            <SearchField
              id="audit-search"
              value={search}
              placeholder={t('audit.search')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          sort={
            <SortControl
              value="occurred_at"
              direction={filters.sort === 'asc' ? 'asc' : 'desc'}
              options={[{ value: 'occurred_at', label: t('audit.time') }]}
              onChange={(_, direction) => apply({ sort: direction })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('audit.entity')}
                value={filters.entity ?? ''}
                options={options(entities, (value) => entityLabel(value, t))}
                onChange={(value) => apply({ entity: value })}
              />
              <FilterChip
                label={t('audit.action')}
                value={filters.action ?? ''}
                options={options(actions, (value) =>
                  actionLabel(
                    { action: value, entity_type: value.split('.')[0] },
                    t,
                  ),
                )}
                onChange={(value) => apply({ action: value })}
              />
              <FilterChip
                label={t('audit.outcome')}
                value={filters.status ?? ''}
                options={options(['success', 'failure', 'denied'], (value) =>
                  t('audit.' + value),
                )}
                onChange={(value) => apply({ status: value })}
              />
              <FilterChip
                label={t('audit.source')}
                value={filters.source ?? ''}
                options={options(
                  ['admin_ui', 'web', 'background_job', 'system'],
                  (value) => sourceLabel(value, t),
                )}
                onChange={(value) => apply({ source: value })}
              />
            </>
          }
        />
        <details
          className="border-t border-slate-200 px-5 py-3"
          open={Object.keys(errors).length > 0 || undefined}
        >
          <summary className="cursor-pointer text-sm text-slate-500">
            {t('audit.advanced')}
          </summary>
          <form
            className="mt-4 grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-5"
            onSubmit={(event) => {
              event.preventDefault();
              apply(
                Object.fromEntries(
                  ['actor', 'request_id', 'from', 'to'].map((key) => [
                    key,
                    advanced[key] ?? '',
                  ]),
                ),
              );
            }}
          >
            {['actor', 'request_id', 'from', 'to'].map((key) => (
              <label key={key} className="text-sm font-medium">
                {t('audit.' + key)}
                <input
                  className="field mt-2"
                  type={['from', 'to'].includes(key) ? 'date' : 'text'}
                  value={advanced[key] ?? ''}
                  maxLength={key === 'actor' ? 40 : 36}
                  onChange={(event) =>
                    setAdvanced((old) => ({
                      ...old,
                      [key]: event.target.value,
                    }))
                  }
                  aria-invalid={!!errors[key]}
                />
                {errors[key] && (
                  <span className="text-danger">{errors[key]}</span>
                )}
              </label>
            ))}
            <button className="button-secondary">{t('audit.apply')}</button>
          </form>
        </details>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                {[
                  'time',
                  'actor',
                  'summary',
                  'entity',
                  'ip_address',
                  'outcome',
                  'details',
                ].map((key) => (
                  <th key={key}>{t('audit.' + key)}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {logs.data.map((log) => {
                const occurred = new Date(log.occurred_at);
                return (
                  <tr key={log.id}>
                    <td className="whitespace-nowrap">
                      <time dateTime={log.occurred_at}>
                        {date.format(occurred)}
                        <p className="mt-1 text-xs text-slate-500">
                          {time.format(occurred)}
                        </p>
                      </time>
                    </td>
                    <td>
                      <p className="font-medium">{actorLabel(log, t)}</p>
                      <p className="mt-1 text-xs text-slate-500">
                        {sourceLabel(log.source, t)}
                      </p>
                    </td>
                    <td className="min-w-56 font-medium">
                      {actionLabel(log, t)}
                      {log.operation && (
                        <p className="mt-1 text-xs font-normal text-slate-500">
                          {t('audit.commands.' + log.operation) ||
                            log.operation}
                        </p>
                      )}
                    </td>
                    <td>
                      <p>
                        {log.entity_label || entityLabel(log.entity_type, t)}
                      </p>
                      {log.entity_id && (
                        <p className="mt-1 text-xs text-slate-500">
                          #{log.entity_id}
                        </p>
                      )}
                    </td>
                    <td className="whitespace-nowrap text-sm">
                      {log.ip_address || '—'}
                    </td>
                    <td>
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
                    </td>
                    <td>
                      <Link
                        className="text-link"
                        href={'/admin/audit-logs/' + log.id}
                      >
                        {t('audit.details')}
                      </Link>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          {logs.data.length === 0 && (
            <p className="p-12 text-center text-sm text-slate-500">
              {t('audit.empty')}
            </p>
          )}
        </div>
        <Pagination page={logs} />
      </section>
    </AdminLayout>
  );
}
