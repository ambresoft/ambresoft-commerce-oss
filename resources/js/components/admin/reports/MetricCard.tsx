import { useTranslation } from '../../../lib/useTranslation';

/**
 * One headline figure with an optional change against the previous period. The change is shown only when the previous
 * period has data, so a first month never reads as a meaningless percentage; a missing value shows "not available".
 */
export default function MetricCard({
  label,
  value,
  hint,
  current,
  previous,
}: {
  label: string;
  value: string | null;
  hint?: string;
  current?: number;
  previous?: number | null;
}) {
  const t = useTranslation();
  const change =
    previous !== null &&
    previous !== undefined &&
    current !== undefined &&
    previous > 0
      ? ((current - previous) / previous) * 100
      : null;
  return (
    <div
      className="rounded-lg border border-slate-200 bg-white p-4"
      title={hint}
    >
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-semibold tabular-nums">
        {value ?? (
          <span className="text-base font-normal text-slate-400">
            {t('reports.not_available')}
          </span>
        )}
      </p>
      {change !== null && (
        <p
          className={
            'mt-1 text-xs tabular-nums ' +
            (change >= 0 ? 'text-emerald-700' : 'text-red-700')
          }
        >
          {(change >= 0 ? '▲ ' : '▼ ') + Math.abs(change).toFixed(1) + '%'}
          <span className="ml-1 text-slate-500">
            {t('reports.vs_previous')}
          </span>
        </p>
      )}
    </div>
  );
}
