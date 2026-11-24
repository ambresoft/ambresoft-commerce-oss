import { useEffect, useMemo, useRef, useState } from 'react';
import Select from './Select';
import { useTranslation } from '../../lib/useTranslation';

export type PricePoint = {
  at: string;
  price: string;
  list_price: string | null;
};
type Series = 'price' | 'list_price';
type Range = '1m' | '3m' | '6m' | 'all';
const ranges: { key: Range; months: number | null; label: string }[] = [
  { key: '1m', months: 1, label: 'products.price_history_1m' },
  { key: '3m', months: 3, label: 'products.price_history_3m' },
  { key: '6m', months: 6, label: 'products.price_history_6m' },
  { key: 'all', months: null, label: 'products.price_history_all' },
];
const HEIGHT = 280;
const M = { top: 16, right: 20, bottom: 32, left: 72 };

/** Round tick values covering [min, max]; equal values get a band around them. */
function niceTicks(min: number, max: number): number[] {
  if (min === max) {
    const pad = Math.abs(min) * 0.1 || 1;
    min -= pad;
    max += pad;
  }
  const rough = (max - min) / 4;
  const magnitude = 10 ** Math.floor(Math.log10(rough));
  const norm = rough / magnitude;
  const step = (norm < 1.5 ? 1 : norm < 3 ? 2 : norm < 7 ? 5 : 10) * magnitude;
  const ticks: number[] = [];
  for (
    let v = Math.max(0, Math.floor(min / step) * step);
    v <= Math.ceil(max / step) * step + step / 2;
    v += step
  )
    ticks.push(Number(v.toPrecision(12)));
  return ticks;
}

/**
 * Step chart of a product's saved price (or list price) over time: the value holds until the next recorded change.
 * Amounts are display-only numbers; the authoritative decimals stay on the server and in the table.
 */
export default function PriceHistoryChart({
  history,
  currency,
  locale,
}: {
  history: PricePoint[];
  currency: { code: string; decimals: number };
  locale: string;
}) {
  const t = useTranslation();
  const [range, setRange] = useState<Range>('1m');
  const [series, setSeries] = useState<Series>('price');
  const [hover, setHover] = useState<number | null>(null);
  const [now] = useState(() => Date.now());
  const [width, setWidth] = useState(720);
  const box = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const element = box.current;
    if (!element || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(([entry]) =>
      setWidth(Math.max(320, Math.round(entry.contentRect.width))),
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
      return (value: number) => format.format(value);
    } catch {
      return (value: number) =>
        value.toFixed(currency.decimals) + ' ' + currency.code;
    }
  }, [locale, currency]);
  const day = useMemo(
    () => new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' }),
    [locale],
  );
  const full = useMemo(
    () =>
      new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
      }),
    [locale],
  );

  const changes = history.map((point) => ({
    time: Date.parse(point.at),
    value: point[series] === null ? null : Number(point[series]),
  }));
  const first = changes[0]?.time ?? now;
  const months = ranges.find((r) => r.key === range)?.months ?? null;
  let start =
    months === null
      ? first
      : new Date(now).setMonth(new Date(now).getMonth() - months);
  if (start >= now) start = now - 86400000;
  const valueAt = (time: number): number | null => {
    let value: number | null = null;
    for (const change of changes) {
      if (change.time > time) break;
      value = change.value;
    }
    return value;
  };
  // Value carried into the window, every change inside it, then the window end.
  const events: { time: number; value: number | null }[] = [
    { time: start, value: valueAt(start) },
    ...changes.filter((c) => c.time > start && c.time <= now),
  ];
  const values = events
    .map((e) => e.value)
    .filter((v): v is number => v !== null);
  // Breathing room above and below so the lowest price never sits on the axis.
  const lowest = values.length ? Math.min(...values) : 0;
  const highest = values.length ? Math.max(...values) : 0;
  const pad = (highest - lowest) * 0.25 || Math.abs(highest) * 0.15 || 1;
  const ticks = values.length
    ? niceTicks(Math.max(0, lowest - pad), highest + pad)
    : [];
  const low = ticks[0] ?? 0;
  const high = ticks[ticks.length - 1] ?? 1;
  const plotWidth = width - M.left - M.right;
  const plotHeight = HEIGHT - M.top - M.bottom;
  const x = (time: number) =>
    M.left + ((time - start) / (now - start)) * plotWidth;
  const y = (value: number) =>
    M.top + plotHeight - ((value - low) / (high - low || 1)) * plotHeight;
  const base = M.top + plotHeight;

  // One line and one area path per uninterrupted run (a missing list price breaks the line).
  const runs: { line: string; area: string }[] = [];
  let line = '';
  let runStart = 0;
  events.forEach((event, index) => {
    const next = events[index + 1]?.time ?? now;
    if (event.value === null) {
      if (line) runs.push({ line, area: line + `V${base}H${runStart}Z` });
      line = '';
      return;
    }
    if (!line) {
      runStart = x(event.time);
      line = `M${runStart},${y(event.value)}`;
    } else line += `V${y(event.value)}`;
    line += `H${x(next)}`;
  });
  if (line) runs.push({ line, area: line + `V${base}H${runStart}Z` });
  const current = events[events.length - 1]?.value ?? null;
  const hovered = hover === null ? null : valueAt(hover);
  const tickCount = Math.max(2, Math.min(7, Math.floor(plotWidth / 110)));
  const xTicks = Array.from(
    { length: tickCount },
    (_, i) => start + ((now - start) * i) / (tickCount - 1),
  );
  const rows = [...history].reverse();
  const seriesLabel = t(
    series === 'price'
      ? 'products.price_history_price'
      : 'products.price_history_list_price',
  );

  return (
    <section aria-labelledby="price-history-title" className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4">
        <h3 id="price-history-title" className="text-base font-semibold">
          {t('products.price_history_title')}
        </h3>
        <Select
          className="w-auto"
          aria-label={t('products.price_history_series')}
          value={series}
          onChange={(value) => setSeries(value as Series)}
          options={[
            { value: 'price', label: t('products.price_history_price') },
            {
              value: 'list_price',
              label: t('products.price_history_list_price'),
            },
          ]}
        />
      </div>
      <p className="text-xs leading-5 text-slate-500">
        {t('products.price_history_hint')}
      </p>
      <div ref={box} className="relative">
        {values.length === 0 ? (
          <p className="rounded-md bg-slate-50 p-6 text-center text-sm text-slate-600">
            {t(
              history.length === 0
                ? 'products.price_history_empty'
                : 'products.price_history_none_in_range',
            )}
          </p>
        ) : (
          <svg
            width={width}
            height={HEIGHT}
            viewBox={`0 0 ${width} ${HEIGHT}`}
            role="img"
            aria-label={t('products.price_history_summary', {
              series: seriesLabel,
              value: current === null ? '—' : money(current),
            })}
            className="block max-w-full touch-none"
            onPointerMove={(event) => {
              const bounds = event.currentTarget.getBoundingClientRect();
              const scale = bounds.width ? width / bounds.width : 1;
              const ratio =
                ((event.clientX - bounds.left) * scale - M.left) / plotWidth;
              setHover(
                ratio < 0 || ratio > 1 ? null : start + ratio * (now - start),
              );
            }}
            onPointerLeave={() => setHover(null)}
          >
            {ticks.map((tick) => (
              <g key={tick}>
                <line
                  x1={M.left}
                  x2={width - M.right}
                  y1={y(tick)}
                  y2={y(tick)}
                  stroke="var(--color-divider)"
                />
                <text
                  x={M.left - 10}
                  y={y(tick)}
                  textAnchor="end"
                  dominantBaseline="middle"
                  className="fill-slate-500 text-xs tabular-nums"
                >
                  {money(tick)}
                </text>
              </g>
            ))}
            {xTicks.map((tick, index) => (
              <text
                key={tick}
                x={x(tick)}
                y={HEIGHT - 10}
                textAnchor={
                  index === 0
                    ? 'start'
                    : index === xTicks.length - 1
                      ? 'end'
                      : 'middle'
                }
                className="fill-slate-500 text-xs"
              >
                {day.format(tick)}
              </text>
            ))}
            {runs.map((run, index) => (
              <g key={index}>
                <path
                  d={run.area}
                  fill="var(--color-primary)"
                  fillOpacity={0.1}
                />
                <path
                  d={run.line}
                  fill="none"
                  stroke="var(--color-primary)"
                  strokeWidth={2}
                  strokeLinejoin="round"
                  strokeLinecap="round"
                />
              </g>
            ))}
            {current !== null && (
              <circle
                cx={x(now)}
                cy={y(current)}
                r={4}
                fill="var(--color-primary)"
                stroke="var(--color-surface)"
                strokeWidth={2}
              />
            )}
            {hover !== null && (
              <g pointerEvents="none">
                <line
                  x1={x(hover)}
                  x2={x(hover)}
                  y1={M.top}
                  y2={base}
                  stroke="var(--color-muted)"
                />
                {hovered !== null && (
                  <circle
                    cx={x(hover)}
                    cy={y(hovered)}
                    r={4}
                    fill="var(--color-primary)"
                    stroke="var(--color-surface)"
                    strokeWidth={2}
                  />
                )}
              </g>
            )}
          </svg>
        )}
        {values.length > 0 && current !== null && hover === null && (
          <span
            className="pointer-events-none absolute rounded-md border border-primary bg-white px-2 py-0.5 text-xs font-semibold tabular-nums text-slate-800 shadow-sm"
            style={{
              right: M.right,
              top: Math.min(y(current) + 10, base - 26),
            }}
          >
            {money(current)}
          </span>
        )}
        {hover !== null && (
          <div
            role="status"
            className="pointer-events-none absolute top-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs shadow-md"
            style={
              x(hover) > width / 2
                ? { right: width - x(hover) + 12 }
                : { left: x(hover) + 12 }
            }
          >
            <p className="text-slate-500">{full.format(hover)}</p>
            <p className="mt-1 flex items-center gap-2 font-semibold tabular-nums text-slate-800">
              <span
                aria-hidden
                className="inline-block h-0.5 w-3 rounded bg-primary"
              />
              {seriesLabel}: {hovered === null ? '—' : money(hovered)}
            </p>
          </div>
        )}
      </div>
      <div
        role="group"
        aria-label={t('products.price_history_range')}
        className="flex flex-wrap justify-center gap-2"
      >
        {ranges.map((item) => (
          <button
            key={item.key}
            type="button"
            aria-pressed={range === item.key}
            className={
              'min-w-24 rounded-md border px-4 py-2 text-sm font-medium ' +
              (range === item.key
                ? 'border-primary bg-primary-tint text-emerald-900'
                : 'border-slate-200 text-slate-700 hover:bg-slate-100')
            }
            onClick={() => setRange(item.key)}
          >
            {t(item.label)}
          </button>
        ))}
      </div>
      {rows.length > 0 && (
        <div className="max-h-80 overflow-auto rounded-md border border-slate-200">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">
              {t('products.price_history_table')}
            </caption>
            <thead className="sticky top-0 bg-slate-50 text-xs text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">
                  {t('products.price_history_date')}
                </th>
                <th className="px-3 py-2 text-right font-medium">
                  {t('products.price_history_price')}
                </th>
                <th className="px-3 py-2 text-right font-medium">
                  {t('products.price_history_list_price')}
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row, index) => (
                <tr key={row.at + index} className="border-t border-slate-200">
                  <td className="px-3 py-2">
                    {full.format(Date.parse(row.at))}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {money(Number(row.price))}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {row.list_price === null
                      ? '—'
                      : money(Number(row.list_price))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
