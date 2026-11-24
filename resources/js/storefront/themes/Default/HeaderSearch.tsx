import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from '../../../lib/useTranslation';
import type { PublicDisplay } from '../../types';
import SearchBox from './SearchBox';

/** Full-screen header search, sharing the existing query and suggestions form. */
export function FullscreenSearch({
  id,
  display,
  onClose,
  icon,
}: {
  id: string;
  display: PublicDisplay;
  onClose: () => void;
  icon: ReactNode;
}) {
  const t = useTranslation();
  const dialog = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const element = dialog.current;
    const body = document.body.style;
    const before = {
      overflow: body.overflow,
      position: body.position,
      top: body.top,
      width: body.width,
    };
    // iOS Safari scrolls the page under an open overlay despite overflow: hidden, so the page is pinned in place and
    // put back at the same spot afterwards.
    const scrollY = window.scrollY;
    Object.assign(body, {
      overflow: 'hidden',
      position: 'fixed',
      top: `-${scrollY}px`,
      width: '100%',
    });
    // The search fills what is visible: above the on-screen keyboard while it is open, the whole screen once it is put away.
    const viewport = window.visualViewport;
    const fit = () => {
      if (!element || !viewport) return;
      element.style.setProperty('--sf-search-height', `${viewport.height}px`);
      element.style.setProperty('--sf-search-top', `${viewport.offsetTop}px`);
    };
    fit();
    viewport?.addEventListener('resize', fit);
    viewport?.addEventListener('scroll', fit);
    element?.showModal();
    element?.querySelector('input')?.focus();
    return () => {
      viewport?.removeEventListener('resize', fit);
      viewport?.removeEventListener('scroll', fit);
      element?.close();
      Object.assign(body, before);
      window.scrollTo(0, scrollY);
    };
  }, []);
  const close = () => {
    dialog.current?.close();
    onClose();
  };
  return (
    <dialog
      ref={dialog}
      id={id}
      className="sf-fashion-search"
      aria-label={t('storefront.search')}
      onCancel={(event) => {
        event.preventDefault();
        close();
      }}
      onKeyDown={(event) => {
        if (event.key === 'Escape' && !event.defaultPrevented) {
          event.preventDefault();
          close();
        }
      }}
    >
      <div className="sf-fashion-search-head">
        <SearchBox
          limit={display.search_suggestions ?? 6}
          minLength={display.search_min_length ?? 0}
          icon={icon}
          keepOpen
        />
        <button
          type="button"
          className="sf-fashion-search-close"
          aria-label={t('storefront.close')}
          onClick={close}
        >
          <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            width="24"
            height="24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
          >
            <path d="M6 6l12 12M18 6 6 18" />
          </svg>
          <span>{t('storefront.close')}</span>
        </button>
      </div>
    </dialog>
  );
}

/** Classic/Compact keep their desktop field and open the shared full-screen search on mobile. */
export default function HeaderSearch({
  display,
  icon,
}: {
  display: PublicDisplay;
  icon: ReactNode;
}) {
  const t = useTranslation();
  const [open, setOpen] = useState(false);
  const id = useId();
  const trigger = useRef<HTMLButtonElement>(null);
  return (
    <>
      <div className="sf-inline-header-search">
        <SearchBox
          limit={display.search_suggestions ?? 6}
          minLength={display.search_min_length ?? 0}
          icon={icon}
        />
      </div>
      <button
        ref={trigger}
        type="button"
        className="sf-mobile-search-trigger"
        aria-label={t('storefront.search')}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-controls={id}
        onClick={() => setOpen(true)}
      >
        <span>{t('storefront.search_placeholder')}</span>
        {icon}
      </button>
      {open &&
        createPortal(
          <FullscreenSearch
            id={id}
            display={display}
            icon={icon}
            onClose={() => {
              setOpen(false);
              // Back to the opener without jumping there: the page returns to where the shopper was.
              trigger.current?.focus({ preventScroll: true });
            }}
          />,
          document.querySelector('.sf-default') ?? document.body,
        )}
    </>
  );
}
