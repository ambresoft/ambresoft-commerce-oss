import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import { AccountIcon, useAccountSections } from './AccountParts';
import { useWishlist } from './wishlistContext';

/** Native popover supplies outside-click dismissal, Escape and focus restoration. */
export default function HeaderAccountMenu({
  signedIn,
  children,
  className,
  fashion = false,
}: {
  signedIn: boolean;
  children: ReactNode;
  className?: string;
  fashion?: boolean;
}) {
  const t = useTranslation();
  const sections = useAccountSections();
  const wishlist = useWishlist();
  const id = useId();
  const panel = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState({ top: 0, right: 12 });
  useEffect(() => {
    if (!open) return;
    const close = (event: Event) => {
      if (event.target instanceof Node && panel.current?.contains(event.target))
        return;
      panel.current?.hidePopover();
    };
    window.addEventListener('resize', close);
    document.addEventListener('scroll', close, true);
    return () => {
      window.removeEventListener('resize', close);
      document.removeEventListener('scroll', close, true);
    };
  }, [open]);
  return (
    <>
      <button
        type="button"
        className={`sf-header-account-trigger ${className ?? ''}`}
        data-fashion-action={fashion ? 'account' : undefined}
        aria-label={t('storefront.account')}
        aria-expanded={open}
        aria-controls={id}
        popoverTarget={id}
        onClick={(event) => {
          const rect = event.currentTarget.getBoundingClientRect();
          setPosition({
            top: rect.bottom + 10,
            right: Math.min(
              Math.max(12, window.innerWidth - rect.right),
              Math.max(12, window.innerWidth - 312),
            ),
          });
        }}
      >
        {children}
      </button>
      <div
        id={id}
        ref={panel}
        popover="auto"
        className="sf-header-account-menu"
        style={{
          ...position,
          maxHeight: `calc(100dvh - ${position.top + 12}px)`,
        }}
        onToggle={(event) => setOpen(event.newState === 'open')}
      >
        <nav aria-label={t('account.menu_title')}>
          {signedIn ? (
            <>
              {sections.map((item) => (
                <a key={item.key} href={item.href}>
                  <AccountIcon name={item.key} />
                  {t(item.label)}
                </a>
              ))}
              {wishlist.enabled && (
                <a href="/wishlist">
                  <AccountIcon name="wishlist" />
                  {t('storefront.wishlist_title')}
                </a>
              )}
              <button type="button" onClick={() => router.post('/logout')}>
                <AccountIcon name="logout" />
                {t('navigation.sign_out')}
              </button>
            </>
          ) : (
            <>
              <a href="/login">
                <AccountIcon name="profile" />
                {t('auth.sign_in')}
              </a>
              <a href="/register">
                <AccountIcon name="profile" />
                {t('auth.create_link')}
              </a>
            </>
          )}
        </nav>
      </div>
    </>
  );
}
