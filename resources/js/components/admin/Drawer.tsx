import { useEffect, useId, useRef, type ReactNode } from 'react';
import { Icon } from './list/IconButton';
import { useTranslation } from '../../lib/useTranslation';

/** A modal side panel with native focus containment and a fixed action footer. */
export default function Drawer({
  title,
  description,
  children,
  footer,
  busy = false,
  onClose,
}: {
  title: string;
  description?: string;
  children: ReactNode;
  footer: ReactNode;
  busy?: boolean;
  onClose: () => void;
}) {
  const t = useTranslation();
  const dialog = useRef<HTMLDialogElement>(null);
  const id = useId();
  useEffect(() => {
    const panel = dialog.current!;
    const previous = document.activeElement as HTMLElement | null;
    panel.showModal();
    panel
      .querySelector<HTMLElement>('input:not([disabled]), textarea, select')
      ?.focus();
    return () => {
      panel.close();
      previous?.focus();
    };
  }, []);
  return (
    <dialog
      ref={dialog}
      aria-labelledby={id}
      aria-describedby={description ? id + '-description' : undefined}
      className="admin-drawer fixed inset-y-0 right-0 left-auto m-0 h-dvh max-h-none w-full max-w-xl border-0 border-l border-slate-200 bg-white p-0 text-slate-900 shadow-xl backdrop:bg-slate-900/15"
      onCancel={(event) => {
        event.preventDefault();
        if (!busy) onClose();
      }}
      onClick={(event) => {
        if (event.target !== event.currentTarget || busy) return;
        const bounds = event.currentTarget.getBoundingClientRect();
        if (
          event.clientX < bounds.left ||
          event.clientX > bounds.right ||
          event.clientY < bounds.top ||
          event.clientY > bounds.bottom
        )
          onClose();
      }}
    >
      <div className="flex h-full flex-col">
        <header className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
          <div>
            <h2 id={id} className="text-lg font-semibold">
              {title}
            </h2>
            {description && (
              <p
                id={id + '-description'}
                className="mt-1 text-sm text-slate-500"
              >
                {description}
              </p>
            )}
          </div>
          <button
            type="button"
            aria-label={t('common.close')}
            className="button-secondary shrink-0 px-2.5"
            disabled={busy}
            onClick={onClose}
          >
            <Icon name="x" className="size-5" />
          </button>
        </header>
        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-6">
          {children}
        </div>
        <footer className="flex shrink-0 justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4">
          {footer}
        </footer>
      </div>
    </dialog>
  );
}
