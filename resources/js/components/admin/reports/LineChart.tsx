import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { Currency, SeriesPoint } from '../../../types/reports';

const HEIGHT = 240;
const M = { top: 16, right: 16, bottom: 28, left: 64 };

/** Round tick values covering [0, max]. */
function ticks(max: number): number[] {
  if (max <= 0) return [0, 1];
  const rough = max / 4;
  const magnitude = 10 ** Math.floor(Math.log10(rough));
  const norm = rough / magnitude;
  const step = (norm < 1.5 ? 1 : norm < 3 ? 2 : norm < 7 ? 5 : 10) * magnitude;
  const result: number[] = [];
  for (let v = 0; ; v += step) {
    result.push(Number(v.toPrecision(12)));
    if (v >= max) break;
  }
  return result;
}

/**
 * Line chart of one measure over time buckets, with the previous equivalent period as a dashed line when comparing.
 * `metric` picks money (`net`, shown in the report currency) or counts (`orders`). Values are display numbers only;
 * the exact decimals stay on the server and in the tables.
 */
export default function LineChart({
  title,
  points,
  previous,
  metric,
  currency,
  locale,
  granularity,
}: {
  title: string;
  points: SeriesPoint[];
  previous: SeriesPoint[] | null;
  metric: 'net' | 'orders';
  currency: Currency;
  locale: string;
  granularity: 'hour' | 'day' | 'week' | 'month';
}) {
  const t = useTranslation();
  const box = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(640);
  const [hover, setHover] = useState<number | null>(null);
  useEffect(() => {
    const element = box.current;
    if (!element || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(([entry]) =>
      setWidth(Math.max(280, Math.round(entry.contentRect.width))),
    );
    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  const money = useMemo(() => {
    try {
      const format = new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currency.code,
        minimumFractionDigits: currency.decimals,
        maximumFractionDigits: currency.decimals,
      });
      return (v: number) => format.format(v);
    } catch {
      return (v: number) => v.toFixed(currency.decimals) + ' ' + currency.code;
    }
  }, [locale, currency]);
  const count = useMemo(() => new Intl.NumberFormat(locale), [locale]);
  const fmt = (v: number) => (metric === 'net' ? money(v) : count.format(v));
  const value = (p: SeriesPoint) => Number(metric === 'net' ? p.net : p.orders);
  const label = useMemo(() => {
    const options: Intl.DateTimeFormatOptions =
      granularity === 'hour'
        ? { hour: '2-digit', minute: '2-digit' }
        : granularity === 'month'
          ? { month: 'short', year: 'numeric' }
          : { day: 'numeric', month: 'short' };
    // Bucket instants carry the store's offset; the local clock part is read from the text so the store's day shows.
    const format = new Intl.DateTimeFormat(locale, {
      ...options,
      timeZone: 'UTC',
    });
    return (at: string) => format.format(new Date(at.slice(0, 19) + 'Z'));
  }, [locale, granularity]);

  const values = points.map(value);
  const prior = previous ? previous.map(value) : [];
  const max = Math.max(0, ...values, ...prior);
  const scale = ticks(max);
  const top = scale[scale.length - 1] || 1;
  const innerW = width - M.left - M.right;
  const innerH = HEIGHT - M.top - M.bottom;
  const x = (i: number, n: number) =>
    M.left + (n <= 1 ? innerW / 2 : (i / (n - 1)) * innerW);
  const y = (v: number) => M.top + innerH - (v / top) * innerH;
  const path = (list: number[]) =>
    list
      .map((v, i) => (i ? 'L' : 'M') + x(i, list.length) + ' ' + y(v))
      .join('');
  const step = Math.max(1, Math.ceil(points.length / 7));

  return (
    <figure className="space-y-2" aria-label={title}>
      <figcaption className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="text-sm font-semibold">{title}</span>
        <span className="text-xs text-slate-500">
          {metric === 'net' ? currency.code : t('reports.unit_orders')}
          {previous && (
            <span className="ml-3 inline-flex items-center gap-1">
              <span className="inline-block h-0.5 w-4 bg-slate-400" />
              {t('reports.previous_period')}
            </span>
          )}
        </span>
      </figcaption>
      <div ref={box} className="relative">
        <svg
          width={width}
          height={HEIGHT}
          role="img"
          aria-label={title}
          onMouseLeave={() => setHover(null)}
          onMouseMove={(e) => {
            const rect = e.currentTarget.getBoundingClientRect();
            const ratio = (e.clientX - rect.left - M.left) / innerW;
            setHover(
              Math.min(
                points.length - 1,
                Math.max(0, Math.round(ratio * (points.length - 1))),
              ),
            );
          }}
        >
          {scale.map((v) => (
            <g key={v}>
              <line
                x1={M.left}
                x2={width - M.right}
                y1={y(v)}
                y2={y(v)}
                stroke="currentColor"
                className="text-slate-200"
              />
              <text
                x={M.left - 8}
                y={y(v) + 4}
                textAnchor="end"
                className="fill-slate-500 text-[11px]"
              >
                {count.format(v)}
              </text>
            </g>
          ))}
          {points.map((p, i) =>
            i % step === 0 ? (
              <text
                key={p.at}
                x={x(i, points.length)}
                y={HEIGHT - 8}
                textAnchor="middle"
                className="fill-slate-500 text-[11px]"
              >
                {label(p.at)}
              </text>
            ) : null,
          )}
          {previous && prior.length > 0 && (
            <path
              d={path(prior)}
              fill="none"
              stroke="currentColor"
              strokeWidth={2}
              strokeDasharray="4 4"
              className="text-slate-400"
            />
          )}
          <path
            d={path(values)}
            fill="none"
            stroke="currentColor"
            strokeWidth={2.5}
            className="text-emerald-600"
          />
          {hover !== null && points[hover] && (
            <g>
              <line
                x1={x(hover, points.length)}
                x2={x(hover, points.length)}
                y1={M.top}
                y2={M.top + innerH}
                stroke="currentColor"
                className="text-slate-300"
              />
              <circle
                cx={x(hover, points.length)}
                cy={y(values[hover])}
                r={4}
                className="fill-emerald-600"
              />
            </g>
          )}
        </svg>
        {max === 0 && (
          <p className="absolute inset-0 flex items-center justify-center text-sm text-slate-500">
            {t('reports.chart_empty')}
          </p>
        )}
        {hover !== null && points[hover] && (
          <div
            role="status"
            className="pointer-events-none absolute top-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs shadow-sm"
            style={{
              left: Math.min(
                width - 190,
                Math.max(0, x(hover, points.length) - 70),
              ),
            }}
          >
            <p className="font-medium">{label(points[hover].at)}</p>
            <p className="tabular-nums">{fmt(values[hover])}</p>
            {previous?.[hover] && (
              <p className="text-slate-500 tabular-nums">
                {t('reports.previous_period')}: {fmt(prior[hover])}
              </p>
            )}
          </div>
        )}
      </div>
    </figure>
  );
}
