import EditorHeader from '../../../components/admin/EditorHeader';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, router } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import { useListQuery } from '../../../lib/useListQuery';
import { useState } from 'react';

type Row = {
  key: string;
  original: string;
  value: string;
  overridden: boolean;
};
function TranslationRow({ row, locale }: { row: Row; locale: string }) {
  const t = useTranslation();
  const form = useForm({
    key: row.key,
    locale,
    value: row.value,
    restore: false,
  });
  useDirtyForm(form);
  return (
    <form
      className="space-y-3 border-b border-slate-100 py-5 last:border-0"
      onSubmit={(e) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, restore: false }));
        form.put('/admin/settings/languages/translations', {
          preserveScroll: true,
        });
      }}
    >
      <label htmlFor={row.key} className="block break-all font-mono text-xs">
        {row.key}
      </label>
      <p className="whitespace-pre-wrap text-sm text-slate-500">
        {row.original}
      </p>
      <textarea
        id={row.key}
        className="field min-h-20"
        value={form.data.value}
        required
        maxLength={20000}
        onChange={(e) => form.setData('value', e.target.value)}
      />
      <FormErrors errors={form.errors} />
      <div className="flex flex-wrap items-center gap-3">
        <button className="button-secondary" disabled={form.processing}>
          {t('common.save')}
        </button>
        {row.overridden && (
          <button
            className="button-secondary"
            type="button"
            disabled={form.processing}
            onClick={() => {
              form.transform((data) => ({ ...data, restore: true }));
              form.put('/admin/settings/languages/translations', {
                preserveScroll: true,
                onSuccess: () => form.setData('value', row.original),
              });
            }}
          >
            {t('languages.restore')}
          </button>
        )}
        <span className="text-xs text-slate-500">
          {t(row.overridden ? 'languages.overridden' : 'languages.original')}
        </span>
      </div>
    </form>
  );
}
export default function Translations({
  languages,
  filters,
  rows,
  page,
  lastPage,
  total,
  ...shell
}: AdminShell & {
  languages: { code: string; name: string }[];
  filters: { locale: string; q: string };
  rows: Row[];
  page: number;
  lastPage: number;
  total: number;
}) {
  const t = useTranslation();
  const apply = useListQuery('/admin/settings/languages/translations', filters);
  const [search, setSearch] = useState(filters.q);
  const visit = (target: number) =>
    router.get('/admin/settings/languages/translations', {
      ...filters,
      page: target,
    });
  return (
    <AdminLayout title={t('languages.translations')} {...shell}>
      <EditorHeader
        heading={t('languages.translations')}
        iconKey="translations"
      />
      <p className="mb-6 max-w-3xl text-sm text-slate-600">
        {t('languages.dictionary_help')}
      </p>
      <section className="list-card mb-5" aria-label={t('common.filters')}>
        <FilterBar
          resetHref={
            '/admin/settings/languages/translations?locale=' + filters.locale
          }
          active={!!filters.q}
          search={
            <SearchField
              id="dictionary-search"
              value={search}
              placeholder={t('common.search_placeholder')}
              onChange={setSearch}
              onSubmit={() => apply({ q: search })}
            />
          }
          chips={
            <FilterChip
              label={t('common.language')}
              value={filters.locale}
              options={languages.map((l) => ({ value: l.code, label: l.name }))}
              onChange={(value) => apply({ locale: value })}
            />
          }
        />
      </section>
      <p className="mb-2 text-sm text-slate-500">
        {t('languages.string_count', { count: total })}
      </p>
      <section className="list-card px-5 sm:px-7">
        {rows.length ? (
          rows.map((row) => (
            <TranslationRow
              key={filters.locale + row.key + row.value}
              row={row}
              locale={filters.locale}
            />
          ))
        ) : (
          <p className="py-6 text-sm text-slate-500">
            {t('common.no_results')}
          </p>
        )}
      </section>
      <nav
        className="mt-5 flex items-center gap-4"
        aria-label={t('languages.pagination')}
      >
        <button
          className="button-secondary"
          disabled={page <= 1}
          onClick={() => visit(page - 1)}
        >
          {t('common.previous')}
        </button>
        <span className="text-sm">
          {t('common.page', { page, last: lastPage })}
        </span>
        <button
          className="button-secondary"
          disabled={page >= lastPage}
          onClick={() => visit(page + 1)}
        >
          {t('common.next')}
        </button>
      </nav>
    </AdminLayout>
  );
}
