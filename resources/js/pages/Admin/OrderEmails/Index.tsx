import AdminCard from '../../../components/admin/AdminCard';
import { Link } from '@inertiajs/react';
import EditorHeader from '../../../components/admin/EditorHeader';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import IconButton from '../../../components/admin/list/IconButton';
import OrderStatusBadge from '../../../components/admin/OrderStatusBadge';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { OrderStatusOption } from '../../../types/orders';

type Row = OrderStatusOption & {
  templates: string[];
  updated_at: string | null;
};

export default function OrderEmailsIndex({
  statuses,
  invoiceStatuses,
  ...shell
}: AdminShell & { statuses: Row[]; invoiceStatuses: string[] }) {
  const t = useTranslation();
  const { date } = useDateDisplay();
  const shared = [
    {
      key: 'layout',
      title: t('orders.layout_title'),
      text: t('orders.layout_intro'),
      action: t('orders.edit_layout'),
    },
    {
      key: 'invoice',
      title: t('orders.invoice_email_title'),
      text:
        invoiceStatuses.length > 0
          ? t('orders.invoice_statuses', {
              statuses: invoiceStatuses.join(', '),
            })
          : t('orders.invoice_statuses_none'),
      action: t('orders.edit_invoice_email'),
    },
  ];
  return (
    <AdminLayout title={t('orders.emails_title')} {...shell}>
      <EditorHeader
        heading={t('orders.emails_title')}
        iconKey="emails"
        description={
          <>
            <p className="mt-1 max-w-2xl text-sm text-slate-500">
              {t('orders.emails_intro')}
            </p>
          </>
        }
      />
      <div className="mb-6 grid gap-4 md:grid-cols-2">
        {shared.map((card) => (
          <section
            key={card.key}
            className="flex items-start justify-between gap-4 rounded-lg border border-slate-200 bg-white p-5"
          >
            <div>
              <h2 className="text-base font-semibold">{card.title}</h2>
              <p className="mt-1 text-sm text-slate-500">{card.text}</p>
            </div>
            <IconButton
              icon="edit"
              label={card.action}
              href={
                '/admin/order-emails/' +
                (card.key === 'invoice' ? 'order-summary' : card.key)
              }
            />
          </section>
        ))}
      </div>
      <section aria-label={t('orders.status_emails')}>
        <AdminCard
          title={t('orders.status_emails')}
          iconKey="status_emails"
          bodyClassName="p-0 "
        >
          <div className="overflow-x-auto">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('common.status')}</th>
                  <th>{t('orders.template_languages')}</th>
                  <th>{t('orders.updated')}</th>
                  <th className="text-right">
                    <span className="sr-only">{t('common.actions')}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {statuses.map((s) => (
                  <tr key={s.id}>
                    <td>
                      <Link
                        href={'/admin/order-emails/' + s.id + '/edit'}
                        className="hover:underline"
                      >
                        <OrderStatusBadge status={s} />
                      </Link>
                      {s.initial && (
                        <span className="mt-1 block text-xs text-slate-500">
                          {t('orders.template_initial_hint')}
                        </span>
                      )}
                    </td>
                    <td className="text-sm">
                      {s.templates.join(', ').toUpperCase() || '—'}
                    </td>
                    <td className="text-sm text-slate-600">
                      {s.updated_at ? date.format(new Date(s.updated_at)) : '—'}
                    </td>
                    <td className="text-right">
                      <IconButton
                        icon="edit"
                        label={t('orders.edit_template') + ': ' + s.name}
                        href={'/admin/order-emails/' + s.id + '/edit'}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </AdminCard>
      </section>
    </AdminLayout>
  );
}
