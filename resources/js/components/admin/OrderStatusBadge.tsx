import type { OrderStatusOption } from '../../types/orders';
import { useTranslation } from '../../lib/useTranslation';

/** Merchant-configured status: its name with a restrained colour dot; never colour alone. */
export default function OrderStatusBadge({
  status,
}: {
  status: OrderStatusOption | null;
}) {
  const t = useTranslation();
  if (!status) return <span className="text-slate-400">—</span>;
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-2.5 py-0.5 text-xs font-medium whitespace-nowrap text-slate-700">
      <span
        aria-hidden
        className="inline-block size-2 rounded-full"
        style={{ backgroundColor: status.color }}
      />
      {status.name}
      {!status.active && (
        <span className="text-slate-400">· {t('orders.status_inactive')}</span>
      )}
    </span>
  );
}
