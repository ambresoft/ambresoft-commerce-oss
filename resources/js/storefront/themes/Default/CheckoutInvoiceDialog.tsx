import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import { Field } from './AccountParts';
import Dialog from './Dialog';
import CountrySelect from '../../../components/admin/CountrySelect';

export type InvoiceDetails = {
  country_code: string;
  vat_number: string;
  business_name: string | null;
  provider?: string;
};

export default function CheckoutInvoiceDialog({
  countries,
  initial,
  signedIn,
  greekSignIn = true,
  onClose,
  onSignIn,
  onVerified,
}: {
  countries: { code: string; name: string }[];
  initial: { country_code: string; vat_number: string };
  signedIn: boolean;
  greekSignIn?: boolean;
  onClose: () => void;
  onSignIn: () => void;
  onVerified: (details: InvoiceDetails) => void;
}) {
  const t = useTranslation();
  const [country, setCountry] = useState(initial.country_code);
  const [vat, setVat] = useState(initial.vat_number);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const call = useRef<AbortController | null>(null);
  useEffect(() => () => call.current?.abort(), []);
  const verify = async (e: FormEvent) => {
    e.preventDefault();
    e.stopPropagation();
    if (busy) return;
    setBusy(true);
    setError('');
    const controller = new AbortController();
    call.current = controller;
    try {
      const csrf = decodeURIComponent(
        document.cookie
          .split('; ')
          .find((row) => row.startsWith('XSRF-TOKEN='))
          ?.slice(11) ?? '',
      );
      const response = await fetch('/checkout/invoice/verify', {
        method: 'POST',
        credentials: 'same-origin',
        signal: controller.signal,
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': csrf,
        },
        body: JSON.stringify({ country_code: country, vat_number: vat }),
      });
      if (controller.signal.aborted) return;
      const result = await response.json();
      if (controller.signal.aborted) return;
      if (!response.ok) {
        setError(
          result.errors?.vat_number?.[0] ||
            result.errors?.country_code?.[0] ||
            t('checkout.unavailable'),
        );
        return;
      }
      if (result.verification?.country_code && result.verification?.vat_number)
        onVerified(result.verification);
      else setError(t('checkout.unavailable'));
    } catch {
      if (!controller.signal.aborted) setError(t('checkout.unavailable'));
    } finally {
      if (!controller.signal.aborted) setBusy(false);
    }
  };
  return (
    <Dialog
      title={t('checkout.modal_title')}
      onClose={onClose}
      className="sf-co-page-dialog"
    >
      <form className="sf-acc-form" onSubmit={verify} aria-busy={busy}>
        <p>{t('checkout.modal_help')}</p>
        <div className="sf-acc-field">
          <label htmlFor="invoice-country">{t('checkout.country')}</label>
          <CountrySelect
            id="invoice-country"
            value={country}
            className="sf-co-country-select"
            disabled={busy}
            aria-required
            options={[
              { value: '', label: t('checkout.choose_country') },
              ...countries.map((c) => ({ value: c.code, label: c.name })),
            ]}
            onChange={(value) => {
              setCountry(value);
              setError('');
            }}
          />
        </div>
        <Field
          id="invoice-vat"
          label={t('checkout.vat_number')}
          value={vat}
          maxLength={40}
          required
          disabled={busy}
          autoComplete="off"
          error={error}
          onChange={(e) => {
            setVat(e.target.value);
            setError('');
          }}
        />
        {country === 'GR' && greekSignIn && !signedIn ? (
          <>
            <p>{t('checkout.sign_in')}</p>
            <button
              type="button"
              className="sf-cart-button w-full"
              onClick={onSignIn}
            >
              {t('auth.sign_in')}
            </button>
          </>
        ) : (
          <div className="sf-co-invoice-actions flex justify-end">
            <button
              type="submit"
              className="sf-cart-button gap-2"
              disabled={busy || !country || !vat.trim()}
              aria-busy={busy}
            >
              <svg
                aria-hidden="true"
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                className={
                  busy
                    ? 'shrink-0 animate-spin motion-reduce:animate-none'
                    : 'shrink-0'
                }
              >
                {busy ? (
                  <>
                    <circle cx="12" cy="12" r="9" opacity="0.25" />
                    <path d="M12 3a9 9 0 0 1 9 9" />
                  </>
                ) : (
                  <>
                    <path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z" />
                    <path d="m8.5 12 2.5 2.5 4.5-5" />
                  </>
                )}
              </svg>
              <span>{t(busy ? 'checkout.checking' : 'checkout.verify')}</span>
            </button>
          </div>
        )}
      </form>
    </Dialog>
  );
}
