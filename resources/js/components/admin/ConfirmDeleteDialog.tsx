import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from '../../lib/useTranslation';

/**
 * Permanent-deletion confirmation: states what is lost, offers archiving as the safer alternative and, when a word
 * is given, only enables the delete button once it has been typed.
 */
export default function ConfirmDeleteDialog({
  title,
  icon = '🗑',
  subtitle,
  confirmLabel,
  facts,
  confirmWord,
  onArchive,
  onConfirm,
  onCancel,
}: {
  title: string;
  icon?: ReactNode;
  /** Defaults to the permanent-deletion warning. */
  subtitle?: string;
  /** Defaults to "Permanently delete". */
  confirmLabel?: string;
  /** What is lost, one line each (use <strong> for the numbers). */
  facts: ReactNode[];
  confirmWord?: string;
  onArchive?: () => void;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const t = useTranslation();
  const [typed, setTyped] = useState('');
  const input = useRef<HTMLInputElement>(null);
  const titleId = useId();
  const ready = !confirmWord || typed.trim() === confirmWord;
  useEffect(() => {
    input.current?.focus();
    const escape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onCancel();
    };
    document.addEventListener('keydown', escape);
    return () => document.removeEventListener('keydown', escape);
  }, [onCancel]);
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
      <form
        role="alertdialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="w-full max-w-md space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl"
        onSubmit={(e) => {
          e.preventDefault();
          if (ready) onConfirm();
        }}
      >
        <div className="flex items-start gap-3">
          <span
            aria-hidden="true"
            className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600"
          >
            {icon}
          </span>
          <div className="min-w-0 flex-1">
            <h2 id={titleId} className="text-lg leading-6 font-semibold">
              {title}
            </h2>
            <p className="mt-1 text-sm text-slate-500">
              {subtitle ?? t('features.delete_permanent')}
            </p>
          </div>
          <button
            type="button"
            aria-label={t('common.close')}
            className="button-secondary px-2.5 py-1"
            onClick={onCancel}
          >
            ×
          </button>
        </div>
        {facts.length > 0 && (
          <ul className="divide-y divide-slate-200 rounded-lg bg-slate-50 text-sm">
            {facts.map((fact, index) => (
              <li key={index} className="px-3 py-2.5">
                {fact}
              </li>
            ))}
          </ul>
        )}
        {onArchive && (
          <div className="space-y-1 rounded-lg bg-primary-tint p-3 text-sm text-emerald-900">
            <p>{t('features.archive_hint')}</p>
            <button
              type="button"
              className="font-medium underline"
              onClick={onArchive}
            >
              {t('features.archive_instead')}
            </button>
          </div>
        )}
        {confirmWord && (
          <div className="space-y-2">
            <label
              htmlFor={titleId + '-word'}
              className="block text-sm font-medium"
            >
              {t('features.type_to_confirm')}{' '}
              <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs">
                {confirmWord}
              </code>
            </label>
            <input
              id={titleId + '-word'}
              ref={input}
              className="field w-full"
              autoComplete="off"
              placeholder={t('features.type_placeholder', {
                word: confirmWord,
              })}
              value={typed}
              onChange={(e) => setTyped(e.target.value)}
            />
          </div>
        )}
        <div className="flex justify-end gap-2 pt-1">
          <button type="button" className="button-secondary" onClick={onCancel}>
            {t('common.cancel')}
          </button>
          <button
            type="submit"
            disabled={!ready}
            className="button-secondary border-red-600 bg-red-600 text-white hover:bg-red-700 disabled:opacity-40"
          >
            {confirmLabel ?? t('features.delete')}
          </button>
        </div>
      </form>
    </div>
  );
}
