import { Link } from '@inertiajs/react';
import { useTranslation } from '../../lib/useTranslation';
import type { Pagination as Page } from '../../types/admin';
import Select from './Select';

export default function Pagination({
  page,
  perPage,
}: {
  page: Omit<Page<unknown>, 'data'>;
  perPage?: {
    value: string;
    options: string[];
    onChange: (value: string) => void;
  };
}) {
  const t = useTranslation();
  const label = t('common.page', {
    page: page.current_page,
    last: page.last_page,
  });
  return (
    <nav
      aria-label={label}
      className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm sm:px-5"
    >
      <p className="text-slate-500 tabular-nums">
        {label}
        <span aria-hidden="true"> · </span>
        {t('common.results', { count: page.total })}
      </p>
      <div className="flex flex-wrap items-center gap-3">
        {perPage && (
          <div className="flex items-center gap-2">
            <span aria-hidden="true" className="text-slate-500">
              {t('common.rows_per_page')}
            </span>
            <div className="w-20">
              <Select
                aria-label={t('common.rows_per_page')}
                value={perPage.value}
                onChange={perPage.onChange}
                options={perPage.options.map((value) => ({
                  value,
                  label: value,
                }))}
              />
            </div>
          </div>
        )}
        {(page.prev_page_url || page.next_page_url) && (
          <div className="flex gap-2">
            {page.prev_page_url ? (
              <Link className="button-secondary" href={page.prev_page_url}>
                {t('common.previous')}
              </Link>
            ) : (
              <span className="button-secondary pointer-events-none opacity-50">
                {t('common.previous')}
              </span>
            )}
            {page.next_page_url ? (
              <Link className="button-secondary" href={page.next_page_url}>
                {t('common.next')}
              </Link>
            ) : (
              <span className="button-secondary pointer-events-none opacity-50">
                {t('common.next')}
              </span>
            )}
          </div>
        )}
      </div>
    </nav>
  );
}
