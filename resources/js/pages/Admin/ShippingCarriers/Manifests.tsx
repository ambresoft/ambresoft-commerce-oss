import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';

type Day = {
  lists: {
    number: string;
    issued_at: string | null;
    count: number | null;
    local: boolean;
  }[];
  shipments: {
    id: string;
    order_id: string;
    order_number: string;
    tracking_number: string | null;
    printed: boolean;
    manifest_number: string | null;
  }[];
  request_key: string;
};

export default function ShippingCarrierManifests({
  carrier,
  accounts,
  account,
  date,
  day,
  list,
  contents,
  error,
  ...shell
}: AdminShell & {
  carrier: { id: string; name: string; manifest_label: string };
  accounts: { id: string; name: string }[];
  account: string;
  date: string;
  day: Day | null;
  list: string | null;
  contents:
    | {
        tracking_number: string;
        order_id: string | null;
        order_number: string | null;
      }[]
    | null;
  error: string | null;
}) {
  const t = useTranslation();
  const { errors } = usePage<SharedProps & { errors: Record<string, string> }>()
    .props;
  const { date: dateFormat, time } = useDateDisplay();
  const [issuing, setIssuing] = useState(false);
  const base = '/admin/shipping-carriers/' + carrier.id + '/manifests';
  const go = (query: Record<string, string>) =>
    router.get(base, { account, date, ...query }, { preserveScroll: true });
  const pending =
    day?.shipments.filter((s) => s.manifest_number === null) ?? [];
  const unprinted = pending.filter((s) => !s.printed);
  const issue = () => {
    if (
      !day ||
      !window.confirm(
        t('shipping.manifest_issue_confirm', { count: pending.length }),
      )
    )
      return;
    setIssuing(true);
    router.post(
      base,
      { account, date, request_key: day.request_key },
      { preserveScroll: true, onFinish: () => setIssuing(false) },
    );
  };
  const printUrl = (number: string) =>
    base +
    '/' +
    encodeURIComponent(number) +
    '/print?' +
    new URLSearchParams({ account, date }).toString();

  return (
    <AdminLayout title={carrier.manifest_label} {...shell}>
      <EditorHeader
        heading={
          <>
            {carrier.manifest_label}· {carrier.name}
          </>
        }
        iconKey="shipping"
        backHref="/admin/shipping-carriers"
        backLabel={t('shipping.carriers_title')}
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('shipping.manifests_intro')}
            </p>
          </>
        }
      />
      <div className="mb-4 space-y-3">
        <FormErrors errors={errors ?? {}} />
        {error && (
          <p
            role="alert"
            className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
          >
            {error}
          </p>
        )}
      </div>
      {accounts.length === 0 ? (
        <p className="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-600">
          {t('shipping.manifests_no_accounts')}
        </p>
      ) : (
        <div className="space-y-5">
          <section className="grid gap-4 rounded-lg border border-slate-200 bg-white p-5 sm:grid-cols-3">
            <div className="space-y-2">
              <label
                className="block text-sm font-medium"
                htmlFor="manifest-account"
              >
                {t('shipping.account')}
              </label>
              <Select
                id="manifest-account"
                aria-label={t('shipping.account')}
                value={account}
                onChange={(value) => go({ account: value, list: '' })}
                options={accounts.map((a) => ({ value: a.id, label: a.name }))}
              />
            </div>
            <div className="space-y-2">
              <label
                className="block text-sm font-medium"
                htmlFor="manifest-date"
              >
                {t('shipping.pickup_date')}
              </label>
              <input
                id="manifest-date"
                type="date"
                className="field"
                value={date}
                onChange={(e) =>
                  e.target.value && go({ date: e.target.value, list: '' })
                }
              />
            </div>
          </section>

          {day && (
            <>
              <section className="space-y-4 rounded-lg border border-slate-200 bg-white p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h2 className="text-base font-semibold">
                      {t('shipping.manifest_pending')}
                    </h2>
                    <p className="mt-1 text-sm text-slate-500">
                      {t('shipping.manifest_pending_hint', {
                        count: pending.length,
                      })}
                    </p>
                  </div>
                  <button
                    type="button"
                    className="button-primary"
                    disabled={issuing || pending.length === 0}
                    onClick={issue}
                  >
                    {issuing ? t('common.wait') : t('shipping.manifest_issue')}
                  </button>
                </div>
                {unprinted.length > 0 && (
                  <p
                    role="status"
                    className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"
                  >
                    {t('shipping.manifest_unprinted', {
                      count: unprinted.length,
                    })}
                  </p>
                )}
                {day.shipments.length > 0 && (
                  <div className="overflow-x-auto">
                    <table className="data-table">
                      <thead>
                        <tr>
                          <th>{t('shipping.order')}</th>
                          <th>{t('shipping.tracking_number')}</th>
                          <th>{t('shipping.label')}</th>
                          <th>{t('shipping.manifest')}</th>
                        </tr>
                      </thead>
                      <tbody>
                        {day.shipments.map((s) => (
                          <tr key={s.id}>
                            <td className="text-sm">
                              <Link
                                className="hover:underline"
                                href={'/admin/orders/' + s.order_id}
                              >
                                {s.order_number}
                              </Link>
                            </td>
                            <td className="text-sm tabular-nums">
                              {s.tracking_number ?? '—'}
                            </td>
                            <td className="text-sm">
                              {s.printed ? (
                                t('shipping.label_printed')
                              ) : (
                                <span className="text-amber-700">
                                  {t('shipping.label_not_printed')}
                                </span>
                              )}
                            </td>
                            <td className="text-sm tabular-nums">
                              {s.manifest_number ?? '—'}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </section>

              <section>
                <AdminCard
                  title={t('shipping.manifest_lists')}
                  iconKey="manifest_lists"
                  bodyClassName="p-5 space-y-4"
                >
                  {day.lists.length === 0 ? (
                    <p className="text-sm text-slate-500">
                      {t('shipping.manifest_none')}
                    </p>
                  ) : (
                    <ul className="divide-y divide-slate-100">
                      {day.lists.map((m) => (
                        <li
                          key={m.number}
                          className="flex flex-wrap items-center justify-between gap-3 py-3"
                        >
                          <div className="text-sm">
                            <span className="font-medium tabular-nums">
                              {m.number}
                            </span>
                            <span className="ml-2 text-slate-500">
                              {m.issued_at &&
                                dateFormat.format(new Date(m.issued_at)) +
                                  ' ' +
                                  time.format(new Date(m.issued_at))}
                              {m.count !== null &&
                                ' · ' +
                                  t('shipping.manifest_count', {
                                    count: m.count,
                                  })}
                            </span>
                          </div>
                          <span className="flex gap-2">
                            <button
                              type="button"
                              className="button-secondary"
                              onClick={() => go({ list: m.number })}
                            >
                              {t('shipping.manifest_show')}
                            </button>
                            <a
                              className="button-secondary"
                              href={printUrl(m.number)}
                              target="_blank"
                              rel="noopener"
                            >
                              {t('shipping.manifest_print')}
                            </a>
                          </span>
                        </li>
                      ))}
                    </ul>
                  )}
                </AdminCard>
              </section>

              {list && contents && (
                <section>
                  <AdminCard
                    title={t('shipping.manifest_contents', { number: list })}
                    iconKey="manifest_contents"
                    bodyClassName="p-5 space-y-3"
                  >
                    {contents.length === 0 ? (
                      <p className="text-sm text-slate-500">
                        {t('shipping.manifest_contents_empty')}
                      </p>
                    ) : (
                      <ul className="grid gap-1 text-sm sm:grid-cols-2">
                        {contents.map((c) => (
                          <li key={c.tracking_number} className="tabular-nums">
                            {c.tracking_number}
                            {c.order_id && (
                              <>
                                {' · '}
                                <Link
                                  className="text-link"
                                  href={'/admin/orders/' + c.order_id}
                                >
                                  {c.order_number}
                                </Link>
                              </>
                            )}
                          </li>
                        ))}
                      </ul>
                    )}
                  </AdminCard>
                </section>
              )}
            </>
          )}
        </div>
      )}
    </AdminLayout>
  );
}
