import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { CurrencyRow, PrimaryCurrency } from '../../../types/currencies';
import type { SharedProps } from '../../../types/page';
import IconButton from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';

export default function Currencies({
  currencies,
  primary,
  ...shell
}: AdminShell & { currencies: CurrencyRow[]; primary: PrimaryCurrency }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  return (
    <AdminLayout title={t('currencies.title')} {...shell}>
      <EditorHeader
        heading={t('currencies.title')}
        iconKey="currency"
        description={
          <>
            <p className="mt-2 max-w-3xl text-sm text-slate-600">
              {t('currencies.primary_help', primary)}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['currencies.create'] && (
              <Link
                className="button-primary"
                href="/admin/settings/currencies/create"
              >
                <Icon name="plus" className="size-5 shrink-0" />
                {t('currencies.create')}
              </Link>
            )}
          </>
        }
      />
      <section className="list-card" aria-label={t('currencies.title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('currencies.name')}</th>
                <th>{t('currencies.symbol')}</th>
                <th className="text-right">{t('currencies.rate')}</th>
                <th className="text-right">{t('currencies.sample')}</th>
                <th>{t('currencies.status')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {currencies.map((c) => (
                <tr key={c.id}>
                  <td>
                    <p className="font-semibold">{c.name}</p>
                    <p className="mt-0.5 font-mono text-xs text-slate-500">
                      {c.code}
                    </p>
                  </td>
                  <td>{c.symbol}</td>
                  <td className="text-right font-mono whitespace-nowrap tabular-nums">
                    {t('currencies.rate_value', {
                      primary: primary.code,
                      rate: c.rate,
                      code: c.code,
                    })}
                  </td>
                  <td className="text-right font-semibold whitespace-nowrap tabular-nums">
                    {c.sample}
                  </td>
                  <td>
                    <div className="flex flex-wrap gap-1.5">
                      {c.primary && (
                        <StatusBadge tone="dark">
                          {t('currencies.primary')}
                        </StatusBadge>
                      )}
                      <StatusBadge tone={c.active ? 'success' : 'neutral'}>
                        {t(
                          c.active
                            ? 'currencies.active'
                            : 'currencies.disabled',
                        )}
                      </StatusBadge>
                    </div>
                  </td>
                  <td>
                    <div className="flex justify-end">
                      <IconButton
                        icon="edit"
                        href={'/admin/settings/currencies/' + c.id + '/edit'}
                        label={t('currencies.edit') + ' ' + c.code}
                      />
                    </div>
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
