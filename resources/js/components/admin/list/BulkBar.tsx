import type { ReactNode } from 'react';
import { useTranslation } from '../../../lib/useTranslation';

/** Tinted bar shown between the filters and the table while rows are selected. */
export default function BulkBar({
  count,
  onClear,
  note,
  children,
}: {
  count: number;
  onClear: () => void;
  /** Scope note after the count; defaults to "(current page)". */
  note?: ReactNode;
  children: ReactNode;
}) {
  const t = useTranslation();
  return (
    <div className="flex flex-wrap items-center gap-3 border-t border-emerald-100 bg-primary-tint px-4 py-3 text-sm sm:px-5">
      <p>
        <strong className="font-semibold">
          {t('common.selected', { count })}
        </strong>{' '}
        <span className="text-slate-500">
          {note === undefined ? t('common.current_page') : note}
        </span>
      </p>
      {children}
      <button
        type="button"
        className="ml-auto text-sm text-slate-700 underline underline-offset-4 hover:text-slate-900"
        onClick={onClear}
      >
        {t('common.clear_selection')}
      </button>
    </div>
  );
}
