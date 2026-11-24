import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import DragGrip from '../../../components/admin/list/DragGrip';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import Switch from '../../../components/admin/Switch';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { PaymentMethodRow } from '../../../types/payments';

export default function PaymentMethodsIndex({
  methods,
  ...shell
}: AdminShell & { methods: PaymentMethodRow[] }) {
  const t = useTranslation();
  const { errors } = usePage<SharedProps & { errors: Record<string, string> }>()
    .props;
  // The dropped order shows immediately; the server order replaces it once saved.
  const [pending, setPending] = useState<string[] | null>(null);
  const [dragging, setDragging] = useState<string | null>(null);
  const rows = pending
    ? pending
        .map((code) => methods.find((m) => m.code === code))
        .filter((m): m is PaymentMethodRow => m !== undefined)
    : methods;
  const save = (next: PaymentMethodRow[]) => {
    setPending(next.map((m) => m.code));
    router.post(
      '/admin/payment-methods/order',
      { codes: next.map((m) => m.code) },
      { preserveScroll: true, onFinish: () => setPending(null) },
    );
  };
  const place = (code: string, target: number) => {
    const from = rows.findIndex((m) => m.code === code);
    if (from < 0 || target < 0 || target >= rows.length || from === target)
      return;
    const next = [...rows];
    next.splice(target, 0, next.splice(from, 1)[0]!);
    save(next);
  };
  return (
    <AdminLayout title={t('payments.title')} {...shell}>
      <EditorHeader
        heading={t('payments.title')}
        iconKey="payments"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">{t('payments.intro')}</p>
          </>
        }
      />
      <div className="mb-4">
        <FormErrors errors={errors ?? {}} />
      </div>
      <section className="list-card" aria-label={t('payments.title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('payments.order_position')}</th>
                <th>{t('common.name')}</th>
                <th>{t('payments.provider')}</th>
                <th>{t('payments.configuration')}</th>
                <th>{t('common.status')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((method, index) => (
                <tr
                  key={method.code}
                  draggable
                  onDragStart={(e) => {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', method.code);
                    setDragging(method.code);
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
                  className={dragging === method.code ? 'opacity-50' : ''}
                >
                  <td>
                    <button
                      type="button"
                      aria-label={t('payments.drag_handle', {
                        name: method.name,
                      })}
                      title={t('payments.drag_handle', { name: method.name })}
                      className="flex cursor-grab items-center gap-2 rounded text-slate-400 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-emerald-600"
                      onKeyDown={(e) => {
                        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                          e.preventDefault();
                          place(
                            method.code,
                            index + (e.key === 'ArrowUp' ? -1 : 1),
                          );
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
                    <span className="flex items-start gap-3">
                      {method.image_url && (
                        <img
                          src={method.image_url}
                          alt=""
                          className="size-10 shrink-0 rounded border border-slate-200 bg-white object-contain"
                        />
                      )}
                      <span>
                        <Link
                          className="font-medium hover:underline"
                          href={
                            '/admin/payment-methods/' + method.code + '/edit'
                          }
                        >
                          {method.name}
                        </Link>
                        {method.description && (
                          <span className="mt-0.5 block max-w-md text-xs whitespace-pre-line text-slate-500">
                            {method.description}
                          </span>
                        )}
                      </span>
                    </span>
                  </td>
                  <td className="text-sm">
                    <span className="block">
                      {method.owner_title
                        ? t('payments.addon', { name: method.owner_title })
                        : t('payments.core')}
                    </span>
                    <span className="mt-0.5 block text-xs text-slate-500">
                      {t(
                        method.kind === 'redirect'
                          ? 'payments.kind_redirect'
                          : 'payments.kind_offline',
                      )}
                    </span>
                  </td>
                  <td className="text-sm">
                    {method.issues.length === 0 ? (
                      <StatusBadge tone="success">
                        {t('payments.ready')}
                      </StatusBadge>
                    ) : (
                      <>
                        <StatusBadge tone="warning">
                          {t('payments.needs_attention')}
                        </StatusBadge>
                        <ul className="mt-1 max-w-xs space-y-0.5 text-xs text-slate-500">
                          {method.issues.map((issue) => (
                            <li key={issue}>{issue}</li>
                          ))}
                        </ul>
                      </>
                    )}
                  </td>
                  <td>
                    <span className="flex items-center gap-2 text-sm">
                      <Switch
                        checked={method.active}
                        disabled={!method.active && method.issues.length > 0}
                        label={t('payments.active_of', { name: method.name })}
                        onChange={(active) =>
                          router.patch(
                            '/admin/payment-methods/' + method.code + '/active',
                            { version: method.version, active },
                            { preserveScroll: true },
                          )
                        }
                      />
                      <span
                        className={
                          method.active ? 'text-slate-700' : 'text-slate-500'
                        }
                      >
                        {t(
                          method.active
                            ? 'payments.active'
                            : 'payments.inactive',
                        )}
                      </span>
                    </span>
                  </td>
                </tr>
              ))}
              {rows.length === 0 && (
                <tr>
                  <td colSpan={5} className="py-6 text-center text-slate-500">
                    {t('payments.empty')}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </AdminLayout>
  );
}
