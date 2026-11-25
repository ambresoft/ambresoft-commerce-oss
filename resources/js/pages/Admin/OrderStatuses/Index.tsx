import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import DragGrip from '../../../components/admin/list/DragGrip';
import FormErrors from '../../../components/admin/FormErrors';
import type { SharedProps } from '../../../types/page';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import { Icon, type IconName } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import Switch from '../../../components/admin/Switch';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { OrderStatusOption } from '../../../types/orders';

type Row = OrderStatusOption & {
  version: number;
  orders: number;
  templates: string[];
};

const stockChip: Record<
  OrderStatusOption['stock_action'],
  { icon: IconName; className: string }
> = {
  none: { icon: 'equals', className: 'bg-slate-100 text-slate-500' },
  decrease: { icon: 'minus', className: 'bg-amber-50 text-amber-700' },
  restore: { icon: 'plus', className: 'bg-primary-tint text-emerald-800' },
};

export default function OrderStatusesIndex({
  statuses,
  ...shell
}: AdminShell & { statuses: Row[] }) {
  const t = useTranslation();
  const { errors } = usePage<SharedProps & { errors: Record<string, string> }>()
    .props;
  const [pending, setPending] = useState<string[] | null>(null);
  const [dragging, setDragging] = useState<string | null>(null);
  const rows = pending
    ? pending
        .map((id) => statuses.find((s) => s.id === id))
        .filter((s): s is Row => s !== undefined)
    : statuses;
  const place = (id: string, target: number) => {
    if (pending !== null) return;
    const from = rows.findIndex((s) => s.id === id);
    if (from < 0 || target < 0 || target >= rows.length || from === target)
      return;
    const next = [...rows];
    next.splice(target, 0, next.splice(from, 1)[0]!);
    setPending(next.map((s) => s.id));
    router.post(
      '/admin/order-statuses/order',
      { ids: next.map((s) => s.id) },
      {
        preserveScroll: true,
        onFinish: () => setPending(null),
      },
    );
  };
  return (
    <AdminLayout title={t('orders.statuses_title')} {...shell}>
      <EditorHeader
        heading={t('orders.statuses_title')}
        iconKey="orders"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('orders.statuses_intro')}
            </p>
          </>
        }
        actions={
          <>
            <Link
              className="button-primary gap-2"
              href="/admin/order-statuses/create"
            >
              <Icon name="plus" />
              {t('orders.status_create')}
            </Link>
          </>
        }
      />
      <div className="mb-4">
        <FormErrors errors={errors ?? {}} />
      </div>
      <section className="list-card" aria-label={t('orders.statuses_title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('orders.order_position')}</th>
                <th>{t('common.name')}</th>
                <th>{t('orders.status_code')}</th>
                <th className="min-w-44 whitespace-normal">
                  {t('orders.stock_action')}
                </th>
                <th className="text-right">{t('orders.orders_using')}</th>
                <th>{t('orders.email_template')}</th>
                <th>{t('common.status')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((s, index) => (
                <tr
                  key={s.id}
                  draggable={pending === null}
                  onDragStart={(e) => {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', s.id);
                    setDragging(s.id);
                  }}
                  onDragEnd={() => setDragging(null)}
                  onDragOver={(e) => {
                    if (dragging === null) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                  }}
                  onDrop={(e) => {
                    e.preventDefault();
                    if (dragging !== null) place(dragging, index);
                    setDragging(null);
                  }}
                  className={dragging === s.id ? 'opacity-50' : ''}
                >
                  <td>
                    <button
                      type="button"
                      disabled={pending !== null}
                      aria-label={t('orders.drag_handle', { name: s.name })}
                      title={t('orders.drag_handle', { name: s.name })}
                      className="flex cursor-grab items-center gap-2 rounded text-slate-400 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-emerald-600"
                      onKeyDown={(e) => {
                        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                          e.preventDefault();
                          place(s.id, index + (e.key === 'ArrowUp' ? -1 : 1));
                        }
                      }}
                    >
                      <DragGrip />
                      <span className="text-sm text-slate-600 tabular-nums">
                        {index + 1}
                      </span>
                    </button>
                  </td>
                  <td className="text-sm">
                    <Link
                      className="inline-flex items-center gap-2 font-medium hover:underline"
                      href={'/admin/order-statuses/' + s.id + '/edit'}
                    >
                      <span
                        aria-hidden
                        className="inline-block size-2.5 shrink-0 rounded-full"
                        style={{ backgroundColor: s.color }}
                      />
                      {s.name}
                    </Link>
                    {(s.initial ||
                      s.final ||
                      s.invoice ||
                      s.customer_can_cancel) && (
                      <span className="mt-1 flex flex-wrap gap-1 pl-[1.125rem]">
                        {s.initial && (
                          <StatusBadge tone="success">
                            {t('orders.status_initial')}
                          </StatusBadge>
                        )}
                        {s.final && (
                          <StatusBadge tone="neutral">
                            {t('orders.status_final')}
                          </StatusBadge>
                        )}
                        {s.invoice && (
                          <StatusBadge tone="neutral">
                            {t('orders.status_sends_invoice')}
                          </StatusBadge>
                        )}
                        {s.customer_can_cancel && (
                          <StatusBadge tone="neutral">
                            {t('orders.status_customer_can_cancel_badge')}
                          </StatusBadge>
                        )}
                      </span>
                    )}
                  </td>
                  <td className="font-mono text-xs text-slate-500">{s.code}</td>
                  <td className="text-sm">
                    <span
                      className={
                        'flex items-center gap-2 ' +
                        (s.stock_action === 'none' ? 'text-slate-500' : '')
                      }
                    >
                      <span
                        aria-hidden
                        className={
                          'inline-flex size-5 shrink-0 items-center justify-center rounded ' +
                          stockChip[s.stock_action].className
                        }
                      >
                        <Icon
                          name={stockChip[s.stock_action].icon}
                          className="size-3.5"
                        />
                      </span>
                      {t(`orders.stock_${s.stock_action}`)}
                    </span>
                  </td>
                  <td className="text-right text-sm tabular-nums">
                    {s.orders > 0 ? (
                      <Link
                        className="hover:underline"
                        href={'/admin/orders?status=' + s.id}
                      >
                        {s.orders}
                      </Link>
                    ) : (
                      <span className="text-slate-400">0</span>
                    )}
                  </td>
                  <td className="text-sm">
                    <Link
                      className="text-link inline-flex items-center gap-1.5 whitespace-nowrap"
                      href={'/admin/order-emails/' + s.id + '/edit'}
                    >
                      <Icon name="mail" />
                      {t('orders.edit_template')}
                    </Link>
                    <span className="mt-0.5 block text-xs text-slate-400 uppercase">
                      {s.templates.join(' · ')}
                    </span>
                  </td>
                  <td>
                    <span className="flex items-center gap-2 text-sm">
                      {/* The initial status is always active, so its switch is locked on. */}
                      <Switch
                        checked={s.active}
                        disabled={s.initial}
                        label={t('orders.status_active_of', { name: s.name })}
                        onChange={(active) =>
                          router.patch(
                            '/admin/order-statuses/' + s.id + '/active',
                            { version: s.version, active },
                            { preserveScroll: true },
                          )
                        }
                      />
                      <span
                        className={
                          s.active ? 'text-slate-700' : 'text-slate-500'
                        }
                      >
                        {t(s.active ? 'orders.active' : 'orders.inactive')}
                      </span>
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </AdminLayout>
  );
}
