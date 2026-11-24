import { useEffect, useId, useRef, type ReactNode } from 'react';
import { useTranslation } from '../../../lib/useTranslation';

/** A centered dialog in the storefront's style: title, close button, Esc and a click outside close it. */
export default function Dialog({
  title,
  onClose,
  children,
  className = '',
}: {
  title: string;
  onClose: () => void;
  children: ReactNode;
  className?: string;
}) {
  const t = useTranslation();
  const dialog = useRef<HTMLDialogElement>(null);
  const id = useId();
  useEffect(() => {
    dialog.current?.showModal();
  }, []);
  return (
    <dialog
      ref={dialog}
      className={`sf-pdp-modal ${className}`}
      aria-labelledby={id}
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      onClick={(e) => e.target === dialog.current && onClose()}
    >
      <div className="sf-pdp-history-head">
        <h2 id={id}>{title}</h2>
        <button
          type="button"
          className="sf-pdp-history-close"
          aria-label={t('storefront.close')}
          onClick={onClose}
        >
          ×
        </button>
      </div>
      {children}
    </dialog>
  );
}
