import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import FormErrors from '../../../components/admin/FormErrors';
import Pagination from '../../../components/admin/Pagination';
import Switch from '../../../components/admin/Switch';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { SeoRule } from '../../../types/seoRules';

/** Website → SEO Rules: URL redirects. */
export default function SeoRulesIndex({
  rules,
  filters,
  ...shell
}: AdminShell & { rules: Page<SeoRule>; filters: Record<string, string> }) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<SharedProps>().props;
  const manage = !!capabilities['seo-rules.manage'];
  const apply = useListQuery('/admin/seo-rules', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [removing, setRemoving] = useState<SeoRule | null>(null);
  const filtered = !!(filters.search || filters.status || filters.code);
  return (
    <AdminLayout title={t('seo_rules.title')} {...shell}>
      <EditorHeader
        heading={t('seo_rules.title')}
        iconKey="seo"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('seo_rules.description')}
            </p>
          </>
        }
        actions={
          <>
            {manage && (
              <Link
                href="/admin/seo-rules/create"
                className="button-primary gap-2"
              >
                <Icon name="plus" />
                {t('seo_rules.create')}
              </Link>
            )}
          </>
        }
      />
      <FormErrors errors={errors ?? {}} />
      <section className="list-card" aria-label={t('seo_rules.title')}>
        <FilterBar
          resetHref="/admin/seo-rules"
          active={filtered}
          search={
            <SearchField
              id="seo-rules-search"
              value={search}
              onChange={setSearch}
              placeholder={t('seo_rules.search')}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('seo_rules.status')}
                value={filters.status ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'enabled', label: t('seo_rules.status_enabled') },
                  { value: 'disabled', label: t('seo_rules.status_disabled') },
                ]}
                onChange={(status) => apply({ status })}
              />
              <FilterChip
                label={t('seo_rules.status_code')}
                value={filters.code ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: '301', label: t('seo_rules.code_301') },
                  { value: '302', label: t('seo_rules.code_302') },
                ]}
                onChange={(code) => apply({ code })}
              />
            </>
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('seo_rules.source_path')}</th>
                <th>{t('seo_rules.destination')}</th>
                <th>{t('seo_rules.status_code')}</th>
                <th>{t('seo_rules.status')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {rules.data.length === 0 && (
                <tr>
                  <td colSpan={5} className="py-10 text-center text-slate-500">
                    {t(filtered ? 'seo_rules.no_matches' : 'seo_rules.empty')}
                  </td>
                </tr>
              )}
              {rules.data.map((rule) => (
                <tr key={rule.id} className={rule.enabled ? '' : 'opacity-75'}>
                  <td className="max-w-xs break-all">
                    <Link
                      href={'/admin/seo-rules/' + rule.id + '/edit'}
                      className="font-mono text-sm font-semibold hover:underline"
                    >
                      {rule.source_path}
                    </Link>
                  </td>
                  <td className="max-w-sm break-all font-mono text-xs text-slate-600">
                    {rule.destination}
                    {rule.external && (
                      <span className="ml-2 font-sans text-[11px] uppercase text-slate-400">
                        {t('seo_rules.external')}
                      </span>
                    )}
                    {rule.enabled && rule.chain_to && (
                      <p
                        role="note"
                        className="mt-1 font-sans text-xs text-amber-700 dark:text-amber-300"
                      >
                        {t('seo_rules.chain_warning', { next: rule.chain_to })}
                      </p>
                    )}
                  </td>
                  <td className="text-sm tabular-nums">{rule.status_code}</td>
                  <td>
                    <StatusBadge tone={rule.enabled ? 'success' : 'neutral'}>
                      {t(
                        rule.enabled
                          ? 'seo_rules.status_enabled'
                          : 'seo_rules.status_disabled',
                      )}
                    </StatusBadge>
                  </td>
                  <td>
                    {manage && (
                      <div className="flex items-center justify-end gap-2">
                        <IconButton
                          icon="edit"
                          label={t('seo_rules.edit') + ': ' + rule.source_path}
                          href={'/admin/seo-rules/' + rule.id + '/edit'}
                        />
                        <IconButton
                          icon="trash"
                          danger
                          label={
                            t('seo_rules.delete') + ': ' + rule.source_path
                          }
                          onClick={() => setRemoving(rule)}
                        />
                        <Switch
                          checked={rule.enabled}
                          label={t('seo_rules.toggle', {
                            source: rule.source_path,
                          })}
                          onChange={(enabled) =>
                            router.patch(
                              '/admin/seo-rules/' + rule.id + '/status',
                              { version: rule.version, enabled },
                              { preserveScroll: true },
                            )
                          }
                        />
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination
          page={rules}
          perPage={{
            value: filters.per_page ?? '25',
            options: ['25', '50', '100'],
            onChange: (per_page) => apply({ per_page }),
          }}
        />
      </section>
      {removing && (
        <ConfirmDeleteDialog
          title={t('seo_rules.delete_title', { source: removing.source_path })}
          facts={[t('seo_rules.delete_body')]}
          onCancel={() => setRemoving(null)}
          onArchive={
            removing.enabled
              ? () => {
                  router.patch(
                    '/admin/seo-rules/' + removing.id + '/status',
                    { version: removing.version, enabled: false },
                    { preserveScroll: true },
                  );
                  setRemoving(null);
                }
              : undefined
          }
          onConfirm={() =>
            router.delete('/admin/seo-rules/' + removing.id, {
              data: { version: removing.version },
              onFinish: () => setRemoving(null),
            })
          }
        />
      )}
    </AdminLayout>
  );
}
