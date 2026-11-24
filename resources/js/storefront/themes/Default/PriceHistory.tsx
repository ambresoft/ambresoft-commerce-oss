import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { CurrencyFormat } from '../../types';
import { Money } from './parts';

type Point = { at: string; price: string };
const RANGES = [
  { key: '1m', months: 1, label: 'storefront.history_1m' },
  { key: '3m', months: 3, label: 'storefront.history_3m' },
  { key: '6m', months: 6, label: 'storefront.history_6m' },
  { key: 'all', months: null, label: 'storefront.history_all' },
] as const;
const W = 720;
const H = 300;
const M = { top: 20, right: 24, bottom: 36, left: 64 };

/** The price over time in a dialog, drawn as an area chart. Coordinates are for drawing only; labels use the exact amounts. */
export default function PriceHistory({
  points,
  current,
  format,
  onClose,
}: {
  points: Point[];
  current: string | null;
  format: CurrencyFormat;
  onClose: () => void;
}) {
  const t = useTranslation();
  const dialog = useRef<HTMLDialogElement>(null);
  const [range, setRange] = useState<(typeof RANGES)[number]['key']>('3m');
  // The moment the dialog opened: the chart ends there.
  const [now] = useState(() => Date.now());
  useEffect(() => {
    dialog.current?.showModal();
  }, []);
  const series = useMemo(() => {
    const months = RANGES.find((r) => r.key === range)?.months ?? null;
    const start = months === null ? null : now - months * 30.44 * 86400000;
    const all = points.map((p) => ({ time: Date.parse(p.at), price: p.price }));
    // The price in force at the start of the range carries over from the last earlier change.
    const before = start === null ? [] : all.filter((p) => p.time < start);
    const inside = all.filter((p) => start === null || p.time >= start);
    const head =
      start !== null && before.length > 0
        ? [{ time: start, price: before[before.length - 1].price }]
        : [];
    const list = [...head, ...inside];
    const last = list[list.length - 1] ?? all[all.length - 1];
    if (last) list.push({ time: now, price: current ?? last.price });
    return list;
  }, [points, range, current, now]);
  const values = series.map((p) => Number(p.price));
  const min = Math.min(...values);
  const max = Math.max(...values);
  const pad = (max - min) * 0.15 || max * 0.05 || 1;
  const low = Math.max(0, min - pad);
  const high = max + pad;
  const t0 = series[0]?.time ?? 0;
  const t1 = series[series.length - 1]?.time ?? 1;
  const x = (time: number) =>
    M.left + ((time - t0) / Math.max(1, t1 - t0)) * (W - M.left - M.right);
  const y = (value: number) =>
    M.top + (1 - (value - low) / (high - low)) * (H - M.top - M.bottom);
  // Prices stay level until they change: a step line.
  const path = series
    .map((p, n) => {
      const px = x(p.time);
      const py = y(Number(p.price));
      return n === 0 ? `M${px},${py}` : `H${px}V${py}`;
    })
    .join('');
  const area = `${path}V${H - M.bottom}H${M.left}Z`;
  const ticks = [0, 1, 2, 3].map((n) => low + ((high - low) * n) / 3);
  const label = (value: number) =>
    `${format.position === 'before' ? format.symbol : ''}${Math.round(value).toLocaleString()}${format.position === 'after' ? ` ${format.symbol}` : ''}`;
  const days = Math.max(1, (t1 - t0) / 86400000);
  const dates = [0, 1, 2, 3, 4].map((n) => t0 + ((t1 - t0) * n) / 4);
  const last = series[series.length - 1];
  return (
    <dialog
      ref={dialog}
      className="sf-pdp-history"
      aria-labelledby="sf-pdp-history-title"
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      onClick={(e) => e.target === dialog.current && onClose()}
    >
      <div className="sf-pdp-history-head">
        <h2 id="sf-pdp-history-title">{t('storefront.price_history')}</h2>
        <button
          type="button"
          className="sf-pdp-history-close"
          aria-label={t('storefront.close')}
          onClick={onClose}
        >
          ×
        </button>
      </div>
      {series.length > 0 && last && (
        <svg
          viewBox={`0 0 ${W} ${H}`}
          role="img"
          aria-label={t('storefront.price_history')}
        >
          <defs>
            <linearGradient id="sf-history-fill" x1="0" y1="0" x2="0" y2="1">
              <stop
                offset="0%"
                stopColor="var(--sf-primary, #05ab7d)"
                stopOpacity="0.35"
              />
              <stop
                offset="100%"
                stopColor="var(--sf-primary, #05ab7d)"
                stopOpacity="0"
              />
            </linearGradient>
          </defs>
          {ticks.map((v) => (
            <g key={v}>
              <line
                x1={M.left}
                x2={W - M.right}
                y1={y(v)}
                y2={y(v)}
                className="sf-history-grid"
              />
              <text
                x={M.left - 10}
                y={y(v) + 4}
                textAnchor="end"
                className="sf-history-axis"
              >
                {label(v)}
              </text>
            </g>
          ))}
          {dates.map((time) => (
            <text
              key={time}
              x={x(time)}
              y={H - 10}
              textAnchor="middle"
              className="sf-history-axis"
            >
              {new Date(time).toLocaleDateString(undefined, {
                day: 'numeric',
                month: 'short',
                ...(days > 300 ? { year: '2-digit' } : {}),
              })}
            </text>
          ))}
          <path d={area} fill="url(#sf-history-fill)" />
          <path d={path} className="sf-history-line" />
          <circle
            cx={x(last.time)}
            cy={y(Number(last.price))}
            r="5"
            className="sf-history-dot"
          />
        </svg>
      )}
      {last && (
        <p className="sf-pdp-history-now">
          {t('storefront.history_current')}{' '}
          <Money amount={last.price} format={format} inline />
        </p>
      )}
      <div
        className="sf-pdp-history-ranges"
        role="group"
        aria-label={t('storefront.price_history')}
      >
        {RANGES.map((r) => (
          <button
            key={r.key}
            type="button"
            aria-pressed={range === r.key}
            onClick={() => setRange(r.key)}
          >
            {t(r.label)}
          </button>
        ))}
      </div>
    </dialog>
  );
}
