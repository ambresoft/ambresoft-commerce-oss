import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { Link, router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import Select from '../../../components/admin/Select';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import BulkBar from '../../../components/admin/list/BulkBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { bannerLabels } from '../../../lib/bannerLabels';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { BannerGroup, BannerRow } from '../../../types/banners';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & {
  banners: Page<BannerRow>;
  groups: BannerGroup[];
  filters: Record<string, string>;
};

const tone = {
  live: 'success',
  scheduled: 'warning',
  expired: 'neutral',
  disabled: 'neutral',
} as const;

export default function BannersIndex({
  banners,
  groups,
  filters,
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const manage = !!capabilities['banners.manage'];
  const apply = useListQuery('/admin/banners', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [picked, setPicked] = useState<string[]>([]);
  const [target, setTarget] = useState('');
  const [drawer, setDrawer] = useState(false);
  const [shown, setShown] = useState(false);
  const [newGroup, setNewGroup] = useState('');
  const [renaming, setRenaming] = useState<{ id: string; name: string } | null>(
    null,
  );
  const ids = banners.data.map((b) => b.id);
  // Only rows on this page count, so paging or filtering drops stale picks.
  const selected = picked.filter((id) => ids.includes(id));
  const allSelected =
    ids.length > 0 && ids.every((id) => selected.includes(id));
  const filtered = ['search', 'group', 'state'].some((key) => filters[key]);
  const { date: date } = useDateDisplay();

  const closeDrawer = () => {
    setShown(false);
    const instant = window.matchMedia?.(
      '(prefers-reduced-motion: reduce)',
    )?.matches;
    setTimeout(() => setDrawer(false), instant ? 0 : 200);
  };
  useEffect(() => {
    if (!drawer) return;
    const frame = requestAnimationFrame(() => setShown(true));
    const escape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') closeDrawer();
    };
    document.addEventListener('keydown', escape);
    return () => {
      cancelAnimationFrame(frame);
      document.removeEventListener('keydown', escape);
    };
  }, [drawer]);

  const bulk = (action: string, group?: string) => {
    if (
      action === 'delete' &&
      !window.confirm(
        t('banners.bulk_delete_confirm', { count: selected.length }),
      )
    )
      return;
    router.post(
      '/admin/banners/bulk',
      { ids: selected, action, ...(group ? { group } : {}) },
      { preserveScroll: true, onSuccess: () => setPicked([]) },
    );
  };
  const remove = (banner: BannerRow) => {
    if (window.confirm(t('banners.delete_confirm', { name: banner.name })))
      router.delete('/admin/banners/' + banner.id, {
        data: { version: banner.version },
        preserveScroll: true,
      });
  };
  const period = (banner: BannerRow) => {
    if (!banner.available_from && !banner.available_until)
      return t('banners.always');
    return [
      banner.available_from &&
        t('banners.period_from', {
          date: date.format(new Date(banner.available_from)),
        }),
      banner.available_until &&
        t('banners.period_until', {
          date: date.format(new Date(banner.available_until)),
        }),
    ]
      .filter(Boolean)
      .join(' · ');
  };
  const columns = manage ? 7 : 5;

  return (
    <AdminLayout title={t('banners.title')} {...shell}>
      <EditorHeader
        heading={t('banners.title')}
        iconKey="banners"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('banners.description')}
            </p>
          </>
        }
        actions={
          <>
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                className="button-secondary"
                onClick={() => setDrawer(true)}
              >
                {t('banners.groups')}
              </button>
              {manage && (
                <Link
                  href="/admin/banners/create"
                  className="button-primary gap-2"
                >
                  <Icon name="plus" />
                  {t('banners.create')}
                </Link>
              )}
            </div>
          </>
        }
      />
      <section className="list-card" aria-label={t('banners.title')}>
        <FilterBar
          resetHref="/admin/banners"
          active={filtered}
          search={
            <SearchField
              id="banners-search"
              value={search}
              placeholder={t('banners.search')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('banners.filter_group')}
                value={filters.group ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'none', label: t('banners.group_none') },
                  ...groups.map((g) => ({ value: g.id, label: g.name })),
                ]}
                onChange={(value) => apply({ group: value })}
              />
              <FilterChip
                label={t('banners.filter_state')}
                value={filters.state ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  ...(
                    ['live', 'scheduled', 'expired', 'disabled'] as const
                  ).map((s) => ({ value: s, label: t(bannerLabels.state[s]) })),
                ]}
                onChange={(value) => apply({ state: value })}
              />
            </>
          }
        />
        {manage && selected.length > 0 && (
          <BulkBar count={selected.length} onClear={() => setPicked([])}>
            <button
              type="button"
              className="button-secondary"
              onClick={() => bulk('activate')}
            >
              {t('banners.bulk_activate')}
            </button>
            <button
              type="button"
              className="button-secondary"
              onClick={() => bulk('disable')}
            >
              {t('banners.bulk_disable')}
            </button>
            {groups.length > 0 && (
              <div className="w-56">
                <Select
                  aria-label={t('banners.bulk_group')}
                  value={target}
                  options={[
                    {
                      value: '',
                      label: t('banners.bulk_group'),
                      disabled: true,
                    },
                    ...groups.map((g) => ({ value: g.id, label: g.name })),
                  ]}
                  onChange={(value) => {
                    setTarget('');
                    bulk('group', value);
                  }}
                />
              </div>
            )}
            <button
              type="button"
              className="button-secondary"
              onClick={() => bulk('ungroup')}
            >
              {t('banners.bulk_ungroup')}
            </button>
            <button
              type="button"
              className="button-secondary text-red-700"
              onClick={() => bulk('delete')}
            >
              {t('banners.bulk_delete')}
            </button>
          </BulkBar>
        )}
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                {manage && (
                  <th className="w-10">
                    <input
                      type="checkbox"
                      aria-label={t('banners.select_all')}
                      checked={allSelected}
                      onChange={() => setPicked(allSelected ? [] : ids)}
                    />
                  </th>
                )}
                <th>{t('banners.col_banner')}</th>
                <th>{t('banners.col_position')}</th>
                <th>{t('banners.col_period')}</th>
                <th>{t('banners.col_status')}</th>
                {manage && (
                  <th className="text-right">{t('common.actions')}</th>
                )}
              </tr>
            </thead>
            <tbody>
              {banners.data.length === 0 && (
                <tr>
                  <td
                    colSpan={columns}
                    className="py-10 text-center text-slate-500"
                  >
                    {t(filtered ? 'banners.no_matches' : 'banners.empty')}
                  </td>
                </tr>
              )}
              {banners.data.map((banner, index) => {
                const previous = banners.data[index - 1];
                const header =
                  index === 0 || previous.group?.id !== banner.group?.id;
                return (
                  <Fragment key={banner.id}>
                    {header && (
                      <tr className="bg-slate-50">
                        <th
                          colSpan={columns}
                          scope="colgroup"
                          className="py-2 text-left text-xs font-semibold tracking-wide text-slate-600 uppercase"
                        >
                          {banner.group?.name ?? t('banners.group_none')}
                        </th>
                      </tr>
                    )}
                    <tr className={banner.state === 'live' ? '' : 'opacity-70'}>
                      {manage && (
                        <td>
                          <input
                            type="checkbox"
                            aria-label={t('banners.select_row', {
                              name: banner.name,
                            })}
                            checked={selected.includes(banner.id)}
                            onChange={() =>
                              setPicked((current) =>
                                current.includes(banner.id)
                                  ? current.filter((id) => id !== banner.id)
                                  : [...current, banner.id],
                              )
                            }
                          />
                        </td>
                      )}
                      <td>
                        <Link
                          href={'/admin/banners/' + banner.id + '/edit'}
                          className="flex items-center gap-3 hover:underline"
                        >
                          <span className="flex h-10 w-16 shrink-0 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-slate-50">
                            {banner.thumbnail ? (
                              <img
                                src={banner.thumbnail}
                                alt=""
                                className="size-full object-cover"
                              />
                            ) : (
                              <span className="text-[10px] text-slate-400 uppercase">
                                {t(bannerLabels.object[banner.object_type])}
                              </span>
                            )}
                          </span>
                          <span className="font-semibold">{banner.name}</span>
                        </Link>
                      </td>
                      <td className="text-sm tabular-nums">
                        {banner.position}
                      </td>
                      <td className="text-sm text-slate-600">
                        {period(banner)}
                      </td>
                      <td>
                        <StatusBadge tone={tone[banner.state]}>
                          {t(bannerLabels.state[banner.state])}
                        </StatusBadge>
                      </td>
                      {manage && (
                        <td>
                          <div className="flex justify-end gap-1">
                            <IconButton
                              icon="edit"
                              label={t('banners.edit') + ': ' + banner.name}
                              href={'/admin/banners/' + banner.id + '/edit'}
                            />
                            <IconButton
                              icon="copy"
                              label={t('banners.clone') + ': ' + banner.name}
                              onClick={() =>
                                router.post(
                                  '/admin/banners/' + banner.id + '/clone',
                                )
                              }
                            />
                            <IconButton
                              icon="trash"
                              danger
                              label={t('banners.delete') + ': ' + banner.name}
                              onClick={() => remove(banner)}
                            />
                          </div>
                        </td>
                      )}
                    </tr>
                  </Fragment>
                );
              })}
            </tbody>
          </table>
        </div>
        <Pagination page={banners} />
      </section>

      {drawer && (
        <div className="fixed inset-0 z-50 flex justify-end">
          <button
            type="button"
            aria-label={t('banners.close')}
            className={`absolute inset-0 bg-slate-900/15 transition-opacity duration-200 ease-out motion-reduce:transition-none ${shown ? 'opacity-100' : 'opacity-0'}`}
            onClick={closeDrawer}
          />
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="groups-title"
            className={`relative flex h-full w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-xl transition-transform duration-200 ease-out motion-reduce:transition-none ${shown ? 'translate-x-0' : 'translate-x-full'}`}
          >
            <header className="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
              <div>
                <h2 id="groups-title" className="text-lg font-semibold">
                  {t('banners.groups')}
                </h2>
                <p className="mt-0.5 text-sm text-slate-500">
                  {t('banners.groups_help')}
                </p>
              </div>
              <button
                type="button"
                aria-label={t('banners.close')}
                className="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50"
                onClick={closeDrawer}
              >
                <svg
                  aria-hidden="true"
                  viewBox="0 0 20 20"
                  className="size-4"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.75"
                  strokeLinecap="round"
                >
                  <path d="m5 5 10 10M15 5 5 15" />
                </svg>
              </button>
            </header>
            <div className="flex-1 space-y-4 overflow-y-auto px-6 py-6">
              {manage && (
                <form
                  className="flex gap-2"
                  onSubmit={(e) => {
                    e.preventDefault();
                    router.post(
                      '/admin/banner-groups',
                      { name: newGroup },
                      {
                        preserveScroll: true,
                        onSuccess: () => setNewGroup(''),
                      },
                    );
                  }}
                >
                  <input
                    className="field"
                    aria-label={t('banners.group_name')}
                    placeholder={t('banners.group_name')}
                    maxLength={120}
                    value={newGroup}
                    onChange={(e) => setNewGroup(e.target.value)}
                  />
                  <button
                    className="button-primary shrink-0"
                    disabled={!newGroup.trim()}
                  >
                    {t('banners.group_add')}
                  </button>
                </form>
              )}
              {groups.length === 0 && (
                <p className="text-sm text-slate-500">
                  {t('banners.groups_empty')}
                </p>
              )}
              <ul className="divide-y divide-slate-100">
                {groups.map((group) => (
                  <li key={group.id} className="flex items-center gap-3 py-3">
                    {renaming?.id === group.id ? (
                      <form
                        className="flex flex-1 gap-2"
                        onSubmit={(e) => {
                          e.preventDefault();
                          router.put(
                            '/admin/banner-groups/' + group.id,
                            { name: renaming.name },
                            {
                              preserveScroll: true,
                              onSuccess: () => setRenaming(null),
                            },
                          );
                        }}
                      >
                        <input
                          className="field"
                          aria-label={t('banners.group_name')}
                          maxLength={120}
                          value={renaming.name}
                          onChange={(e) =>
                            setRenaming({ id: group.id, name: e.target.value })
                          }
                        />
                        <EditorActionButton className="shrink-0">
                          {t('banners.save')}
                        </EditorActionButton>
                      </form>
                    ) : (
                      <>
                        <div className="min-w-0 flex-1">
                          <p className="truncate font-medium">{group.name}</p>
                          <p className="text-xs text-slate-500">
                            {t('banners.group_count', { count: group.banners })}
                          </p>
                        </div>
                        {manage && (
                          <>
                            <button
                              type="button"
                              className="text-sm underline underline-offset-4"
                              onClick={() =>
                                setRenaming({ id: group.id, name: group.name })
                              }
                            >
                              {t('banners.group_rename')}
                            </button>
                            <button
                              type="button"
                              className="text-sm text-red-700 underline underline-offset-4"
                              onClick={() => {
                                if (
                                  window.confirm(
                                    t('banners.group_disband_confirm', {
                                      name: group.name,
                                    }),
                                  )
                                )
                                  router.delete(
                                    '/admin/banner-groups/' + group.id,
                                    {
                                      preserveScroll: true,
                                    },
                                  );
                              }}
                            >
                              {t('banners.group_disband')}
                            </button>
                          </>
                        )}
                      </>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      )}
    </AdminLayout>
  );
}
