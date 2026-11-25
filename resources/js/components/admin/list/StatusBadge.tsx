import type { ReactNode } from 'react';

export type Tone = 'success' | 'neutral' | 'warning' | 'danger' | 'dark';

const tones: Record<Tone, string> = {
  success: 'bg-primary-tint text-emerald-900 [--dot:var(--color-primary)]',
  neutral: 'bg-slate-100 text-slate-600 [--dot:#a3a3a3]',
  warning: 'bg-amber-50 text-amber-800 [--dot:var(--color-warning)]',
  danger: 'bg-red-50 text-red-700 [--dot:var(--color-danger)]',
  dark: 'bg-slate-900 text-white [--dot:var(--color-white)]',
};

/** Rounded status pill with a coloured dot. */
export default function StatusBadge({
  tone,
  children,
}: {
  tone: Tone;
  children: ReactNode;
}) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap ${tones[tone]}`}
    >
      <span aria-hidden="true" className="size-1.5 rounded-full bg-(--dot)" />
      {children}
    </span>
  );
}
