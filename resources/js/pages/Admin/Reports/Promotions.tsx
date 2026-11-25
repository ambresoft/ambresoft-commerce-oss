import { Link, router } from '@inertiajs/react';
import Pagination from '../../../components/admin/Pagination';
import ReportShell from '../../../components/admin/reports/ReportShell';
import { useReportUrl } from '../../../lib/reportQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { ReportCommon } from '../../../types/reports';

type Row = {
  coupon_id: string;
  code: string;
  redemptions: number;
  discount: string;
  shipping_discount: string;
  sales_orders: number;
  net: string;
  total: string;
};
type Props = AdminShell &
  ReportCommon & { promotions: Page<Row>; released: number };

export default function ReportsPromotions(props: Props) {
  const t = useTranslation();
  const { params, href } = useReportUrl();
  const { navigation, breadcrumbs } = props;
  return (
    <ReportShell
      common={props}
      title={t('reports.promotions_title')}
      description={t('reports.promotions_description')}
      shell={{ navigation, breadcrumbs }}
    >
      <section className="list-card" aria-label={t('reports.promotions_title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('reports.coupon')}</th>
                <th className="text-right">{t('reports.redemptions')}</th>
                <th className="text-right">{t('reports.discount_granted')}</th>
                <th className="text-right">
                  {t('reports.shipping_discounts')}
                </th>
                <th className="text-right">{t('reports.paid_orders')}</th>
                <th className="text-right">{t('reports.net_sales')}</th>
                <th className="text-right">{t('reports.total_charged')}</th>
              </tr>
            </thead>
            <tbody>
              {props.promotions.data.length === 0 && (
                <tr>
                  <td colSpan={7} className="py-10 text-center text-slate-500">
                    {t('reports.no_promotions')}
                  </td>
                </tr>
              )}
              {props.promotions.data.map((r) => (
                <tr key={r.coupon_id + r.code}>
                  <td>
                    <Link
                      className="font-medium hover:underline"
                      href={'/admin/coupons/' + r.coupon_id + '/edit'}
                    >
                      {r.code}
                    </Link>
                  </td>
                  <td className="text-right tabular-nums">{r.redemptions}</td>
                  <td className="text-right tabular-nums">{r.discount}</td>
                  <td className="text-right tabular-nums">
                    {r.shipping_discount}
                  </td>
                  <td className="text-right tabular-nums">{r.sales_orders}</td>
                  <td className="text-right tabular-nums">{r.net}</td>
                  <td className="text-right tabular-nums">{r.total}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination
          page={props.promotions}
          perPage={{
            value: params.get('per_page') ?? '25',
            options: ['25', '50', '100'],
            onChange: (v) =>
              router.get(href({ per_page: v }), {}, { preserveScroll: true }),
          }}
        />
      </section>
      {props.released > 0 && (
        <p className="mt-4 text-sm text-slate-600">
          {t('reports.released_note', { count: props.released })}
        </p>
      )}
      <p className="mt-2 text-xs text-slate-500">
        {t('reports.promotions_note')}
      </p>
    </ReportShell>
  );
}
