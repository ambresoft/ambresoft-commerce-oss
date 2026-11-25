import { useEffect, useRef } from 'react';
import type { ImpactPreview } from '../../types/features';
import { useTranslation } from '../../lib/useTranslation';

/** Modal review of an action's impact; the server has not changed anything until the administrator confirms. */
export default function FeatureImpactDialog({
  preview,
  onConfirm,
  onCancel,
}: {
  preview: ImpactPreview;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const t = useTranslation();
  const confirm = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    if (!preview) return;
    confirm.current?.focus();
    const escape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onCancel();
    };
    document.addEventListener('keydown', escape);
    return () => document.removeEventListener('keydown', escape);
  }, [preview, onCancel]);
  if (!preview) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="feature-impact-title"
        className="w-full max-w-md space-y-3 rounded-xl border border-slate-200 bg-white p-6 shadow-xl"
      >
        <h2 id="feature-impact-title" className="text-lg font-semibold">
          {t('features.impact')}
        </h2>
        <ul className="space-y-1 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
          {Object.entries(preview.impact).map(([key, value]) => (
            <li key={key}>
              {t('features.' + key)}: <strong>{value}</strong>
            </li>
          ))}
        </ul>
        <p className="text-sm text-slate-600">{t('features.impact_help')}</p>
        <div className="flex flex-wrap justify-end gap-2 pt-2">
          <button type="button" className="button-secondary" onClick={onCancel}>
            {t('common.cancel')}
          </button>
          <button
            ref={confirm}
            type="button"
            className="button-primary"
            onClick={onConfirm}
          >
            {t('features.confirm_impact')}
          </button>
        </div>
      </div>
    </div>
  );
}
