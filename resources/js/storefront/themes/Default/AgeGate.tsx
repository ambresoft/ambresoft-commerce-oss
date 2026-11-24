import { useEffect, useRef, useState } from 'react';
import { useTranslation } from '../../../lib/useTranslation';

const csrf = () =>
  decodeURIComponent(
    document.cookie
      .split('; ')
      .find((row) => row.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length) ?? '',
  );

/** The 18+ sign above the question. */
function AgeSign({ age }: { age: number }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 120 120"
      width="120"
      height="120"
      fill="none"
    >
      <circle cx="60" cy="60" r="54" stroke="#c94a4f" strokeWidth="4" />
      <text
        x="60"
        y="72"
        textAnchor="middle"
        fill="#c94a4f"
        fontSize="38"
        fontWeight="700"
        fontFamily="inherit"
      >
        {age}+
      </text>
    </svg>
  );
}

/**
 * An age-restricted page: nothing of it is shown until the visitor confirms the age (remembered by the server), or
 * they go back to the homepage.
 */
export default function AgeGate({
  age,
  message,
}: {
  age: number;
  /** The merchant's own warning; empty uses the theme's. */
  message: string;
}) {
  const t = useTranslation();
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);
  const confirm = useRef<HTMLButtonElement>(null);
  useEffect(() => confirm.current?.focus(), []);
  const accept = async () => {
    setBusy(true);
    setFailed(false);
    const response = await fetch('/age-verification', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': csrf(),
      },
      body: JSON.stringify({ age }),
    }).catch(() => null);
    if (response?.ok) {
      window.location.reload();
      return;
    }
    setBusy(false);
    setFailed(true);
  };
  return (
    <div className="sf-agegate">
      <div
        className="sf-agegate-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sf-agegate-title"
        aria-describedby="sf-agegate-text"
      >
        <AgeSign age={age} />
        <h1 id="sf-agegate-title">{t('storefront.age_gate_title')}</h1>
        <p id="sf-agegate-text">
          {message || t('storefront.age_gate_message', { age })}
        </p>
        {failed && (
          <p className="sf-agegate-error" role="alert">
            {t('storefront.cart_error')}
          </p>
        )}
        <button
          ref={confirm}
          type="button"
          className="sf-agegate-yes"
          disabled={busy}
          onClick={accept}
        >
          {t('storefront.age_gate_confirm', { age })}
        </button>
        <a className="sf-agegate-back" href="/">
          {t('storefront.age_gate_back')}
        </a>
      </div>
    </div>
  );
}
