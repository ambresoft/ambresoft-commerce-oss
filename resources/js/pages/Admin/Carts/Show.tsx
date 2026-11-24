import Card from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import { usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { CartDetail } from '../../../types/carts';
import type { SharedProps } from '../../../types/page';

/** One cart: its owner, its products priced as the storefront shows them to that owner now, and the total. */
export default function CartShow({
  cart,
  ...shell
}: AdminShell & { cart: CartDetail }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const { date, time } = useDateDisplay();
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const title = t('carts.cart_number', { id: cart.id });
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="bought_together"
        backHref="/admin/carts"
        backLabel={t('carts.title')}
        description={
          <>
            <p className="mt-1 font-mono text-xs text-slate-500">
              {t('carts.uuid')}: {cart.uuid}
            </p>
          </>
        }
      />
      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <Card id="cart-products" title={t('carts.lines_title')} icon="box">
          <div className="relative max-w-full overflow-x-auto">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('carts.product')}</th>
                  <th className="text-right">{t('carts.price')}</th>
                  <th className="text-right">{t('carts.quantity')}</th>
                  <th className="text-right">{t('carts.total')}</th>
                </tr>
              </thead>
              <tbody>
                {cart.items.map((item) => (
                  <tr key={item.id}>
                    <td className="text-sm">
                      <div className="flex items-center gap-3">
                        <span className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-white">
                          {item.image && (
                            <img
                              src={item.image.url}
                              alt=""
                              className="max-h-full max-w-full object-contain"
                            />
                          )}
                        </span>
                        <span className="min-w-0">
                          {capabilities['products.view'] ? (
                            <a
                              href={'/admin/products/' + item.id + '/edit'}
                              className="block max-w-72 truncate font-medium hover:underline"
                            >
                              {item.name}
                            </a>
                          ) : (
                            <span className="block max-w-72 truncate font-medium">
                              {item.name}
                            </span>
                          )}
                          {item.sku && (
                            <span className="block text-xs text-slate-500">
                              {item.sku}
                            </span>
                          )}
                          {item.problem && (
                            <span className="mt-0.5 block text-xs text-red-700">
                              {item.problem}
                            </span>
                          )}
                        </span>
                      </div>
                    </td>
                    <td className="text-right text-sm whitespace-nowrap tabular-nums">
                      {item.price ?? '—'}
                    </td>
                    <td className="text-right text-sm tabular-nums">
                      {item.quantity}
                    </td>
                    <td className="text-right text-sm font-semibold whitespace-nowrap tabular-nums">
                      {item.total ?? '—'}
                    </td>
                  </tr>
                ))}
                {cart.items.length === 0 && cart.hidden.length === 0 && (
                  <tr>
                    <td
                      colSpan={4}
                      className="py-10 text-center text-slate-500"
                    >
                      {t('carts.no_lines')}
                    </td>
                  </tr>
                )}
              </tbody>
              {cart.items.length > 0 && (
                <tfoot>
                  <tr>
                    <th colSpan={3} className="text-right text-sm">
                      {t('carts.subtotal')}
                    </th>
                    <td className="text-right text-base font-semibold whitespace-nowrap tabular-nums">
                      {cart.subtotal}
                    </td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
          <p className="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
            {t('carts.prices_note')}
          </p>
          {cart.hidden.length > 0 && (
            <div className="border-t border-slate-200 px-5 py-4">
              <h3 className="text-sm font-semibold">
                {t('carts.hidden_title')}
              </h3>
              <p className="mt-1 text-xs text-slate-500">
                {t('carts.hidden_help')}
              </p>
              <ul className="mt-3 space-y-1 text-sm">
                {cart.hidden.map((item) => (
                  <li key={item.id} className="flex justify-between gap-3">
                    <span className="truncate">
                      {item.name}
                      {item.sku && (
                        <span className="ml-2 text-xs text-slate-500">
                          {item.sku}
                        </span>
                      )}
                    </span>
                    <span className="tabular-nums">×{item.quantity}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </Card>
        <aside>
          <Card id="cart-owner" title={t('carts.owner')} icon="user">
            <div className="space-y-4 text-sm">
              <div>
                {cart.customer ? (
                  capabilities['customers.view'] ? (
                    <a
                      href={'/admin/customers/' + cart.customer.id}
                      className="font-medium hover:underline"
                    >
                      {cart.customer.name}
                    </a>
                  ) : (
                    <p className="font-medium">{cart.customer.name}</p>
                  )
                ) : (
                  <StatusBadge tone="neutral">{t('carts.guest')}</StatusBadge>
                )}
                {cart.customer?.email && (
                  <p className="text-xs text-slate-500">
                    {cart.customer.email}
                  </p>
                )}
              </div>
              <div>
                <p className="text-xs text-slate-500">{t('carts.col_lines')}</p>
                <p>
                  {cart.lines} · {t('carts.col_units')}: {cart.units}
                </p>
              </div>
              <div>
                <p className="text-xs text-slate-500">{t('carts.created')}</p>
                <p>{when(cart.created_at)}</p>
              </div>
              <div>
                <p className="text-xs text-slate-500">{t('carts.updated')}</p>
                <p>{when(cart.updated_at)}</p>
              </div>
            </div>
          </Card>
        </aside>
      </div>
    </AdminLayout>
  );
}
