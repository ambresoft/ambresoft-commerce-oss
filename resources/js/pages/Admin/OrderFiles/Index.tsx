import EditorHeader from '../../../components/admin/EditorHeader';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import IconButton from '../../../components/admin/list/IconButton';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';

/** Orders → Files: documents staff print for an order (from the order's page). */
export default function OrderFilesIndex(shell: AdminShell) {
  const t = useTranslation();
  return (
    <AdminLayout title={t('orders.files_title')} {...shell}>
      <EditorHeader
        heading={t('orders.files_title')}
        iconKey="attachments"
        description={
          <>
            <p className="mt-1 max-w-2xl text-sm text-slate-500">
              {t('orders.files_intro')}
            </p>
          </>
        }
      />
      <div className="grid gap-4 md:grid-cols-2">
        <section className="flex items-start justify-between gap-4 rounded-lg border border-slate-200 bg-white p-5">
          <div>
            <h2 className="text-base font-semibold">
              {t('orders.packing_slip_title')}
            </h2>
            <p className="mt-1 text-sm text-slate-500">
              {t('orders.packing_slip_intro')}
            </p>
          </div>
          <IconButton
            icon="edit"
            label={t('orders.edit_packing_slip')}
            href="/admin/orders/files/packing-slip"
          />
        </section>
      </div>
    </AdminLayout>
  );
}
