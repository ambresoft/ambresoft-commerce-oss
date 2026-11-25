import { useEffect, useId, useRef } from 'react';
import { useTranslation } from '../../lib/useTranslation';

export default function ConfirmDialog({
  title,
  description,
  confirmLabel,
  busy,
  onConfirm,
  onCancel,
}: {
  title: string;
  description: string;
  confirmLabel: string;
  busy: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const id = useId();
  const t = useTranslation();
  useEffect(() => {
    const panel = dialog.current!;
    const previous = document.activeElement as HTMLElement | null;
    panel.showModal();
    return () => {
      panel.close();
      previous?.focus();
    };
  }, []);
  return (
    <dialog
      ref={dialog}
      aria-labelledby={id}
      aria-describedby={id + '-description'}
      className="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-slate-200 bg-white p-6 text-slate-900 shadow-xl backdrop:bg-black/40"
      onCancel={(event) => {
        event.preventDefault();
        if (!busy) onCancel();
      }}
    >
      <h2 id={id} className="text-lg font-semibold">
        {title}
      </h2>
      <p
        id={id + '-description'}
        className="mt-3 text-sm leading-6 text-slate-500"
      >
        {description}
      </p>
      <div className="mt-6 flex justify-end gap-3">
        <button
          type="button"
          className="button-secondary"
          disabled={busy}
          onClick={onCancel}
          autoFocus
        >
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className="button-primary"
          disabled={busy}
          onClick={onConfirm}
        >
          {busy ? t('common.wait') : confirmLabel}
        </button>
      </div>
    </dialog>
  );
}
