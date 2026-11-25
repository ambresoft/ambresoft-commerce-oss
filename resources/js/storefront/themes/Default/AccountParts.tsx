import { router, usePage } from '@inertiajs/react';
import {
  createContext,
  useContext,
  useId,
  useState,
  type InputHTMLAttributes,
  type ReactNode,
} from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import { usePreferences } from '../../../lib/usePreferences';
import type { SharedProps } from '../../../types/page';

/** Account dates use the same store language and timezone as the administration. */
export function useDateFormat() {
  const { locale } = usePage<SharedProps>().props.i18n;
  const tag = locale.replace('_', '-');
  const { timezone } = usePreferences();
  return (iso: string, options?: Intl.DateTimeFormatOptions) =>
    new Date(iso).toLocaleString(tag, {
      ...(options ?? { dateStyle: 'medium' }),
      timeZone: timezone,
    });
}

/** The message left by the last account action, and errors that belong to no field. */
export function Notices({ skip = [] }: { skip?: string[] }) {
  const { flash, errors } = usePage<
    SharedProps & { errors?: Record<string, string> }
  >().props;
  const loose = Object.entries(errors ?? {}).filter(
    ([key]) => !skip.includes(key),
  );
  return (
    <>
      {flash?.status && (
        <p role="status" className="sf-acc-notice">
          {flash.status}
        </p>
      )}
      {loose.map(([key, message]) => (
        <p key={key} role="alert" className="sf-pdp-form-error">
          {message}
        </p>
      ))}
    </>
  );
}

type FieldProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> & {
  id: string;
  label: string;
  error?: string;
  hint?: string;
  /** Password fields can show what was typed. */
  revealable?: boolean;
};

export function Field({
  id,
  label,
  error,
  hint,
  revealable,
  ...input
}: FieldProps) {
  const t = useTranslation();
  const [revealed, setRevealed] = useState(false);
  const describedBy =
    [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(' ') ||
    undefined;
  return (
    <div className="sf-acc-field">
      <label htmlFor={id}>
        {label}
        {input.required && <span aria-hidden="true"> *</span>}
      </label>
      <div className={revealable ? 'sf-acc-reveal-wrap' : undefined}>
        <input
          {...input}
          id={id}
          name={input.name ?? id}
          type={revealable && revealed ? 'text' : input.type}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
        />
        {revealable && (
          <button
            type="button"
            className="sf-acc-reveal"
            aria-label={t(
              revealed ? 'auth.hide_password' : 'auth.show_password',
            )}
            onClick={() => setRevealed(!revealed)}
          >
            <svg
              aria-hidden="true"
              viewBox="0 0 24 24"
              width="22"
              height="22"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
              <circle cx="12" cy="12" r="3" />
              {revealed && <path d="M3 3l18 18" />}
            </svg>
          </button>
        )}
      </div>
      {hint && (
        <p id={`${id}-hint`} className="sf-acc-hint">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="sf-acc-error">
          {error}
        </p>
      )}
    </div>
  );
}

/** A titled card of the account page. */
export function Card({
  title,
  hint,
  children,
  danger,
}: {
  title: string;
  hint?: string;
  children: ReactNode;
  danger?: boolean;
}) {
  return (
    <section className={`sf-acc-card${danger ? ' is-danger' : ''}`}>
      <h2>{title}</h2>
      {hint && <p className="sf-acc-hint">{hint}</p>}
      {children}
    </section>
  );
}

/** Outline icons shared by the account menu, address cards and checkout sections. */
const icons = {
  profile: (
    <>
      <circle cx="12" cy="8" r="4" />
      <path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7" />
    </>
  ),
  orders: (
    <>
      <path d="M21 8 12 3 3 8l9 5 9-5Z" />
      <path d="M3 8v8l9 5 9-5V8M12 13v8" />
    </>
  ),
  addresses: (
    <>
      <path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21Z" />
      <circle cx="12" cy="9.5" r="2.5" />
    </>
  ),
  company: (
    <path d="M3 21h18M5 21V7h6v14M11 21V3h8v18M7.5 10h.01M7.5 14h.01M14 7h2M14 11h2M14 15h2" />
  ),
  globe: (
    <>
      <circle cx="12" cy="12" r="9" />
      <ellipse cx="12" cy="12" rx="4" ry="9" />
      <path d="M3 12h18M5 7h14M5 17h14" />
    </>
  ),
  phone: (
    <path d="M21 16.5v3a1.5 1.5 0 0 1-1.6 1.5C10.6 20.4 3.6 13.4 3 4.6A1.5 1.5 0 0 1 4.5 3h3l1.5 5-2 2a16 16 0 0 0 7 7l2-2 5 1.5Z" />
  ),
  bell: (
    <>
      <path d="M5 3h11v18H5zM9 7h3M9 11h.01M19 8c2 2 2 6 0 8" />
    </>
  ),
  notes: (
    <>
      <path d="M4 4h16v13H9l-5 4V4Z" />
      <path d="M8 8h8M8 12h5" />
    </>
  ),
  edit: (
    <>
      <path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4Z" />
      <path d="m13.5 6.5 4 4" />
    </>
  ),
  trash: <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" />,
  reviews: (
    <path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z" />
  ),
  newsletters: (
    <>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="m3 7 9 6 9-6" />
    </>
  ),
  password: (
    <>
      <rect x="5" y="11" width="14" height="9" rx="2" />
      <path d="M8 11V8a4 4 0 0 1 8 0v3" />
    </>
  ),
  wishlist: (
    <path d="M12 20.5s-7.5-4.6-9-9.6C2 7.7 4 5 7 5c2 0 3.4 1 5 3 1.6-2 3-3 5-3 3 0 5 2.7 4 5.9-1.5 5-9 9.6-9 9.6Z" />
  ),
  delivery: (
    <>
      <path d="M3 6h11v10H3zM14 9h4l3 3v4h-7" />
      <circle cx="7" cy="17.5" r="1.8" />
      <circle cx="17" cy="17.5" r="1.8" />
    </>
  ),
  payment: (
    <>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="M3 10h18M7 15h4" />
    </>
  ),
  billing: (
    <>
      <path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z" />
      <path d="M9 8h6M9 12h6" />
    </>
  ),
  logout: (
    <>
      <path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4" />
      <path d="M16 8l4 4-4 4M20 12H9" />
    </>
  ),
};

export function AccountIcon({ name }: { name: keyof typeof icons }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="20"
      height="20"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {icons[name]}
    </svg>
  );
}

/** General settings: reviews are on for the store; off drops the account's Reviews part. */
export const ReviewsEnabledContext = createContext(true);

/** The account parts the store offers. */
export function useAccountSections() {
  const reviews = useContext(ReviewsEnabledContext);
  return accountSections.filter((s) => reviews || s.key !== 'reviews');
}

export const accountSections = [
  { key: 'profile', href: '/account', label: 'account.nav_profile' },
  { key: 'orders', href: '/account/orders', label: 'account.nav_orders' },
  {
    key: 'addresses',
    href: '/account/addresses',
    label: 'account.nav_addresses',
  },
  { key: 'reviews', href: '/account/reviews', label: 'account.nav_reviews' },
  {
    key: 'newsletters',
    href: '/account/newsletters',
    label: 'account.nav_newsletters',
  },
  {
    key: 'password',
    href: '/account/password',
    label: 'account.nav_password',
  },
] as const;

/** The account menu beside every part of the account, the favourites page included. */
export function AccountMenu({
  current,
  wishlist,
}: {
  current: string;
  wishlist: boolean;
}) {
  const t = useTranslation();
  const list = useId();
  const sections = useAccountSections();
  // On phones the menu is a dropdown that shows the open part; wider screens list every part.
  const [open, setOpen] = useState(false);
  const items = [
    ...sections,
    ...(wishlist
      ? [
          {
            key: 'wishlist',
            href: '/wishlist',
            label: 'storefront.wishlist_title',
          } as const,
        ]
      : []),
  ];
  const active = items.find((s) => s.key === current) ?? items[0];
  return (
    <nav
      className={`sf-acc-nav${open ? ' is-open' : ''}`}
      aria-label={t('account.menu_title')}
    >
      <button
        type="button"
        className="sf-acc-nav-toggle"
        aria-expanded={open}
        aria-controls={list}
        onClick={() => setOpen(!open)}
      >
        <AccountIcon name={active.key} />
        <span>{t(active.label)}</span>
        <svg
          aria-hidden="true"
          className="sf-acc-nav-caret"
          viewBox="0 0 24 24"
          width="20"
          height="20"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="m6 9 6 6 6-6" />
        </svg>
      </button>
      <div className="sf-acc-nav-list" id={list}>
        {items.map((s) => (
          <a
            key={s.key}
            href={s.href}
            aria-current={current === s.key ? 'page' : undefined}
          >
            <AccountIcon name={s.key} />
            {t(s.label)}
          </a>
        ))}
        <button type="button" onClick={() => router.post('/logout')}>
          <AccountIcon name="logout" />
          {t('navigation.sign_out')}
        </button>
      </div>
    </nav>
  );
}
