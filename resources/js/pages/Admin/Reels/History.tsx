import EditorHeader from '../../../components/admin/EditorHeader';
import { Link } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import { useListQuery } from '../../../lib/useListQuery';
import { usePreferences } from '../../../lib/usePreferences';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';

type Row = {
  id: string;
  user_id: string | null;
  name: string | null;
  email: string | null;
  viewed_at: string;
};
export default function ReelHistory({
  item,
  rows,
  stats,
  filters,
  ...shell
}: AdminShell & {
  item: { id: string; name: string };
  rows: Page<Row>;
  stats: { total: number; registered: number; anonymous: number };
  filters: Record<string, string>;
}) {
  const t = useTranslation();
  const { timezone: timeZone } = usePreferences();
  const base = `/admin/reels/${item.id}/history`;
  const apply = useListQuery(base, filters);
  return (
    <AdminLayout title={t('reels.history')} {...shell}>
      <EditorHeader
        heading={t('reels.history')}
        iconKey="video"
        backHref={`/admin/reels/${item.id}/edit`}
        backLabel={item.name}
      />
      <p className="mb-5 max-w-3xl text-sm text-slate-500">
        {t('reels.history_help')}
      </p>
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        {(
          [
            ['total', 'total_views'],
            ['registered', 'registered_viewers'],
            ['anonymous', 'anonymous_views'],
          ] as const
        ).map(([key, label]) => (
          <div
            key={key}
            className="rounded-xl border border-slate-200 bg-white p-5"
          >
            <div className="text-sm text-slate-500">{t(`reels.${label}`)}</div>
            <strong className="mt-2 block text-3xl">{stats[key]}</strong>
          </div>
        ))}
      </div>
      <div className="mb-4 flex flex-wrap gap-3">
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={filters.registered === '1'}
            onChange={(e) =>
              apply({ registered: e.target.checked ? '1' : '', user: '' })
            }
          />
          {t('reels.registered_only')}
        </label>
        {filters.user && (
          <Link className="text-link" href={base}>
            {t('reels.all_viewers')}
          </Link>
        )}
      </div>
      <section className="list-card">
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('reels.viewer')}</th>
                <th>{t('reels.viewed_at')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.data.length === 0 && (
                <tr>
                  <td colSpan={2} className="py-10 text-center text-slate-500">
                    {t('common.no_results')}
                  </td>
                </tr>
              )}
              {rows.data.map((row) => (
                <tr key={row.id}>
                  <td>
                    {row.user_id ? (
                      <Link
                        className="text-link"
                        href={`${base}?user=${row.user_id}`}
                      >
                        <span className="block font-semibold">{row.name}</span>
                        <span className="text-sm">{row.email}</span>
                      </Link>
                    ) : (
                      t('reels.anonymous')
                    )}
                  </td>
                  <td>
                    {new Date(row.viewed_at).toLocaleString(undefined, {
                      timeZone,
                    })}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={rows} />
      </section>
    </AdminLayout>
  );
}
