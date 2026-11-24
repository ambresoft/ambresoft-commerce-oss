import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import { useTranslation } from '../../../lib/useTranslation';
import { Icon } from '../../../components/admin/list/IconButton';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';

type CarrierRow = {
  id: string;
  name: string;
  accounts: number;
  manifests: boolean;
};

export default function ShippingCarriersIndex({
  carriers,
  ...shell
}: AdminShell & { carriers: CarrierRow[] }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const canManage = capabilities['shipping-carriers.manage'];
  const canShip = capabilities['orders.shipments'];

  return (
    <AdminLayout title={t('shipping.carriers_title')} {...shell}>
      <EditorHeader
        heading={t('shipping.carriers_title')}
        iconKey="shipping"
        description={
          <>
            <p className="mt-2 text-sm leading-6 text-slate-500">
              {t('shipping.carriers_intro')}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['addons.manage'] && (
              <Link
                className="button-secondary inline-flex items-center gap-2"
                href="/admin/addons"
              >
                <Icon name="puzzle" />
                {t('shipping.carriers_addons')}
              </Link>
            )}
          </>
        }
      />
      {carriers.length === 0 ? (
        <section className="flex flex-col items-center rounded-xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center text-sm text-slate-600">
          <span className="mb-5 flex size-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
            <Icon name="truck" className="size-7" />
          </span>
          <p>{t('shipping.carriers_empty')}</p>
        </section>
      ) : (
        <section aria-labelledby="available-carriers">
          <div className="mb-4 flex items-center gap-3">
            <h2
              id="available-carriers"
              className="text-sm font-semibold text-slate-700"
            >
              {t('shipping.carriers_available')}
            </h2>
            <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
              {carriers.length}
            </span>
          </div>
          <ul className="grid gap-5 lg:grid-cols-2 2xl:grid-cols-3">
            {carriers.map((carrier) => (
              <li
                key={carrier.id}
                className="flex min-w-0 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
              >
                <div className="flex-1 p-6">
                  <div className="flex items-center gap-4">
                    <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary-tint text-primary-dark">
                      <Icon name="truck" className="size-6" />
                    </span>
                    <h3 className="min-w-0 text-lg font-semibold tracking-tight">
                      {carrier.name}
                    </h3>
                  </div>
                  <p className="mt-5 inline-flex items-center gap-2 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                    <Icon name="user" className="size-3.5" />
                    {t('shipping.accounts_count', { count: carrier.accounts })}
                  </p>
                  <p className="mt-3 text-sm leading-6 text-slate-500">
                    {t(
                      carrier.accounts === 0
                        ? 'shipping.carrier_no_accounts_help'
                        : 'shipping.carrier_accounts_help',
                    )}
                  </p>
                </div>
                <div className="mt-auto flex flex-wrap items-center gap-3 border-t border-slate-100 bg-slate-50/50 px-6 py-4">
                  {canManage && (
                    <Link
                      className="button-primary inline-flex items-center gap-2"
                      href={'/admin/shipping-carriers/' + carrier.id}
                    >
                      <Icon name="settings" />
                      {t('shipping.carrier_configure')}
                    </Link>
                  )}
                  {canShip && carrier.manifests && (
                    <Link
                      className="button-secondary inline-flex items-center gap-2"
                      href={
                        '/admin/shipping-carriers/' + carrier.id + '/manifests'
                      }
                    >
                      <Icon name="note" />
                      {t('shipping.manifests_title')}
                    </Link>
                  )}
                </div>
              </li>
            ))}
          </ul>
        </section>
      )}
    </AdminLayout>
  );
}
