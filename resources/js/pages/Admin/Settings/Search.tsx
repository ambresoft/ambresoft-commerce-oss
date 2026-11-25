import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import SettingsFields, {
  fieldValues,
} from '../../../components/admin/SettingsFields';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { SettingFieldView } from '../../../types/shipping';

type Engine = {
  id: string;
  name: string;
  version: number;
  fields: SettingFieldView[];
  built: boolean;
  health: {
    reachable: boolean;
    ready: boolean;
    current: boolean;
    documents: number | null;
    index: string | null;
    version: string | null;
    message: string | null;
  };
};

type Run = {
  id: string;
  status: 'running' | 'completed' | 'failed' | 'cancelled';
  target: string;
  expected: number;
  indexed: number;
  failed: number;
  errors: { product: string; message: string }[];
  message: string | null;
  started_at: string | null;
  finished_at: string | null;
};

type Props = AdminShell & {
  recovery?: SharedProps['searchHealth'];
  engine: Engine | null;
  catalog: { eligible: number };
  outbox: {
    pending: number;
    critical: number;
    failed: number;
    oldest: string | null;
    errors: {
      product: string;
      attempts: number;
      message: string;
      failed: boolean;
    }[];
  };
  state: {
    built_at: string | null;
    last_sync_at: string | null;
    last_reconcile_at: string | null;
    last_reconcile: {
      missing: number;
      extra: number;
      retried: number;
    } | null;
    last_error_at: string | null;
    last_error: string | null;
  };
  runs: Run[];
};

function Card({
  title,
  children,
  iconKey,
}: {
  title: string;
  children: ReactNode;
  iconKey: string;
}) {
  return (
    <section>
      <AdminCard title={title} iconKey={iconKey} bodyClassName="p-5 space-y-4">
        {children}
      </AdminCard>
    </section>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">
        {label}
      </dt>
      <dd className="mt-1 text-sm">{children}</dd>
    </div>
  );
}

/**
 * Settings → Search: the search add-on's health and settings, catalog changes waiting for the index, full rebuilds
 * and the check against the catalog. Everything shown is recorded state; while a rebuild runs the page refreshes it.
 */
export default function SearchSettings({
  engine,
  recovery,
  catalog,
  outbox,
  state,
  runs,
  ...shell
}: Props) {
  const t = useTranslation();
  const { date, time } = useDateDisplay();
  const { errors: pageErrors } = usePage<{ errors: Record<string, string> }>()
    .props;
  const when = (value: string | null) =>
    value
      ? `${date.format(new Date(value))} ${time.format(new Date(value))}`
      : t('search.never');
  const running = runs.find((run) => run.status === 'running') ?? null;
  const form = useForm({
    version: engine?.version ?? 1,
    settings: fieldValues(engine?.fields ?? []),
    clear: {} as Record<string, boolean>,
  });
  const repair = useForm({});
  const errors = form.errors as Record<string, string>;
  // A rebuild in progress is followed from its recorded state.
  useEffect(() => {
    if (!running && !recovery?.pending) return;
    const timer = window.setInterval(
      () =>
        router.reload({
          only: ['runs', 'outbox', 'state', 'engine', 'recovery'],
        }),
      5000,
    );
    return () => window.clearInterval(timer);
  }, [running, recovery?.pending]);
  const post = (path: string) =>
    router.post(`/admin/settings/search/${path}`, {}, { preserveScroll: true });
  const health = engine?.health;

  return (
    <AdminLayout title={t('search.title')} {...shell}>
      <EditorHeader heading={t('search.title')} iconKey="search" />
      <FormErrors errors={pageErrors} />
      {!engine || !health ? (
        <p className="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          {t('search.no_provider')}
        </p>
      ) : (
        <div className="space-y-6">
          <Card title={t('search.recovery_heading')} iconKey="history">
            <p
              role="status"
              className={
                'text-base font-semibold ' +
                (recovery?.status === 'attention' ||
                !health.reachable ||
                !engine.built ||
                !health.current
                  ? 'text-amber-700'
                  : 'text-emerald-700')
              }
            >
              {t(
                !health.reachable
                  ? 'search.unreachable'
                  : !engine.built || !health.current
                    ? 'search.health_setup'
                    : recovery?.status === 'attention'
                      ? 'search.health_attention'
                      : recovery?.status === 'pending'
                        ? 'search.health_pending'
                        : 'search.health_healthy',
              )}
            </p>
            <p className="max-w-3xl text-sm leading-6 text-slate-600">
              {t('search.recovery_intro')}
            </p>
            <dl className="grid gap-4 sm:grid-cols-3">
              <Fact label={t('search.health_hidden')}>
                {recovery?.hidden ?? outbox.critical}
              </Fact>
              <Fact label={t('search.pending')}>
                {recovery?.pending ?? outbox.pending}
              </Fact>
              <Fact label={t('search.health_errors')}>
                {recovery?.errors ?? outbox.failed}
              </Fact>
              <Fact label={t('search.last_sync')}>
                {when(state.last_sync_at)}
              </Fact>
            </dl>
            <p className="max-w-3xl text-sm leading-6 text-slate-500">
              {t('search.repair_help')}
            </p>
            <div className="flex flex-wrap gap-3">
              <button
                type="button"
                className="button-primary"
                disabled={repair.processing || !health.reachable}
                onClick={() =>
                  repair.post('/admin/settings/search/repair', {
                    preserveScroll: true,
                  })
                }
              >
                {t(
                  repair.processing
                    ? 'search.repair_working'
                    : 'search.repair_action',
                )}
              </button>
              <button
                type="button"
                className="button-secondary"
                disabled={repair.processing}
                onClick={() => router.reload()}
              >
                {t('search.health_refresh')}
              </button>
            </div>
            {!health.reachable && (
              <p role="alert" className="text-sm text-red-700">
                {t('search.repair_unreachable')}
              </p>
            )}
            <p className="max-w-3xl text-xs leading-5 text-slate-500">
              {t('search.repair_escalate')}
            </p>
          </Card>
          <details className="space-y-4">
            <summary className="cursor-pointer text-sm font-medium text-slate-600">
              {t('search.advanced_details')}
            </summary>
            <div className="space-y-6">
              <Card title={t('search.status_heading')} iconKey="check">
                <dl className="grid gap-4 sm:grid-cols-3">
                  <Fact label={t('search.provider')}>
                    {engine.name}
                    {health.version && (
                      <span className="text-slate-500"> {health.version}</span>
                    )}
                  </Fact>
                  <Fact label={t('search.status_heading')}>
                    <span
                      className={
                        health.reachable ? 'text-emerald-700' : 'text-red-700'
                      }
                    >
                      {t(
                        health.reachable
                          ? 'search.reachable'
                          : 'search.unreachable',
                      )}
                    </span>
                  </Fact>
                  <Fact label={t('search.live_index')}>
                    {health.index ?? '—'}
                  </Fact>
                  <Fact label={t('search.documents')}>
                    {health.documents ?? '—'}
                  </Fact>
                  <Fact label={t('search.eligible')}>{catalog.eligible}</Fact>
                  <Fact label={t('search.built_at')}>
                    {when(state.built_at)}
                  </Fact>
                </dl>
                {health.message && (
                  <p role="alert" className="text-sm text-red-700">
                    {health.message}
                  </p>
                )}
                {!engine.built && (
                  <p className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    {t('search.not_built')}
                  </p>
                )}
                {health.ready && !health.current && (
                  <p className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    {t('search.schema_outdated')}
                  </p>
                )}
                <p className="text-xs text-slate-500">
                  {t('search.connection_hint')}
                </p>
              </Card>

              <Card title={t('search.runs_heading')} iconKey="history">
                <p className="text-sm text-slate-600">
                  {t('search.rebuild_hint')}
                </p>
                <div className="flex flex-wrap gap-3">
                  <button
                    type="button"
                    className="button-primary"
                    disabled={running !== null}
                    onClick={() => post('rebuild')}
                  >
                    {t(engine.built ? 'search.rebuild' : 'search.build')}
                  </button>
                  <button
                    type="button"
                    className="button-secondary"
                    onClick={() => post('reconcile')}
                  >
                    {t('search.reconcile')}
                  </button>
                </div>
                <p className="text-xs text-slate-500">
                  {t('search.reconcile_hint')} {t('search.last_reconcile')}:{' '}
                  {when(state.last_reconcile_at)}
                  {state.last_reconcile &&
                    ` (${t('search.reconcile_summary', state.last_reconcile)})`}
                </p>
                {runs.length > 0 && (
                  <ul className="divide-y divide-slate-100 text-sm">
                    {runs.map((run) => (
                      <li key={run.id} className="space-y-1 py-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <span>
                            <strong>
                              {t(`search.run_${run.status}`)} #{run.id}
                            </strong>{' '}
                            <span className="text-slate-500">
                              {when(run.started_at)}
                            </span>
                          </span>
                          <span>
                            {t('search.run_progress', {
                              indexed: run.indexed,
                              expected: run.expected,
                            })}
                            {run.status === 'running' && (
                              <button
                                type="button"
                                className="button-secondary ml-3"
                                onClick={() => post(`runs/${run.id}/cancel`)}
                              >
                                {t('search.cancel')}
                              </button>
                            )}
                          </span>
                        </div>
                        {run.status === 'running' && (
                          <progress
                            className="w-full"
                            max={Math.max(1, run.expected)}
                            value={run.indexed}
                            aria-label={t('search.run_progress', {
                              indexed: run.indexed,
                              expected: run.expected,
                            })}
                          />
                        )}
                        {run.message && (
                          <p className="text-slate-600">{run.message}</p>
                        )}
                        {run.errors.map((error) => (
                          <p
                            key={error.product}
                            className="text-xs text-red-700"
                          >
                            {t('search.product', { id: error.product })}:{' '}
                            {error.message}
                          </p>
                        ))}
                      </li>
                    ))}
                  </ul>
                )}
              </Card>

              <Card title={t('search.sync_heading')} iconKey="history">
                <dl className="grid gap-4 sm:grid-cols-3">
                  <Fact label={t('search.pending')}>{outbox.pending}</Fact>
                  <Fact label={t('search.critical')}>{outbox.critical}</Fact>
                  <Fact label={t('search.failed')}>{outbox.failed}</Fact>
                  <Fact label={t('search.oldest')}>{when(outbox.oldest)}</Fact>
                  <Fact label={t('search.last_sync')}>
                    {when(state.last_sync_at)}
                  </Fact>
                  <Fact label={t('search.last_error')}>
                    {state.last_error ? (
                      <>
                        {when(state.last_error_at)}
                        <span className="block text-red-700">
                          {state.last_error}
                        </span>
                      </>
                    ) : (
                      '—'
                    )}
                  </Fact>
                </dl>
                <p className="text-xs text-slate-500">
                  {t('search.critical_hint')}
                </p>
                {outbox.errors.length > 0 && (
                  <div>
                    <h3 className="text-sm font-semibold">
                      {t('search.errors_heading')}
                    </h3>
                    <ul className="mt-2 space-y-1 text-sm">
                      {outbox.errors.map((error) => (
                        <li key={error.product}>
                          <Link
                            className="text-link"
                            href={'/admin/products/' + error.product + '/edit'}
                          >
                            {t('search.product', { id: error.product })}
                          </Link>{' '}
                          · {t('search.attempts', { count: error.attempts })}
                          {error.failed && ` · ${t('search.given_up')}`}:{' '}
                          <span className="text-red-700">{error.message}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
                <div className="flex flex-wrap gap-3">
                  <button
                    type="button"
                    className="button-secondary"
                    onClick={() => post('sync')}
                  >
                    {t('search.sync_now')}
                  </button>
                  {outbox.failed > 0 && (
                    <button
                      type="button"
                      className="button-secondary"
                      onClick={() => post('retry')}
                    >
                      {t('search.retry')}
                    </button>
                  )}
                </div>
              </Card>
            </div>
          </details>
          {engine.fields.length > 0 && (
            <form
              onSubmit={(event) => {
                event.preventDefault();
                form.put('/admin/settings/search', { preserveScroll: true });
              }}
            >
              <Card title={t('search.settings_heading')} iconKey="settings">
                <FormErrors errors={errors} />
                <div className="grid gap-5">
                  <SettingsFields
                    fields={engine.fields}
                    values={form.data.settings}
                    clear={form.data.clear}
                    errors={errors}
                    prefix="settings."
                    onChange={(key, value) =>
                      form.setData('settings', {
                        ...form.data.settings,
                        [key]: value,
                      })
                    }
                    onClear={(key, value) =>
                      form.setData('clear', {
                        ...form.data.clear,
                        [key]: value,
                      })
                    }
                  />
                </div>
                <div className="flex justify-end">
                  <button className="button-primary" disabled={form.processing}>
                    {t('search.save')}
                  </button>
                </div>
              </Card>
            </form>
          )}
        </div>
      )}
    </AdminLayout>
  );
}
