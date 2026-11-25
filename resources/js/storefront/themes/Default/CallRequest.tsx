import { useId, useState, type FormEvent } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import { Field } from './AccountParts';
import Dialog from './Dialog';
import { emitStorefrontEvent } from '../../events';

const csrf = () =>
  decodeURIComponent(
    document.cookie
      .split('; ')
      .find((row) => row.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length) ?? '',
  );
type Contact = {
  name: string;
  phone: string;
  order_number: string;
  call_from: string;
  call_to: string;
};

export default function CallRequest({
  form,
  onClose,
}: {
  form: string;
  onClose: () => void;
}) {
  const t = useTranslation();
  const id = useId();
  const [data, setData] = useState<Contact>({
    name: '',
    phone: '',
    order_number: '',
    call_from: '09:00',
    call_to: '20:00',
  });
  const [trap, setTrap] = useState('');
  const [state, setState] = useState<'idle' | 'sending' | 'done'>('idle');
  const [errors, setErrors] = useState<Partial<Record<keyof Contact, string>>>(
    {},
  );
  const [message, setMessage] = useState('');
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (state === 'sending') return;
    setState('sending');
    setErrors({});
    setMessage('');
    try {
      const response = await fetch('/call-requests', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ ...data, form, website: trap }),
      });
      if (response.ok) {
        // Only that a call was requested: the shopper's name and number never leave the store.
        emitStorefrontEvent({ type: 'call_request' });
        setState('done');
        return;
      }
      if (response.status === 422) {
        const body = (await response.json()) as {
          errors?: Record<string, string[]>;
        };
        const fields = Object.fromEntries(
          Object.entries(body.errors ?? {})
            .filter(([key]) => key in data)
            .map(([key, messages]) => [key, messages[0]]),
        );
        setErrors(fields);
        if (Object.keys(fields).length === 0)
          setMessage(t('storefront.call_request_error'));
      } else
        setMessage(
          t(
            response.status === 429
              ? 'storefront.call_request_limit'
              : 'storefront.call_request_error',
          ),
        );
    } catch {
      setMessage(t('storefront.call_request_error'));
    }
    setState('idle');
  };
  const field = (name: 'name' | 'phone' | 'order_number', type = 'text') => (
    <Field
      id={`${id}-${name}`}
      label={t(`storefront.call_request_${name}`)}
      type={type}
      autoComplete={name === 'name' ? 'name' : name === 'phone' ? 'tel' : 'off'}
      required={name === 'phone'}
      inputMode={name === 'order_number' ? 'numeric' : undefined}
      maxLength={name === 'phone' ? 40 : name === 'order_number' ? 19 : 200}
      value={data[name]}
      onChange={(e) => setData({ ...data, [name]: e.target.value })}
      error={errors[name]}
      disabled={state === 'sending'}
    />
  );
  return (
    <Dialog
      title={t('storefront.call_request_title')}
      onClose={onClose}
      className="sf-acc-dialog"
    >
      {state === 'done' ? (
        <div className="sf-acc-form">
          <p role="status" className="sf-acc-notice">
            {t('storefront.call_request_success')}
          </p>
          <button type="button" className="sf-pdp-add" onClick={onClose}>
            {t('storefront.close')}
          </button>
        </div>
      ) : (
        <form className="sf-acc-form" onSubmit={submit} noValidate>
          <p>{t('storefront.call_request_help')}</p>
          {field('name')}
          {field('phone', 'tel')}
          {field('order_number')}
          <fieldset className="sf-call-time">
            <legend>{t('storefront.call_request_convenient_time')}</legend>
            <div className="sf-call-time-row">
              {(['call_from', 'call_to'] as const).map((key, index) => (
                <div key={key}>
                  <label htmlFor={`${id}-${key}`} className="sr-only">
                    {t(
                      key === 'call_from'
                        ? 'storefront.call_request_from'
                        : 'storefront.call_request_to',
                    )}
                  </label>
                  <div className="sf-acc-field">
                    <input
                      id={`${id}-${key}`}
                      type="time"
                      step={60}
                      value={data[key]}
                      disabled={state === 'sending'}
                      aria-invalid={errors[key] ? true : undefined}
                      aria-describedby={
                        errors[key] ? `${id}-${key}-error` : undefined
                      }
                      onChange={(e) =>
                        setData({ ...data, [key]: e.target.value })
                      }
                    />
                  </div>
                  {errors[key] && (
                    <p
                      id={`${id}-${key}-error`}
                      className="sf-acc-error"
                      role="alert"
                    >
                      {errors[key]}
                    </p>
                  )}
                  {index === 0 && (
                    <span className="sf-call-time-separator" aria-hidden="true">
                      –
                    </span>
                  )}
                </div>
              ))}
            </div>
          </fieldset>
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
          {message && (
            <p className="sf-pdp-form-error" role="alert">
              {message}
            </p>
          )}
          <button
            className="sf-pdp-add"
            type="submit"
            disabled={state === 'sending'}
          >
            {t(
              state === 'sending'
                ? 'storefront.call_request_sending'
                : 'storefront.call_request_submit',
            )}
          </button>
        </form>
      )}
    </Dialog>
  );
}
