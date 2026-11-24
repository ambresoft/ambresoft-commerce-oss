import type { ReactNode } from 'react';

export type Bar = {
  key: string;
  label: ReactNode;
  value: number;
  text: string;
};

/** Horizontal bars for a ranking: the bar length is the value relative to the largest, the text is the exact figure. */
export default function BarList({
  title,
  bars,
  empty,
}: {
  title: string;
  bars: Bar[];
  empty: string;
}) {
  const max = Math.max(0, ...bars.map((b) => b.value));
  return (
    <section aria-label={title} className="space-y-3">
      <h3 className="text-sm font-semibold">{title}</h3>
      {bars.length === 0 ? (
        <p className="text-sm text-slate-500">{empty}</p>
      ) : (
        <ul className="space-y-2.5">
          {bars.map((bar) => (
            <li key={bar.key} className="space-y-1">
              <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="min-w-0 truncate">{bar.label}</span>
                <span className="shrink-0 tabular-nums text-slate-600">
                  {bar.text}
                </span>
              </div>
              <div className="h-1.5 rounded-full bg-slate-100">
                <div
                  className="h-1.5 rounded-full bg-emerald-600"
                  style={{
                    width:
                      (max > 0 ? Math.max(2, (bar.value / max) * 100) : 0) +
                      '%',
                  }}
                />
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
