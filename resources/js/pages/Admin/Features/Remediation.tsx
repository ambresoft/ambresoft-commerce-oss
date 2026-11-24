import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import StatusBadge from '../../../components/admin/list/StatusBadge';
export default function Remediation({
  products,
  ...shell
}: AdminShell & {
  products: Page<{
    product_id: string;
    sku: string;
    product_name: string;
    feature_id: string;
    code: string;
    feature_name: string;
  }>;
}) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  return (
    <AdminLayout title={t('features.remediation')} {...shell}>
      <EditorHeader
        heading={t('features.remediation')}
        iconKey="features"
        backHref="/admin/features"
        backLabel={t('features.title')}
      />
      <p className="mb-5 text-sm text-slate-500">
        {t('features.remediation_help')}
      </p>
      <section className="list-card" aria-label={t('features.remediation')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('products.title')}</th>
                <th>{t('features.required')}</th>
              </tr>
            </thead>
            <tbody>
              {products.data.map((row) => (
                <tr key={row.product_id + ':' + row.feature_id}>
                  <td>
                    {capabilities['products.update'] ? (
                      <Link
                        className="font-semibold text-slate-900 hover:underline"
                        href={
                          '/admin/products/' +
                          row.product_id +
                          '/edit?tab=features'
                        }
                      >
                        {row.product_name}
                      </Link>
                    ) : (
                      <span className="font-semibold">{row.product_name}</span>
                    )}
                    <p className="mt-0.5 text-xs text-slate-500">{row.sku}</p>
                  </td>
                  <td>
                    <StatusBadge tone="warning">
                      {row.feature_name || row.code}
                    </StatusBadge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {products.data.length === 0 && (
            <p className="p-12 text-center text-sm text-slate-500">
              {t('features.compliant')}
            </p>
          )}
        </div>
        <Pagination page={products} />
      </section>
    </AdminLayout>
  );
}
