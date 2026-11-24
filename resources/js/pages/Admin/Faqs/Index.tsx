import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import Pagination from '../../../components/admin/Pagination';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { FaqRow } from '../../../types/faqs';
import type { SharedProps } from '../../../types/page';

export default function FaqIndex({
  faqs,
  filters,
  ...shell
}: AdminShell & { faqs: Page<FaqRow>; filters: Record<string, string> }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const apply = useListQuery('/admin/faqs', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  return (
    <AdminLayout title={t('faqs.title')} {...shell}>
      <EditorHeader
        heading={t('faqs.title')}
        iconKey="faqs"
        actions={
          <>
            <div className="flex flex-wrap gap-2">
              {capabilities['settings.manage'] && (
                <Link
                  className="button-secondary"
                  href="/admin/settings/general?tab=ai"
                >
                  {t('ai.settings')}
                </Link>
              )}
              {capabilities['ai.logs.view'] && (
                <Link
                  className="button-secondary"
                  href="/admin/settings/ai-log"
                >
                  {t('ai.log')}
                </Link>
              )}
              {capabilities['faqs.manage'] && (
                <Link className="button-primary" href="/admin/faqs/create">
                  <Icon name="plus" className="size-5 shrink-0" />
                  {t('faqs.create')}
                </Link>
              )}
            </div>
          </>
        }
      />
      <p className="mb-5 max-w-3xl text-sm text-slate-500">
        {t('faqs.description')}
      </p>
      <section className="list-card" aria-label={t('faqs.title')}>
        <FilterBar
          active={!!(filters.search || filters.type || filters.status)}
          resetHref="/admin/faqs"
          search={
            <SearchField
              id="faq-catalog-search"
              value={search}
              placeholder={t('faqs.search')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('faqs.type')}
                value={filters.type ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  ...['general', 'category', 'product'].map((value) => ({
                    value,
                    label: t(`faqs.type_${value}`),
                  })),
                ]}
                onChange={(type) => apply({ type })}
              />
              <FilterChip
                label={t('common.status')}
                value={filters.status ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'active', label: t('common.active') },
                  { value: 'disabled', label: t('common.inactive') },
                ]}
                onChange={(status) => apply({ status })}
              />
            </>
          }
        />
        {faqs.data.length === 0 ? (
          <p className="p-8 text-center text-sm text-slate-500">
            {t('common.no_results')}
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('faqs.question')}</th>
                  <th>{t('faqs.type')}</th>
                  <th>{t('common.status')}</th>
                  <th>{t('faqs.position')}</th>
                  <th>{t('faqs.links')}</th>
                  <th>{t('common.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {faqs.data.map((faq) => (
                  <tr key={faq.id}>
                    <td>
                      <Link
                        className="text-link"
                        href={'/admin/faqs/' + faq.id + '/edit'}
                      >
                        {faq.question}
                      </Link>
                    </td>
                    <td>{t(`faqs.type_${faq.type}`)}</td>
                    <td>
                      <StatusBadge
                        tone={faq.status === 'active' ? 'success' : 'neutral'}
                      >
                        {t(
                          faq.status === 'active'
                            ? 'common.active'
                            : 'common.inactive',
                        )}
                      </StatusBadge>
                    </td>
                    <td>{faq.position}</td>
                    <td>{faq.links}</td>
                    <td>
                      <Link
                        className="text-link"
                        href={'/admin/faqs/' + faq.id + '/edit'}
                      >
                        {t(
                          capabilities['faqs.manage']
                            ? 'common.edit'
                            : 'faqs.view',
                        )}
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pagination page={faqs} />
      </section>
    </AdminLayout>
  );
}
