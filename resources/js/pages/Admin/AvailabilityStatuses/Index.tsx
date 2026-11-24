import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
export type AvailabilityStatus = {
  id: string;
  name: string;
  position: number;
  version: number;
  default_in_stock: boolean;
  default_out_of_stock: boolean;
  usage: number;
  translations?: Record<string, { name: string }>;
};
export default function Index({
  statuses,
  ...shell
}: AdminShell & { statuses: AvailabilityStatus[] }) {
  const t = useTranslation();
  return (
    <AdminLayout title={t('availability.title')} {...shell}>
      <EditorHeader
        heading={t('availability.title')}
        iconKey="inventory"
        actions={
          <>
            <Link
              className="button-primary"
              href="/admin/availability-statuses/create"
            >
              <Icon name="plus" className="size-5 shrink-0" />
              {t('availability.create')}
            </Link>
          </>
        }
      />
      <p className="mb-5 max-w-3xl text-sm text-slate-500">
        {t('availability.description')}
      </p>
      <section className="list-card overflow-x-auto">
        <table className="data-table">
          <thead>
            <tr>
              <th>{t('common.name')}</th>
              <th>{t('availability.defaults')}</th>
              <th>{t('availability.position')}</th>
              <th>{t('availability.usage')}</th>
              <th>{t('common.actions')}</th>
            </tr>
          </thead>
          <tbody>
            {statuses.map((status) => (
              <tr key={status.id}>
                <td>
                  <Link
                    className="text-link"
                    href={`/admin/availability-statuses/${status.id}/edit`}
                  >
                    {status.name}
                  </Link>
                </td>
                <td>
                  {[
                    status.default_in_stock ? t('availability.in_stock') : '',
                    status.default_out_of_stock
                      ? t('availability.out_of_stock')
                      : '',
                  ]
                    .filter(Boolean)
                    .join(' · ') || '—'}
                </td>
                <td>{status.position}</td>
                <td>{status.usage}</td>
                <td>
                  <Link
                    className="text-link"
                    href={`/admin/availability-statuses/${status.id}/edit`}
                  >
                    {t('common.edit')}
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </AdminLayout>
  );
}
