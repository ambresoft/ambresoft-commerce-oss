import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import Pagination from '../../../components/admin/Pagination';
import Switch from '../../../components/admin/Switch';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge, {
  type Tone,
} from '../../../components/admin/list/StatusBadge';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type {
  Choice,
  NewsletterRow,
  NewsletterType,
} from '../../../types/newsletters';

// Literal keys (translation keys are never built at runtime).
const stateText: Record<string, [string, Tone]> = {
  draft: ['newsletters.state_draft', 'neutral'],
  sending: ['newsletters.state_sending', 'warning'],
  sent: ['newsletters.state_sent', 'success'],
};
const titles: Record<NewsletterType, string> = {
  newsletter: 'newsletters.newsletters_title',
  template: 'newsletters.templates_title',
  autoresponder: 'newsletters.autoresponders_title',
};
const intros: Record<NewsletterType, string> = {
  newsletter: 'newsletters.newsletters_intro',
  template: 'newsletters.templates_intro',
  autoresponder: 'newsletters.autoresponders_intro',
};
const emptyText: Record<NewsletterType, string> = {
  newsletter: 'newsletters.newsletters_empty',
  template: 'newsletters.templates_empty',
  autoresponder: 'newsletters.autoresponders_empty',
};

export default function NewslettersIndex({
  type,
  base,
  items,
  filters,
  campaigns,
  ...shell
}: AdminShell & {
  type: NewsletterType;
  base: string;
  items: Page<NewsletterRow>;
  filters: Record<string, string>;
  campaigns: Choice[];
}) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const { date, time } = useDateDisplay();
  const apply = useListQuery(base, filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const filtered = ['search', 'status', 'campaign'].some((k) => filters[k]);
  const canManage = capabilities['newsletters.manage'];
  const isNewsletter = type === 'newsletter';
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const remove = (row: NewsletterRow) => {
    if (!window.confirm(t('newsletters.delete_confirm', { name: row.name })))
      return;
    router.delete(base + '/' + row.id, {
      data: { version: row.version },
      preserveScroll: true,
    });
  };

  return (
    <AdminLayout title={t(titles[type])} {...shell}>
      <EditorHeader
        heading={t(titles[type])}
        iconKey="emails"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">{t(intros[type])}</p>
          </>
        }
        actions={
          <>
            {canManage && (
              <Link className="button-primary gap-2" href={base + '/create'}>
                <Icon name="plus" />
                {t('newsletters.add')}
              </Link>
            )}
          </>
        }
      />
      <div className="mb-4">
        <FormErrors errors={errors ?? {}} />
      </div>
      <section className="list-card" aria-label={t(titles[type])}>
        <FilterBar
          resetHref={base}
          active={filtered}
          search={
            <SearchField
              id="newsletter-search"
              value={search}
              placeholder={t('newsletters.search_placeholder')}
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
                  { value: 'active', label: t('newsletters.active') },
                  { value: 'disabled', label: t('newsletters.inactive') },
                ]}
                onChange={(value) => apply({ status: value })}
              />
              {isNewsletter && campaigns.length > 0 && (
                <FilterChip
                  label={t('newsletters.campaign')}
                  value={filters.campaign ?? ''}
                  options={[
                    { value: '', label: t('common.all') },
                    ...campaigns.map((c) => ({ value: c.id, label: c.name })),
                  ]}
                  onChange={(value) => apply({ campaign: value })}
                />
              )}
            </>
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.name')}</th>
                {!isNewsletter ? null : <th>{t('newsletters.campaign')}</th>}
                {isNewsletter && <th>{t('newsletters.delivery')}</th>}
                <th>{t('common.status')}</th>
                <th>{t('newsletters.updated')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {items.data.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-slate-500">
                    {t(filtered ? 'newsletters.no_matches' : emptyText[type])}
                  </td>
                </tr>
              )}
              {items.data.map((row) => (
                <tr key={row.id}>
                  <td className="text-sm">
                    {canManage ? (
                      <Link
                        className="font-medium hover:underline"
                        href={base + '/' + row.id + '/edit'}
                      >
                        {row.name}
                      </Link>
                    ) : (
                      <span className="font-medium">{row.name}</span>
                    )}
                    {type !== 'template' && row.subject && (
                      <span className="mt-0.5 block text-xs text-slate-500">
                        {row.subject}
                      </span>
                    )}
                  </td>
                  {isNewsletter && (
                    <td className="text-sm">
                      {row.campaign ?? (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                  )}
                  {isNewsletter && (
                    <td className="text-sm">
                      {row.state && (
                        <StatusBadge tone={stateText[row.state][1]}>
                          {t(stateText[row.state][0])}
                        </StatusBadge>
                      )}
                      {row.stats.total > 0 && (
                        <span className="mt-1 block text-xs text-slate-500 tabular-nums">
                          {t('newsletters.stats_line', {
                            sent: row.stats.sent,
                            total: row.stats.total,
                            failed: row.stats.failed,
                          })}
                        </span>
                      )}
                    </td>
                  )}
                  <td>
                    <span className="flex items-center gap-2 text-sm">
                      <Switch
                        checked={row.active}
                        disabled={!canManage}
                        label={t('newsletters.active_of', { name: row.name })}
                        onChange={(active) =>
                          router.patch(
                            base + '/' + row.id + '/active',
                            { version: row.version, active },
                            { preserveScroll: true },
                          )
                        }
                      />
                      <span
                        className={
                          row.active ? 'text-slate-700' : 'text-slate-500'
                        }
                      >
                        {t(
                          row.active
                            ? 'newsletters.active'
                            : 'newsletters.inactive',
                        )}
                      </span>
                    </span>
                  </td>
                  <td className="text-sm text-slate-500">
                    {row.updated_at ? when(row.updated_at) : '—'}
                  </td>
                  <td>
                    {canManage && (
                      <span className="flex justify-end gap-1">
                        <IconButton
                          icon="edit"
                          label={t('newsletters.edit_of', { name: row.name })}
                          href={base + '/' + row.id + '/edit'}
                        />
                        <IconButton
                          icon="trash"
                          danger
                          label={t('newsletters.delete_of', { name: row.name })}
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
        <Pagination page={items} />
      </section>
    </AdminLayout>
  );
}
