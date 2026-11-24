import { useState, type FormEvent } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { PublicBlock } from '../../types';

const csrf = () =>
  decodeURIComponent(
    document.cookie
      .split('; ')
      .find((row) => row.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length) ?? '',
  );

/** Newsletter sign-up: the block decides the list; the visitor confirms by e-mail (double opt-in). */
export default function Newsletter({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const [email, setEmail] = useState('');
  const [trap, setTrap] = useState('');
  const [state, setState] = useState<
    'idle' | 'sending' | 'done' | 'confirm' | 'error'
  >('idle');
  const [message, setMessage] = useState('');
  const terms = block.content.terms_url;
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setState('sending');
    try {
      const response = await fetch('/newsletter/subscribe', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({
          email,
          form: block.content.form,
          website: trap,
        }),
      });
      if (response.ok) {
        const body = (await response.json()) as { confirm?: boolean };
        setState(body.confirm ? 'confirm' : 'done');
        setEmail('');
        return;
      }
      const body = (await response.json().catch(() => ({}))) as {
        message?: string;
      };
      setMessage(
        response.status === 422
          ? (body.message ?? t('storefront.newsletter_invalid'))
          : t('storefront.newsletter_error'),
      );
    } catch {
      setMessage(t('storefront.newsletter_error'));
    }
    setState('error');
  };
  if (state === 'done' || state === 'confirm')
    return (
      <p className="sf-newsletter-thanks" role="status">
        <svg
          aria-hidden="true"
          viewBox="0 0 24 24"
          width="28"
          height="28"
          fill="none"
          stroke="currentColor"
          strokeWidth="2.4"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <circle cx="12" cy="12" r="10" />
          <path d="m7.5 12.5 3 3 6-6.5" />
        </svg>
        {t(
          state === 'confirm'
            ? 'storefront.newsletter_confirm'
            : 'storefront.newsletter_thanks',
        )}
      </p>
    );
  return (
    <form className="sf-newsletter" onSubmit={submit} noValidate>
      <div className="sf-newsletter-row">
        <label className="sr-only" htmlFor={`nl-${block.id}`}>
          {t('storefront.newsletter_email')}
        </label>
        <input
          id={`nl-${block.id}`}
          type="email"
          autoComplete="email"
          required
          maxLength={254}
          placeholder={t('storefront.newsletter_email')}
          value={email}
          aria-invalid={state === 'error'}
          onChange={(e) => setEmail(e.target.value)}
        />
        <input
          className="sf-hp"
          type="text"
          name="website"
          tabIndex={-1}
          autoComplete="off"
          aria-hidden="true"
          value={trap}
          onChange={(e) => setTrap(e.target.value)}
        />
        <button type="submit" disabled={state === 'sending'}>
          {t('storefront.newsletter_button')}
        </button>
      </div>
      {state === 'error' && (
        <p className="sf-newsletter-error" role="alert">
          {message}
        </p>
      )}
      {terms && (
        <p className="sf-newsletter-terms">
          {t('storefront.newsletter_accept', {
            button: t('storefront.newsletter_button'),
          })}{' '}
          <a href={terms}>{t('storefront.newsletter_terms')}</a>
        </p>
      )}
    </form>
  );
}
