import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import Pagination from '../../../components/admin/Pagination';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { Choice, SubscriberRow } from '../../../types/newsletters';
import type { SharedProps } from '../../../types/page';

export default function Subscribers({
  subscribers,
  filters,
  lists,
  ...shell
}: AdminShell & {
  subscribers: Page<SubscriberRow>;
  filters: Record<string, string>;
  lists: Choice[];
}) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const { date } = useDateDisplay();
  const apply = useListQuery('/admin/newsletter-subscribers', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const filtered = ['search', 'status', 'list'].some((k) => filters[k]);
  const canManage = capabilities['subscribers.manage'];
  const exportUrl =
    '/admin/newsletter-subscribers/export?' +
    new URLSearchParams(
      Object.entries(filters).filter(([k, v]) => k !== 'page' && v),
    ).toString();
  const remove = (row: SubscriberRow) => {
    if (
      !window.confirm(
        t('newsletters.delete_subscriber_confirm', { email: row.email }),
      )
    )
      return;
    router.delete('/admin/newsletter-subscribers/' + row.id, {
      data: { version: row.version },
      preserveScroll: true,
    });
  };

  return (
    <AdminLayout title={t('newsletters.subscribers_title')} {...shell}>
      <EditorHeader
        heading={t('newsletters.subscribers_title')}
        iconKey="emails"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('newsletters.subscribers_intro')}
            </p>
          </>
        }
        actions={
          <>
            <div className="flex flex-wrap gap-2">
              <a className="button-secondary" href={exportUrl}>
                {t('newsletters.export')}
              </a>
              {canManage && (
                <Link
                  className="button-primary gap-2"
                  href="/admin/newsletter-subscribers/create"
                >
                  <Icon name="plus" />
                  {t('newsletters.add')}
                </Link>
              )}
            </div>
          </>
        }
      />
      <div className="mb-4">
        <FormErrors errors={errors ?? {}} />
      </div>
      <section
        className="list-card"
        aria-label={t('newsletters.subscribers_title')}
      >
        <FilterBar
          resetHref="/admin/newsletter-subscribers"
          active={filtered}
          search={
            <SearchField
              id="subscriber-search"
              value={search}
              placeholder={t('newsletters.search_email')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('common.status')}
                value={filters.status ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  {
                    value: 'active',
                    label: t('newsletters.subscriber_active'),
                  },
                  {
                    value: 'unsubscribed',
                    label: t('newsletters.subscriber_unsubscribed'),
                  },
                ]}
                onChange={(value) => apply({ status: value })}
              />
              {lists.length > 0 && (
                <FilterChip
                  label={t('newsletters.lists_title')}
                  value={filters.list ?? ''}
                  options={[
                    { value: '', label: t('common.all') },
                    ...lists.map((l) => ({ value: l.id, label: l.name })),
                  ]}
                  onChange={(value) => apply({ list: value })}
                />
              )}
            </>
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.email')}</th>
                <th>{t('newsletters.lists_title')}</th>
                <th>{t('common.status')}</th>
                <th>{t('newsletters.added')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {subscribers.data.length === 0 && (
                <tr>
                  <td colSpan={5} className="py-10 text-center text-slate-500">
                    {t(
                      filtered
                        ? 'newsletters.no_matches'
                        : 'newsletters.subscribers_empty',
                    )}
                  </td>
                </tr>
              )}
              {subscribers.data.map((row) => (
                <tr key={row.id}>
                  <td className="text-sm font-medium">
                    {canManage ? (
                      <Link
                        className="hover:underline"
                        href={
                          '/admin/newsletter-subscribers/' + row.id + '/edit'
                        }
                      >
                        {row.email}
                      </Link>
                    ) : (
                      row.email
                    )}
                  </td>
                  <td className="text-sm">
                    {row.lists.length === 0 ? (
                      <span className="text-slate-400">—</span>
                    ) : (
                      row.lists.map((l) => (
                        <span key={l.id} className="mr-2 inline-block">
                          {l.name}
                          {!l.confirmed && (
                            <span className="ml-1 text-xs text-amber-700">
                              ({t('newsletters.unconfirmed')})
                            </span>
                          )}
                        </span>
                      ))
                    )}
                  </td>
                  <td>
                    <StatusBadge
                      tone={row.status === 'active' ? 'success' : 'neutral'}
                    >
                      {t(
                        row.status === 'active'
                          ? 'newsletters.subscriber_active'
                          : 'newsletters.subscriber_unsubscribed',
                      )}
                    </StatusBadge>
                  </td>
                  <td className="text-sm text-slate-500">
                    {row.created_at
                      ? date.format(new Date(row.created_at))
                      : '—'}
                  </td>
                  <td>
                    {canManage && (
                      <span className="flex justify-end gap-1">
                        <IconButton
                          icon="edit"
                          label={t('newsletters.edit_of', { name: row.email })}
                          href={
                            '/admin/newsletter-subscribers/' + row.id + '/edit'
                          }
                        />
                        <IconButton
                          icon="trash"
                          danger
                          label={t('newsletters.delete_of', {
                            name: row.email,
                          })}
                          onClick={() => remove(row)}
                        />
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={subscribers} />
      </section>
    </AdminLayout>
  );
}
