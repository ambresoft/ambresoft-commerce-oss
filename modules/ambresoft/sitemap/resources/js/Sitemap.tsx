import EditorHeader from '../../../../../resources/js/components/admin/EditorHeader';
import EditorActionButton from '../../../../../resources/js/components/admin/EditorActionButton';
import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '../../../../../resources/js/layouts/admin/AdminLayout';

import FormErrors from '../../../../../resources/js/components/admin/FormErrors';
import Select from '../../../../../resources/js/components/admin/Select';
import Switch from '../../../../../resources/js/components/admin/Switch';
import UnderlineTabs from '../../../../../resources/js/components/admin/UnderlineTabs';
import { useDirtyForm } from '../../../../../resources/js/lib/useUnsavedChanges';
import { useTranslation } from '../../../../../resources/js/lib/useTranslation';
import { useDateDisplay } from '../../../../../resources/js/lib/useDateDisplay';
import type { AdminShell } from '../../../../../resources/js/types/admin';

type Section = {
  enabled: boolean;
  frequency: string;
  status: string;
  error: string | null;
  eligible: number;
  draft: number;
  published: number;
  last_refresh_at: string | null;
  next_refresh_at: string | null;
};
type Entry = {
  id: number;
  source_id: string;
  canonical_url: string;
  url_override: string | null;
  inclusion_override: boolean | null;
  available: boolean;
  lastmod: string | null;
  warning: string | null;
};
export type SitemapProps = AdminShell & {
  version: number;
  pending: boolean;
  sections: Record<string, Section>;
  published: { id: string; at: string; count: number } | null;
  publishedUrl: string;
  tab: string;
  activeSection: string;
  entries: {
    items: Entry[];
    page: number;
    last_page: number;
    total: number;
  } | null;
  canManage: boolean;
  canPublish: boolean;
  errors?: Record<string, string>;
};
const base = '/admin/sitemap';
const frequencies = ['hour', 'day', 'week', 'month', 'year', 'never'];

function useTimestamp() {
  const { date, time } = useDateDisplay();
  return (value: string) => {
    const instant = new Date(value);
    return date.format(instant) + ' ' + time.format(instant);
  };
}

function SectionSettings({
  version,
  sections,
  canManage,
  onDirty,
}: Pick<SitemapProps, 'version' | 'sections' | 'canManage'> & {
  onDirty: (dirty: boolean) => void;
}) {
  const t = useTranslation();
  const date = useTimestamp();
  const form = useForm({
    version,
    sections: Object.fromEntries(
      Object.entries(sections).map(([key, row]) => [
        key,
        { enabled: row.enabled, frequency: row.frequency },
      ]),
    ),
  });
  useDirtyForm(form);
  useEffect(() => {
    onDirty(form.isDirty);
    return () => onDirty(false);
  }, [form.isDirty, onDirty]);
  return (
    <form
      className="space-y-5"
      onSubmit={(event) => {
        event.preventDefault();
        form.put(base + '/settings', {
          preserveState: 'errors',
          preserveScroll: true,
        });
      }}
    >
      <FormErrors errors={form.errors} />
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-slate-600">
            <tr>
              {[
                'section',
                'enabled',
                'eligible',
                'draft_count',
                'published_count',
                'last_refresh',
                'next_refresh',
                'frequency',
              ].map((key) => (
                <th key={key} className="px-4 py-3 font-medium">
                  {t('cms-sitemap::ui.' + key)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {Object.entries(sections).map(([key, row]) => (
              <tr key={key}>
                <th scope="row" className="min-w-44 px-4 py-4 font-medium">
                  <Link
                    href={base + '?tab=urls&section=' + key}
                    className="link"
                  >
                    {t('cms-sitemap::ui.section_' + key)}
                  </Link>
                  <p className="mt-1 text-xs text-slate-500">
                    {t('cms-sitemap::ui.status_' + row.status)}
                  </p>
                  {row.error && (
                    <p
                      role="status"
                      className="mt-2 max-w-xs text-xs text-red-700"
                    >
                      {row.error}
                    </p>
                  )}
                </th>
                <td className="px-4 py-4">
                  <Switch
                    disabled={!canManage || form.processing}
                    label={
                      t('cms-sitemap::ui.enabled') +
                      ' · ' +
                      t('cms-sitemap::ui.section_' + key)
                    }
                    checked={form.data.sections[key].enabled}
                    onChange={(value) =>
                      form.setData('sections', {
                        ...form.data.sections,
                        [key]: { ...form.data.sections[key], enabled: value },
                      })
                    }
                  />
                </td>
                <td className="px-4 py-4 tabular-nums">{row.eligible}</td>
                <td className="px-4 py-4 tabular-nums">{row.draft}</td>
                <td className="px-4 py-4 tabular-nums">{row.published}</td>
                <td className="min-w-40 px-4 py-4 text-slate-500">
                  {row.last_refresh_at ? date(row.last_refresh_at) : '—'}
                </td>
                <td className="min-w-40 px-4 py-4 text-slate-500">
                  {row.next_refresh_at ? date(row.next_refresh_at) : '—'}
                </td>
                <td className="min-w-48 px-4 py-4">
                  <Select
                    id={'frequency-' + key}
                    aria-label={
                      t('cms-sitemap::ui.frequency') +
                      ' · ' +
                      t('cms-sitemap::ui.section_' + key)
                    }
                    disabled={!canManage || form.processing}
                    value={form.data.sections[key].frequency}
                    options={frequencies.map((value) => ({
                      value,
                      label: t('cms-sitemap::ui.frequency_' + value),
                    }))}
                    onChange={(frequency) =>
                      form.setData('sections', {
                        ...form.data.sections,
                        [key]: { ...form.data.sections[key], frequency },
                      })
                    }
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {canManage && (
        <div className="flex justify-end">
          <EditorActionButton disabled={form.processing || !form.isDirty}>
            {t('cms-sitemap::ui.save_settings')}
          </EditorActionButton>
        </div>
      )}
    </form>
  );
}

function UrlTable({
  version,
  entries,
  activeSection,
  sections,
  canManage,
  onDirty,
}: Pick<
  SitemapProps,
  'version' | 'entries' | 'activeSection' | 'sections' | 'canManage'
> & { onDirty: (dirty: boolean) => void }) {
  const t = useTranslation();
  const date = useTimestamp();
  const form = useForm({
    version,
    entries: (entries?.items ?? []).map((row) => ({
      id: row.id,
      include: row.inclusion_override,
      url: row.url_override ?? '',
    })),
  });
  useDirtyForm(form);
  useEffect(() => {
    onDirty(form.isDirty);
    return () => onDirty(false);
  }, [form.isDirty, onDirty]);
  return (
    <div className="space-y-5">
      <div className="max-w-md">
        <Select
          id="sitemap-section"
          aria-label={t('cms-sitemap::ui.section')}
          value={activeSection}
          options={Object.keys(sections).map((value) => ({
            value,
            label: t('cms-sitemap::ui.section_' + value),
          }))}
          onChange={(section) => router.get(base, { tab: 'urls', section })}
        />
      </div>
      <p className="max-w-4xl text-sm leading-6 text-slate-500">
        {t('cms-sitemap::ui.draft_help')}
      </p>
      <form
        className="space-y-4"
        onSubmit={(event) => {
          event.preventDefault();
          form.put(base + '/draft', {
            preserveState: 'errors',
            preserveScroll: true,
          });
        }}
      >
        <FormErrors errors={form.errors} />
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 text-slate-600">
              <tr>
                {['include', 'url', 'source', 'lastmod', 'warnings'].map(
                  (key) => (
                    <th key={key} className="px-4 py-3 font-medium">
                      {t('cms-sitemap::ui.' + key)}
                    </th>
                  ),
                )}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {entries?.items.map((row, index) => (
                <tr key={row.id}>
                  <td className="min-w-40 px-4 py-4 align-top">
                    <Select
                      id={'include-' + row.id}
                      aria-label={
                        t('cms-sitemap::ui.include') + ' · ' + row.canonical_url
                      }
                      disabled={!canManage || form.processing}
                      value={
                        form.data.entries[index]?.include === null
                          ? 'auto'
                          : form.data.entries[index]?.include
                            ? 'yes'
                            : 'no'
                      }
                      options={[
                        {
                          value: 'auto',
                          label: t('cms-sitemap::ui.automatic'),
                        },
                        { value: 'yes', label: t('cms-sitemap::ui.included') },
                        { value: 'no', label: t('cms-sitemap::ui.excluded') },
                      ]}
                      onChange={(value) =>
                        form.setData(
                          'entries',
                          form.data.entries.map((entry, n) =>
                            n === index
                              ? {
                                  ...entry,
                                  include:
                                    value === 'auto' ? null : value === 'yes',
                                }
                              : entry,
                          ),
                        )
                      }
                    />
                  </td>
                  <td className="min-w-96 px-4 py-4 align-top">
                    <p className="mb-2 break-all font-medium">
                      {row.canonical_url}
                    </p>
                    <label className="sr-only" htmlFor={'url-' + row.id}>
                      {t('cms-sitemap::ui.url')} · {row.source_id}
                    </label>
                    <input
                      id={'url-' + row.id}
                      type="url"
                      className="field w-full"
                      placeholder={t('cms-sitemap::ui.reset_url')}
                      disabled={!canManage || form.processing}
                      value={form.data.entries[index]?.url ?? ''}
                      onChange={(event) =>
                        form.setData(
                          'entries',
                          form.data.entries.map((entry, n) =>
                            n === index
                              ? { ...entry, url: event.target.value }
                              : entry,
                          ),
                        )
                      }
                    />
                  </td>
                  <td className="px-4 py-4 align-top text-slate-500">
                    {t('cms-sitemap::ui.section_' + activeSection)} #
                    {row.source_id}
                  </td>
                  <td className="min-w-40 px-4 py-4 align-top text-slate-500">
                    {row.lastmod ? date(row.lastmod) : '—'}
                  </td>
                  <td className="min-w-56 px-4 py-4 align-top text-amber-800">
                    {row.warning
                      ? t('cms-sitemap::ui.warning_' + row.warning)
                      : '—'}
                  </td>
                </tr>
              ))}
              {!entries?.items.length && (
                <tr>
                  <td
                    colSpan={5}
                    className="px-5 py-12 text-center text-slate-500"
                  >
                    {t('cms-sitemap::ui.empty')}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-4">
          <nav
            aria-label={t('cms-sitemap::ui.pagination')}
            className="flex items-center gap-4"
          >
            <Link
              className="link"
              aria-disabled={!entries || entries.page <= 1}
              tabIndex={!entries || entries.page <= 1 ? -1 : undefined}
              onClick={(event) => {
                if (!entries || entries.page <= 1) event.preventDefault();
              }}
              href={
                base +
                '?tab=urls&section=' +
                activeSection +
                '&page=' +
                Math.max(1, (entries?.page ?? 1) - 1)
              }
            >
              {t('common.previous')}
            </Link>
            <span>
              {entries?.page ?? 1} / {entries?.last_page ?? 1}
            </span>
            <Link
              className="link"
              aria-disabled={!entries || entries.page >= entries.last_page}
              tabIndex={
                !entries || entries.page >= entries.last_page ? -1 : undefined
              }
              onClick={(event) => {
                if (!entries || entries.page >= entries.last_page)
                  event.preventDefault();
              }}
              href={
                base +
                '?tab=urls&section=' +
                activeSection +
                '&page=' +
                Math.min(entries?.last_page ?? 1, (entries?.page ?? 1) + 1)
              }
            >
              {t('common.next')}
            </Link>
          </nav>
          {canManage && (
            <EditorActionButton disabled={form.processing || !form.isDirty}>
              {t('cms-sitemap::ui.save_draft')}
            </EditorActionButton>
          )}
        </div>
      </form>
    </div>
  );
}

function PublishDialog({
  version,
  onClose,
}: {
  version: number;
  onClose: () => void;
}) {
  const t = useTranslation();
  const dialog = useRef<HTMLDialogElement>(null);
  const form = useForm({ version, confirmed: true });
  useEffect(() => {
    dialog.current?.showModal();
  }, []);
  return (
    <dialog
      ref={dialog}
      aria-labelledby="sitemap-publish-title"
      onCancel={onClose}
      onClose={onClose}
      className="m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-lg overflow-y-auto rounded-2xl border border-slate-200 bg-white p-7 text-slate-900 shadow-2xl backdrop:bg-black/50"
    >
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          form.post(base + '/publish', {
            preserveState: 'errors',
            onFinish: onClose,
          });
        }}
      >
        <h2 id="sitemap-publish-title" className="text-xl font-semibold">
          {t('cms-sitemap::ui.publish_title')}
        </h2>
        <p className="text-sm leading-6 text-slate-600">
          {t('cms-sitemap::ui.publish_help')}
        </p>
        <FormErrors errors={form.errors} />
        <div className="flex justify-end gap-3">
          <button
            autoFocus
            type="button"
            className="button-secondary"
            disabled={form.processing}
            onClick={onClose}
          >
            {t('common.cancel')}
          </button>
          <button className="button-primary" disabled={form.processing}>
            {t('cms-sitemap::ui.publish')}
          </button>
        </div>
      </form>
    </dialog>
  );
}

export default function SitemapPage(props: SitemapProps) {
  const t = useTranslation();
  const date = useTimestamp();
  const [dirty, setDirty] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const ready = Object.values(props.sections).every(
    (row) => !row.enabled || row.last_refresh_at !== null,
  );
  const busy = Object.values(props.sections).some(
    (row) => row.status === 'queued',
  );
  useEffect(() => {
    if (!busy || dirty) return;
    const timer = window.setInterval(
      () =>
        router.get(
          window.location.href,
          {},
          { preserveState: false, preserveScroll: true, replace: true },
        ),
      4000,
    );
    return () => window.clearInterval(timer);
  }, [busy, dirty]);
  return (
    <AdminLayout
      title={t('cms-sitemap::ui.title')}
      navigation={props.navigation}
      breadcrumbs={props.breadcrumbs}
    >
      <div className="space-y-6">
        <FormErrors errors={props.errors ?? {}} />
        <EditorHeader
          heading={t('cms-sitemap::ui.title')}
          iconKey="core.website"
          status={
            <>
              <span
                role="status"
                className={
                  'rounded-full px-3 py-1 text-sm ' +
                  (props.pending
                    ? 'bg-amber-50 text-amber-800'
                    : 'bg-emerald-50 text-emerald-800')
                }
              >
                {t(
                  'cms-sitemap::ui.' +
                    (props.pending
                      ? 'pending'
                      : props.published
                        ? 'clean'
                        : 'not_generated'),
                )}
              </span>
            </>
          }
          description={
            <>
              <p className="mt-2 text-sm text-slate-500">
                {t('cms-sitemap::ui.intro')}
              </p>
            </>
          }
          actions={
            <>
              {props.canManage && (
                <button
                  type="button"
                  className="button-secondary"
                  disabled={dirty || busy}
                  onClick={() =>
                    router.post(
                      base + '/generate',
                      { version: props.version },
                      { preserveState: 'errors' },
                    )
                  }
                >
                  {t('cms-sitemap::ui.generate')}
                </button>
              )}
              {props.canPublish && (
                <button
                  type="button"
                  className="button-primary"
                  disabled={dirty || busy || !ready}
                  title={
                    !ready ? t('cms-sitemap::ui.generate_first') : undefined
                  }
                  onClick={() => setConfirm(true)}
                >
                  {t('cms-sitemap::ui.publish')}
                </button>
              )}
            </>
          }
        />

        <section className="rounded-xl border border-slate-200 bg-white p-5">
          <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
            {t('cms-sitemap::ui.live')}
          </p>
          <a
            href={props.publishedUrl}
            target="_blank"
            rel="noreferrer"
            className="mt-2 inline-block break-all font-medium text-primary underline"
          >
            {props.publishedUrl}
          </a>
          <p className="mt-2 text-sm text-slate-500">
            {props.published
              ? t('cms-sitemap::ui.published_at') +
                ': ' +
                date(props.published.at) +
                ' · ' +
                props.published.count
              : t('cms-sitemap::ui.none_published')}
          </p>
        </section>
        <UnderlineTabs
          label={t('cms-sitemap::ui.title')}
          active={props.tab}
          tabs={['overview', 'urls'].map((tab) => ({
            key: tab,
            label: t('cms-sitemap::ui.' + tab),
            href: base + '?tab=' + tab,
          }))}
        />
        <p className="text-sm text-slate-500">
          {t('cms-sitemap::ui.counts_help')}
        </p>
        {props.tab === 'urls' ? (
          <UrlTable
            key={'urls-' + props.activeSection}
            {...props}
            onDirty={setDirty}
          />
        ) : (
          <SectionSettings {...props} onDirty={setDirty} />
        )}
      </div>
      {confirm && (
        <PublishDialog
          version={props.version}
          onClose={() => setConfirm(false)}
        />
      )}
    </AdminLayout>
  );
}
