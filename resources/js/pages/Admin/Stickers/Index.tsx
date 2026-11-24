import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FormErrors from '../../../components/admin/FormErrors';
import FormField from '../../../components/auth/FormField';
import FilterBar, {
  SearchField,
  FilterChip,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import Switch from '../../../components/admin/Switch';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import IconButton from '../../../components/admin/list/IconButton';
import { useTranslation } from '../../../lib/useTranslation';
import { useListQuery } from '../../../lib/useListQuery';
import { useUrlTab } from '../../../lib/useUrlTab';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { StickerDetail, StickerLimits } from '../../../types/stickers';

export default function StickerIndex({
  stickers,
  filters,
  limits,
  ...shell
}: AdminShell & {
  stickers: Page<StickerDetail>;
  filters: Record<string, string>;
  limits: StickerLimits;
}) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const manage = !!capabilities['stickers.manage'];
  const tabs = manage
    ? (['stickers', 'settings'] as const)
    : (['stickers'] as const);
  const [tab, setTab] = useUrlTab(tabs);
  const apply = useListQuery('/admin/stickers', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [pending, setPending] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<StickerDetail | null>(null);
  const form = useForm({
    version: limits.version,
    card_limit: String(limits.card_limit),
    detail_limit: String(limits.detail_limit),
  });
  return (
    <AdminLayout title={t('stickers.title')} {...shell}>
      <EditorHeader
        heading={t('stickers.title')}
        iconKey="stickers"
        description={
          <>
            <p className="mt-2 text-sm text-slate-500">{t('stickers.intro')}</p>
          </>
        }
        actions={
          <>
            {manage && (
              <Link className="button-primary" href="/admin/stickers/create">
                <Icon name="plus" className="size-5 shrink-0" />
                {t('stickers.create')}
              </Link>
            )}
          </>
        }
      />
      <FormErrors errors={errors ?? {}} />

      <AdminTabs label={t('stickers.title')}>
        {tabs.map((item) => (
          <AdminTab
            key={item}
            type="button"
            id={`stickers-tab-${item}`}
            role="tab"
            aria-selected={tab === item}
            aria-controls={`stickers-panel-${item}`}
            onClick={() => setTab(item)}
            iconKey={item}
          >
            {t(item === 'stickers' ? 'stickers.title' : 'stickers.settings')}
          </AdminTab>
        ))}
      </AdminTabs>

      {tab === 'stickers' && (
        <div
          className="list-card"
          role="tabpanel"
          id="stickers-panel-stickers"
          aria-labelledby="stickers-tab-stickers"
        >
          <FilterBar
            resetHref="/admin/stickers"
            active={
              !!filters.search ||
              !!filters.status ||
              !!filters.type ||
              !!filters.style
            }
            search={
              <SearchField
                id="sticker-search"
                value={search}
                onChange={setSearch}
                onSubmit={() => apply({ search })}
                placeholder={t('stickers.search')}
              />
            }
            chips={
              <>
                {(['status', 'type', 'style'] as const).map((field) => (
                  <FilterChip
                    key={field}
                    label={t(`stickers.${field}`)}
                    value={filters[field] ?? ''}
                    options={(field === 'status'
                      ? ['active', 'disabled']
                      : field === 'type'
                        ? ['manual', 'dynamic']
                        : ['text', 'image']
                    ).map((value) => ({
                      value,
                      label: t(`stickers.${value}`),
                    }))}
                    onChange={(value) => apply({ [field]: value })}
                  />
                ))}
              </>
            }
            sort={
              <SortControl
                value={filters.sort ?? 'priority'}
                direction={filters.direction === 'desc' ? 'desc' : 'asc'}
                options={[
                  'name',
                  'priority',
                  'updated_at',
                  'starts_at',
                  'ends_at',
                ].map((value) => ({ value, label: t(`stickers.${value}`) }))}
                onChange={(sort, direction) => apply({ sort, direction })}
              />
            }
          />
          <div className="overflow-x-auto">
            <table className="data-table">
              <thead>
                <tr>
                  {[
                    'name',
                    'type',
                    'style',
                    'status',
                    'assignment',
                    'starts_at',
                    'ends_at',
                    'priority',
                    'updated_at',
                    'actions',
                  ].map((field) => (
                    <th key={field}>{t(`stickers.${field}`)}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {stickers.data.length === 0 && (
                  <tr>
                    <td
                      colSpan={10}
                      className="py-12 text-center text-slate-500"
                    >
                      {t('stickers.empty')}
                    </td>
                  </tr>
                )}
                {stickers.data.map((s) => (
                  <tr key={s.id}>
                    <td>
                      <Link
                        className="text-link font-medium"
                        href={`/admin/stickers/${s.id}/edit`}
                      >
                        {s.name}
                      </Link>
                    </td>
                    <td>{t(`stickers.${s.type}`)}</td>
                    <td>{t(`stickers.${s.style}`)}</td>
                    <td>
                      <StatusBadge
                        tone={s.state === 'active' ? 'success' : 'neutral'}
                      >
                        {t(`stickers.${s.state}`)}
                      </StatusBadge>
                    </td>
                    <td>
                      {s.type === 'manual' ? (
                        t('stickers.assignment_count', {
                          count: s.assigned_count,
                        })
                      ) : (
                        <span className="text-xs text-slate-500">
                          {s.conditions
                            .map((c) =>
                              [
                                t(`stickers.condition_${c.type}`),
                                c.minimum
                                  ? `≥ ${c.minimum.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')}`
                                  : '',
                                c.maximum
                                  ? `≤ ${c.maximum.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')}`
                                  : '',
                                c.reference ?? '',
                                c.type === 'feature' && c.boolean_value !== null
                                  ? t(
                                      c.boolean_value
                                        ? 'stickers.yes'
                                        : 'stickers.no',
                                    )
                                  : '',
                              ]
                                .filter(Boolean)
                                .join(' '),
                            )
                            .join(` · ${t(`stickers.${s.match}`)} · `)}
                        </span>
                      )}
                    </td>
                    <td className="whitespace-nowrap">
                      {s.starts_at?.replace('T', ' ') || '—'}
                    </td>
                    <td className="whitespace-nowrap">
                      {s.ends_at?.replace('T', ' ') || '—'}
                    </td>
                    <td>{s.priority}</td>
                    <td className="whitespace-nowrap">{s.updated_at}</td>
                    <td>
                      <div className="flex items-center justify-end gap-3">
                        {manage && (
                          <IconButton
                            icon="trash"
                            danger
                            label={`${t('stickers.delete')}: ${s.name}`}
                            onClick={() => setDeleting(s)}
                          />
                        )}
                        <IconButton
                          icon="edit"
                          label={`${t(manage ? 'stickers.edit' : 'stickers.view')}: ${s.name}`}
                          href={`/admin/stickers/${s.id}/edit`}
                        />
                        {manage && (
                          <Switch
                            checked={s.status === 'active'}
                            label={`${t('stickers.status')}: ${s.name}`}
                            disabled={pending === s.id}
                            onChange={(enabled) => {
                              setPending(s.id);
                              router.patch(
                                `/admin/stickers/${s.id}/status`,
                                {
                                  version: s.version,
                                  status: enabled ? 'active' : 'disabled',
                                },
                                {
                                  preserveScroll: true,
                                  onFinish: () => setPending(null),
                                },
                              );
                            }}
                          />
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination
            page={stickers}
            perPage={{
              value: filters.per_page ?? '25',
              options: ['25', '50', '100'],
              onChange: (per_page) => apply({ per_page }),
            }}
          />
        </div>
      )}
      {manage && tab === 'settings' && (
        <form
          role="tabpanel"
          id="stickers-panel-settings"
          aria-labelledby="stickers-tab-settings"
          className="rounded-xl border border-slate-200 bg-white p-6"
          onSubmit={(e) => {
            e.preventDefault();
            form.patch('/admin/stickers/limits', {
              preserveScroll: true,
              onError: () => setTab('settings'),
              onSuccess: (page) => {
                const saved = (
                  page.props as unknown as { limits: StickerLimits }
                ).limits;
                form.setData('version', saved.version);
                form.setDefaults('version', saved.version);
              },
            });
          }}
        >
          <h2 className="mb-5 font-semibold">{t('stickers.limits')}</h2>
          <p className="mb-5 text-sm text-slate-500">
            {t('stickers.limits_help')}
          </p>
          <div className="flex flex-wrap items-end gap-5">
            {(['card_limit', 'detail_limit'] as const).map((field) => (
              <FormField
                key={field}
                id={field}
                label={t(`stickers.${field}`)}
                type="number"
                min="0"
                max="20"
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
                error={form.errors[field]}
              />
            ))}
            <button className="button-secondary" disabled={form.processing}>
              {t('stickers.save')}
            </button>
          </div>
        </form>
      )}
      {deleting && (
        <ConfirmDeleteDialog
          title={t('stickers.delete')}
          confirmLabel={t('stickers.delete')}
          facts={[t('stickers.delete_impact')]}
          onCancel={() => setDeleting(null)}
          onConfirm={() =>
            router.delete(`/admin/stickers/${deleting.id}`, {
              data: { version: deleting.version },
              onFinish: () => setDeleting(null),
            })
          }
        />
      )}
    </AdminLayout>
  );
}
