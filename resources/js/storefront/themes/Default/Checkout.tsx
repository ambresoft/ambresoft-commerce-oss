import { useForm } from '@inertiajs/react';
import { createPortal } from 'react-dom';
import FormField from '../../../components/auth/FormField';
import SubmitButton from '../../../components/auth/SubmitButton';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type {
  CheckoutConfirmation,
  CheckoutOptions,
  CheckoutPageData,
  CheckoutQuote,
  CurrencyFormat,
  PublicBlock,
  PublicImage,
} from '../../types';
import { AccountIcon, Field } from './AccountParts';
import { TicketIcon } from './Cart';
import { useCart } from './cartContext';
import Dialog from './Dialog';
import CheckoutInvoiceDialog, {
  type InvoiceDetails,
} from './CheckoutInvoiceDialog';
import { InfoTip, Money, Picture, TrashIcon } from './parts';

type Address = {
  first_name: string;
  last_name: string;
  company: string;
  line1: string;
  street_number: string;
  line2: string;
  city: string;
  postal_code: string;
  country_code: string;
  region_code: string;
  phone: string;
  floor: string;
  bell_name: string;
  notes: string;
};

type CheckoutForm = {
  submission_key: string;
  email: string;
  first_name: string;
  last_name: string;
  phone: string;
  /** A saved address of the customer, or '' for the address typed below. */
  shipping_address_id: string;
  shipping: Address;
  billing_same: boolean;
  billing_address_id: string;
  billing: Address;
  save_address: boolean;
  shipping_method_id: string;
  payment_method_id: string;
  customer_note: string;
  accepted_pages: string[];
  newsletter: boolean;
  invoice_requested: boolean;
  invoice_country_code: string;
  invoice_vat_number: string;
};

/** The address fields every address needs (region too, when its country has regions). */
const REQUIRED = [
  'first_name',
  'last_name',
  'line1',
  'city',
  'postal_code',
  'country_code',
] as const;

/** Address changes wait this long for the shopper to stop typing before the totals are asked for again. */
const QUOTE_DELAY = 450;

/** Errors shown next to their own field; any other error is listed above the button. */
const FIELD_ERRORS = [
  'invoice_requested',
  'email',
  'first_name',
  'last_name',
  'phone',
  'shipping_address_id',
  'billing_address_id',
  'shipping_method_id',
  'payment_method_id',
  'customer_note',
];

const blank = (first_name = '', last_name = ''): Address => ({
  first_name,
  last_name,
  company: '',
  line1: '',
  street_number: '',
  line2: '',
  city: '',
  postal_code: '',
  country_code: '',
  region_code: '',
  phone: '',
  floor: '',
  bell_name: '',
  notes: '',
});

const csrf = () =>
  decodeURIComponent(
    document.cookie
      .split('; ')
      .find((row) => row.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length) ?? '',
  );

/** The checkout page's main content block: the form, or the placed order's confirmation. */
export default function Checkout({ block }: { block: PublicBlock }) {
  const data = block.content.checkout;
  if (!data) return null;
  if (data.placed) return <Confirmation placed={data.placed} />;
  if (data.account_required) return <AccountRequired />;
  return data.cart && data.quote ? (
    <CheckoutFormView
      // A new visitor (signing in from the window) starts the form afresh: the contact and addresses come from the account.
      key={data.contact?.email ?? 'guest'}
      data={data}
      options={
        block.content.checkout_options ?? {
          pages: [],
          newsletter: null,
          invoice: false,
        }
      }
    />
  ) : null;
}

function LockIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="20"
      height="20"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <rect x="5" y="11" width="14" height="10" rx="2" />
      <path d="M8 11V7a4 4 0 0 1 8 0v4" />
    </svg>
  );
}

/** The checkout's own header: back to the cart, the store's logo in the middle and a secure-checkout mark. */
export function CheckoutHeader({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const header = block.content.checkout_header;
  if (!header) return null;
  return (
    <header
      className="sf-co-header"
      style={{
        ['--sf-co-header-bg' as string]: header.background_color,
        ...(header.text_color
          ? { ['--sf-co-header-text' as string]: header.text_color }
          : {}),
      }}
    >
      <a className="sf-co-header-back" href="/cart">
        <svg
          aria-hidden="true"
          viewBox="0 0 24 24"
          width="20"
          height="20"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="m15 5-7 7 7 7" />
        </svg>
        <span>{t('storefront.checkout_back')}</span>
      </a>
      <a className="sf-co-brand" href="/" aria-label={header.name}>
        {header.logo ? (
          <Picture image={header.logo} alt={header.name} eager />
        ) : (
          header.name
        )}
      </a>
      <p className="sf-co-secure" title={t('storefront.checkout_secure_hint')}>
        <span>{t('storefront.checkout_secure')}</span>
        <LockIcon />
      </p>
    </header>
  );
}

/** Signs a guest in without leaving the checkout: the same sign-in form as the login page, in a window; back to the checkout when it works. */
function CheckoutSignIn({ onClose }: { onClose: () => void }) {
  const t = useTranslation();
  const form = useForm({ email: '', password: '', remember: false });
  return (
    <Dialog title={t('storefront.checkout_sign_in_title')} onClose={onClose}>
      <div className="store-auth-card sf-co-signin-card">
        <p className="store-auth-lead">
          {t('storefront.checkout_sign_in_text')}
        </p>
        <form
          method="post"
          action="/login?return=checkout"
          onSubmit={(event) => {
            event.preventDefault();
            // The window sits in the checkout's React tree, so its submit would also place the order.
            event.stopPropagation();
            // The server returns to /checkout after a sign-in that asks for it; a failure stays here with its message.
            form.post('/login?return=checkout', {
              preserveScroll: true,
              onFinish: () => form.reset('password'),
            });
          }}
        >
          <FormField
            id="checkout-login-email"
            label={t('auth.email')}
            type="email"
            autoComplete="username"
            autoFocus
            required
            value={form.data.email}
            onChange={(e) => form.setData('email', e.target.value)}
            error={form.errors.email}
          />
          <FormField
            id="checkout-login-password"
            label={t('auth.password')}
            type="password"
            autoComplete="current-password"
            required
            revealable
            revealLabel={{
              show: t('auth.show_password'),
              hide: t('auth.hide_password'),
            }}
            labelAside={
              <a className="text-link" href="/forgot-password">
                {t('auth.forgot')}
              </a>
            }
            value={form.data.password}
            onChange={(e) => form.setData('password', e.target.value)}
            error={form.errors.password}
          />
          <label className="sf-co-signin-remember">
            <input
              type="checkbox"
              checked={form.data.remember}
              onChange={(e) => form.setData('remember', e.target.checked)}
            />
            {t('auth.remember')}
          </label>
          <SubmitButton processing={form.processing}>
            {t('auth.sign_in')}
          </SubmitButton>
        </form>
        <div className="store-auth-footer">
          {t('auth.new_here')}
          <a className="store-secondary" href="/register?return=checkout">
            {t('auth.create_link')}
          </a>
        </div>
      </div>
    </Dialog>
  );
}

function AccountRequired() {
  const t = useTranslation();
  return (
    <div className="sf-cartpage sf-co">
      <h1>{t('storefront.checkout_heading')}</h1>
      <section className="sf-acc-card sf-co-gate">
        <h2>{t('storefront.checkout_account_required_title')}</h2>
        <p className="sf-acc-hint">
          {t('storefront.checkout_account_required_text')}
        </p>
        <div className="sf-co-actions">
          <a className="sf-pdp-add" href="/login?return=checkout">
            {t('storefront.checkout_sign_in')}
          </a>
          <a className="sf-acc-secondary" href="/register?return=checkout">
            {t('storefront.checkout_create_account')}
          </a>
        </div>
      </section>
    </div>
  );
}

function CheckoutFormView({
  data,
  options,
}: {
  data: CheckoutPageData;
  options: CheckoutOptions;
}) {
  const t = useTranslation();
  const cart = data.cart!;
  const format = cart.currency_format;
  const contact = data.contact ?? null;
  const addresses = data.addresses ?? [];
  const firstSaved = data.defaults?.shipping ?? addresses[0]?.id ?? '';
  const form = useForm<CheckoutForm>({
    // One key per visit to the page: a repeated or retried submission places the order only once.
    submission_key: crypto.randomUUID(),
    email: contact?.email ?? '',
    first_name: contact?.first_name ?? '',
    last_name: contact?.last_name ?? '',
    phone: contact?.phone ?? '',
    shipping_address_id: firstSaved,
    shipping: blank(contact?.first_name, contact?.last_name),
    billing_same: true,
    billing_address_id: data.defaults?.billing ?? addresses[0]?.id ?? '',
    billing: blank(contact?.first_name, contact?.last_name),
    save_address: false,
    shipping_method_id: data.quote!.shipping_method_id ?? '',
    payment_method_id: data.quote!.payment_method_id ?? '',
    customer_note: '',
    accepted_pages: [],
    newsletter: false,
    invoice_requested: false,
    invoice_country_code: '',
    invoice_vat_number: '',
  });
  const [reading, setReading] = useState<CheckoutOptions['pages'][number]>();
  const [signingIn, setSigningIn] = useState(false);
  const [checkingInvoice, setCheckingInvoice] = useState(false);
  const [invoiceDetails, setInvoiceDetails] = useState<InvoiceDetails | null>(
    null,
  );
  const editInvoice = () => {
    form.setData('invoice_requested', false);
    form.clearErrors('invoice_requested');
    setCheckingInvoice(true);
  };
  const errors = form.errors as Record<string, string | undefined>;
  const [quote, setQuote] = useState<CheckoutQuote>(data.quote!);
  const [quoting, setQuoting] = useState(false);
  const [quoteError, setQuoteError] = useState('');
  // Coupons and gift cards go through the cart's own code routes; a new quote then shows what they change.
  const api = useCart();
  const [codes, setCodes] = useState(0);
  const [code, setCode] = useState('');
  const [codeError, setCodeError] = useState('');
  const [codeBusy, setCodeBusy] = useState(false);
  const sendCode = async (method: 'POST' | 'DELETE', value: string) => {
    setCodeBusy(true);
    setCodeError('');
    const reply = await api.code(method, value);
    setCodeBusy(false);
    if (reply.ok) {
      if (method === 'POST') setCode('');
      setCodes((n) => n + 1);
    } else {
      setCodeError(reply.message || t('storefront.cart_error'));
    }
  };
  const d = form.data;
  // Only what changes the price is sent for a new quote; amounts always come back from the server.
  const quoteInput = {
    // The contact decides which payment methods the buyer may use (a blacklisted e-mail or phone can lose some).
    email: d.email,
    phone: d.phone,
    shipping_address_id: d.shipping_address_id,
    shipping: d.shipping_address_id ? null : d.shipping,
    billing_same: d.billing_same,
    billing_address_id: d.billing_address_id,
    billing: d.billing_same || d.billing_address_id ? null : d.billing,
    shipping_method_id: d.shipping_method_id,
    payment_method_id: d.payment_method_id,
    // Not read by the server: a changed code asks for a new quote.
    codes,
  };
  const quoteKey = JSON.stringify(quoteInput);
  const lastKey = useRef(quoteKey);
  const sequence = useRef(0);
  useEffect(() => {
    if (quoteKey === lastKey.current) return;
    lastKey.current = quoteKey;
    const call = ++sequence.current;
    const timer = window.setTimeout(async () => {
      setQuoting(true);
      try {
        const response = await fetch('/checkout/quote', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrf(),
          },
          body: quoteKey,
        });
        if (call !== sequence.current) return;
        if (!response.ok) throw new Error(String(response.status));
        const next: CheckoutQuote = (await response.json()).quote;
        setQuote(next);
        setQuoteError('');
        // The server may preselect a delivery method or drop a payment method the new delivery method refuses.
        const shipping = next.shipping_method_id ?? '';
        const payment = next.payment_method_id ?? '';
        if (shipping !== form.data.shipping_method_id)
          form.setData('shipping_method_id', shipping);
        if (payment !== form.data.payment_method_id)
          form.setData('payment_method_id', payment);
      } catch {
        if (call === sequence.current)
          setQuoteError(t('storefront.cart_error'));
      } finally {
        if (call === sequence.current) setQuoting(false);
      }
    }, QUOTE_DELAY);
    return () => window.clearTimeout(timer);
    // The key covers every input of the quote.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [quoteKey]);

  const typedShipping = d.shipping_address_id === '';
  const typedBilling =
    quote.needs_shipping && !d.billing_same && d.billing_address_id === '';
  const general = Object.entries(errors)
    .filter(
      ([key, message]) =>
        message &&
        !FIELD_ERRORS.includes(key) &&
        !(typedShipping && key.startsWith('shipping.')) &&
        !(typedBilling && key.startsWith('billing.')),
    )
    .map(([, message]) => message as string);
  const totals = quote.totals;
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post('/checkout', {
      preserveScroll: true,
      onError: () =>
        requestAnimationFrame(() =>
          document
            .querySelector<HTMLElement>('.sf-co [aria-invalid="true"]')
            ?.focus(),
        ),
    });
  };
  const lines = cart.lines.filter((line) => line.total !== null);
  // Placing waits until every required step is filled in; the server checks everything again.
  const complete = (a: Address) =>
    REQUIRED.every((field) => a[field].trim() !== '') &&
    ((data.regions?.[a.country_code] ?? []).length === 0 ||
      a.region_code !== '');
  const missing = [
    (!contact && !d.email.trim()) ||
    !d.first_name.trim() ||
    !d.last_name.trim() ||
    !d.phone.trim()
      ? t('storefront.checkout_contact')
      : null,
    typedShipping && !complete(d.shipping)
      ? t(
          quote.needs_shipping
            ? 'storefront.checkout_shipping_address'
            : 'storefront.checkout_billing_address',
        )
      : null,
    typedBilling && !complete(d.billing)
      ? t('storefront.checkout_billing_address')
      : null,
    quote.shipping_required && !d.shipping_method_id
      ? t('storefront.checkout_delivery')
      : null,
    !d.payment_method_id ? t('storefront.checkout_payment') : null,
    options.pages.some(
      (page) => page.required && !d.accepted_pages.includes(page.id),
    )
      ? t('storefront.checkout_acceptance')
      : null,
  ].filter((step): step is string => step !== null);
  const blocked = quote.blocked || missing.length > 0;

  return (
    <div className="sf-cartpage sf-co">
      <h1>{t('storefront.checkout_heading')}</h1>
      <form className="sf-cart-layout" onSubmit={submit} noValidate>
        <div className="sf-co-sections">
          <section className="sf-acc-card" aria-labelledby="checkout-contact">
            <h2 id="checkout-contact">
              <span className="sf-co-icon">
                <AccountIcon name="profile" />
              </span>
              {t('storefront.checkout_contact')}
            </h2>
            {contact ? (
              <p className="sf-acc-hint">
                {t('storefront.checkout_signed_in', { email: contact.email })}
              </p>
            ) : (
              <p className="sf-acc-hint">
                {t('storefront.checkout_have_account')}{' '}
                <button
                  type="button"
                  className="sf-co-consent-link"
                  onClick={() => setSigningIn(true)}
                >
                  {t('storefront.checkout_sign_in')}
                </button>
              </p>
            )}
            <div className="sf-acc-grid">
              {!contact && (
                <Field
                  id="checkout-email"
                  type="email"
                  autoComplete="email"
                  label={t('customers.email')}
                  hint={t('storefront.checkout_email_hint')}
                  required
                  maxLength={254}
                  value={d.email}
                  onChange={(e) => form.setData('email', e.target.value)}
                  error={errors.email}
                />
              )}
              <Field
                id="checkout-phone"
                type="tel"
                autoComplete="tel"
                label={t('customers.phone')}
                required
                maxLength={40}
                value={d.phone}
                onChange={(e) => form.setData('phone', e.target.value)}
                error={errors.phone}
              />
              <Field
                id="checkout-first_name"
                autoComplete="given-name"
                label={t('customers.first_name')}
                required
                maxLength={100}
                value={d.first_name}
                onChange={(e) => form.setData('first_name', e.target.value)}
                error={errors.first_name}
              />
              <Field
                id="checkout-last_name"
                autoComplete="family-name"
                label={t('customers.last_name')}
                required
                maxLength={100}
                value={d.last_name}
                onChange={(e) => form.setData('last_name', e.target.value)}
                error={errors.last_name}
              />
            </div>
          </section>

          <section className="sf-acc-card" aria-labelledby="checkout-address">
            <h2 id="checkout-address">
              <span className="sf-co-icon">
                <AccountIcon name="addresses" />
              </span>
              {t(
                quote.needs_shipping
                  ? 'storefront.checkout_shipping_address'
                  : 'storefront.checkout_billing_address',
              )}
            </h2>
            <AddressChoice
              name="shipping"
              data={data}
              value={d.shipping_address_id}
              onChange={(id) => form.setData('shipping_address_id', id)}
              error={errors.shipping_address_id}
            />
            {typedShipping && (
              <AddressFields
                prefix="shipping"
                value={d.shipping}
                data={data}
                errors={errors}
                onChange={(address) => form.setData('shipping', address)}
              />
            )}
            {typedShipping && contact && (
              <label className="sf-acc-check">
                <input
                  type="checkbox"
                  checked={d.save_address}
                  onChange={(e) =>
                    form.setData('save_address', e.target.checked)
                  }
                />
                <span>{t('storefront.checkout_save_address')}</span>
              </label>
            )}
            {quote.needs_shipping && (
              <label className="sf-acc-check">
                <input
                  type="checkbox"
                  checked={d.billing_same}
                  onChange={(e) =>
                    form.setData('billing_same', e.target.checked)
                  }
                />
                <span>{t('storefront.checkout_billing_same')}</span>
              </label>
            )}
          </section>

          {quote.needs_shipping && !d.billing_same && (
            <section className="sf-acc-card" aria-labelledby="checkout-billing">
              <h2 id="checkout-billing">
                <span className="sf-co-icon">
                  <AccountIcon name="billing" />
                </span>
                {t('storefront.checkout_billing_address')}
              </h2>
              <AddressChoice
                name="billing"
                data={data}
                value={d.billing_address_id}
                onChange={(id) => form.setData('billing_address_id', id)}
                error={errors.billing_address_id}
              />
              {d.billing_address_id === '' && (
                <AddressFields
                  prefix="billing"
                  value={d.billing}
                  data={data}
                  errors={errors}
                  onChange={(address) => form.setData('billing', address)}
                />
              )}
            </section>
          )}

          {quote.shipping_required && (
            <section
              className="sf-acc-card"
              aria-labelledby="checkout-delivery"
              aria-busy={quoting}
            >
              <h2 id="checkout-delivery">
                <span className="sf-co-icon">
                  <AccountIcon name="delivery" />
                </span>
                {t('storefront.checkout_delivery')}
              </h2>
              {quote.shipping_methods.length === 0 ? (
                <p className="sf-acc-hint">
                  {t(
                    hasAddress(d.shipping_address_id, d.shipping)
                      ? 'storefront.checkout_no_shipping'
                      : 'storefront.checkout_delivery_needs_address',
                  )}
                </p>
              ) : (
                <Options
                  name="shipping_method_id"
                  legend={t('storefront.checkout_delivery')}
                  value={d.shipping_method_id}
                  onChange={(id) => form.setData('shipping_method_id', id)}
                  options={quote.shipping_methods.map((m) => ({
                    id: m.id,
                    name: m.name,
                    amount: m.amount,
                    image: m.image,
                  }))}
                  format={format}
                />
              )}
              <FieldError
                id="checkout-shipping_method_id-error"
                message={errors.shipping_method_id}
              />
            </section>
          )}

          <section className="sf-acc-card" aria-labelledby="checkout-payment">
            <h2 id="checkout-payment">
              <span className="sf-co-icon">
                <AccountIcon name="payment" />
              </span>
              {t('storefront.checkout_payment')}
            </h2>
            {quote.payment_methods.length === 0 ? (
              <p className="sf-acc-hint">
                {t('storefront.checkout_no_payment')}
              </p>
            ) : (
              <Options
                name="payment_method_id"
                legend={t('storefront.checkout_payment')}
                value={d.payment_method_id}
                onChange={(id) => form.setData('payment_method_id', id)}
                options={quote.payment_methods.map((m) => ({
                  id: m.id,
                  name: m.name,
                  amount: m.fee,
                  prefix: '+',
                  description: m.description,
                  image: m.image,
                }))}
                format={format}
              />
            )}
            <FieldError
              id="checkout-payment_method_id-error"
              message={errors.payment_method_id}
            />
          </section>

          <section className="sf-acc-card" aria-labelledby="checkout-note">
            <div className="sf-acc-field">
              <h2 id="checkout-note">
                <span className="sf-co-icon">
                  <AccountIcon name="notes" />
                </span>
                <label htmlFor="checkout-customer_note">
                  {t('storefront.checkout_note')}
                </label>
              </h2>
              <textarea
                id="checkout-customer_note"
                rows={3}
                maxLength={5000}
                value={d.customer_note}
                aria-describedby="checkout-customer_note-hint"
                aria-invalid={errors.customer_note ? true : undefined}
                onChange={(e) => form.setData('customer_note', e.target.value)}
              />
              <p id="checkout-customer_note-hint" className="sf-acc-hint">
                {t('storefront.checkout_note_hint')}
              </p>
              <FieldError
                id="checkout-customer_note-error"
                message={errors.customer_note}
              />
            </div>
          </section>
        </div>

        <aside
          className="sf-cart-summary sf-co-summary"
          aria-busy={quoting}
          aria-labelledby="checkout-summary"
        >
          <h2 id="checkout-summary">{t('storefront.checkout_summary')}</h2>
          <ul className="sf-co-lines">
            {lines.map((line) => (
              <li key={line.id}>
                <span>
                  {line.name} <small>×{line.quantity}</small>
                </span>
                <Money amount={line.total!} format={format} inline />
              </li>
            ))}
          </ul>
          {totals && (
            <dl className={quoting ? 'is-updating' : undefined}>
              <div>
                <dt>{t('storefront.cart_subtotal')}</dt>
                <dd>
                  <Money amount={totals.subtotal} format={format} inline />
                </dd>
              </div>
              {Number(totals.discount) > 0 && (
                <div>
                  <dt>
                    {t('storefront.checkout_discount')}
                    {quote.discounts.length > 0 && (
                      <small className="sf-co-sub">
                        {quote.discounts.join(', ')}
                      </small>
                    )}
                  </dt>
                  <dd>
                    −<Money amount={totals.discount} format={format} inline />
                  </dd>
                </div>
              )}
              {quote.shipping_required && (
                <div className="sf-cart-shipping">
                  <dt>{t('storefront.cart_shipping')}</dt>
                  <dd>
                    {totals.shipping === null ? (
                      '—'
                    ) : Number(totals.shipping) === 0 ? (
                      t('storefront.checkout_shipping_free')
                    ) : (
                      <Money amount={totals.shipping} format={format} inline />
                    )}
                  </dd>
                </div>
              )}
              {Number(totals.payment_fee) > 0 && (
                <div>
                  <dt>{t('storefront.checkout_payment_fee')}</dt>
                  <dd>
                    <Money amount={totals.payment_fee} format={format} inline />
                  </dd>
                </div>
              )}
              {Number(totals.tax_added) > 0 && (
                <div>
                  <dt>{t('storefront.checkout_tax')}</dt>
                  <dd>
                    <Money amount={totals.tax_added} format={format} inline />
                  </dd>
                </div>
              )}
              <div className="is-total">
                <dt>
                  {t('storefront.cart_total')}
                  {Number(totals.tax_included) > 0 && (
                    <InfoTip
                      className="sf-cart-vat"
                      label={t('storefront.cart_vat_info')}
                      text={t('storefront.cart_vat_included')}
                    />
                  )}
                </dt>
                <dd>
                  <Money amount={totals.total} format={format} />
                </dd>
              </div>
              {Number(totals.gift_card) > 0 && quote.gift_card && (
                <>
                  <div>
                    <dt>
                      {t('storefront.cart_gift_card', {
                        code: quote.gift_card.code,
                      })}
                    </dt>
                    <dd>
                      −
                      <Money amount={totals.gift_card} format={format} inline />
                    </dd>
                  </div>
                  <div className="sf-co-due">
                    <dt>{t('storefront.checkout_due')}</dt>
                    <dd>
                      <Money amount={totals.due} format={format} />
                    </dd>
                  </div>
                </>
              )}
            </dl>
          )}
          {(quote.coupon || quote.gift_card) && (
            <ul className="sf-co-codes">
              {quote.coupon && (
                <CodeLine
                  label={t('storefront.cart_coupon', {
                    code: quote.coupon.code,
                  })}
                  problem={quote.coupon.problem}
                  onRemove={() => sendCode('DELETE', 'coupon')}
                />
              )}
              {quote.gift_card && (
                <CodeLine
                  label={t('storefront.cart_gift_card', {
                    code: quote.gift_card.code,
                  })}
                  problem={quote.gift_card.problem}
                  onRemove={() => sendCode('DELETE', 'gift_card')}
                />
              )}
            </ul>
          )}
          {cart.codes && (
            <details className="sf-cart-code" open={codeError !== ''}>
              <summary>
                <TicketIcon />
                {t('storefront.cart_code_toggle')}
              </summary>
              <div className="sf-co-code-form">
                <input
                  value={code}
                  onChange={(event) => setCode(event.target.value)}
                  onKeyDown={(event) => {
                    // Inside the checkout form: Enter applies the code instead of placing the order.
                    if (event.key === 'Enter') {
                      event.preventDefault();
                      if (code.trim()) sendCode('POST', code.trim());
                    }
                  }}
                  placeholder={t('storefront.cart_code_label')}
                  aria-label={t('storefront.cart_code_label')}
                  aria-invalid={codeError !== ''}
                  maxLength={40}
                  autoComplete="off"
                />
                {codeError && (
                  <p className="sf-cart-problem" role="alert">
                    {codeError}
                  </p>
                )}
                <button
                  type="button"
                  className="sf-cart-button"
                  disabled={codeBusy || !code.trim()}
                  onClick={() => sendCode('POST', code.trim())}
                >
                  {t('storefront.cart_code_apply')}
                </button>
              </div>
            </details>
          )}
          {quote.blocked && (
            <p className="sf-cart-problem" role="alert">
              {t('storefront.checkout_cart_changed')}{' '}
              <a href="/cart">{t('storefront.checkout_review_cart')}</a>
            </p>
          )}
          {quoteError && (
            <p className="sf-cart-problem" role="alert">
              {quoteError}
            </p>
          )}
          {general.length > 0 && (
            <ul className="sf-co-errors" role="alert">
              {general.map((message) => (
                <li key={message}>{message}</li>
              ))}
            </ul>
          )}
          {(options.pages.length > 0 ||
            options.newsletter ||
            options.invoice) && (
            <div className="sf-co-consents">
              {options.invoice && (
                <label className="sf-co-consent">
                  <input
                    type="checkbox"
                    checked={d.invoice_requested}
                    onChange={(e) =>
                      e.target.checked
                        ? editInvoice()
                        : form.setData('invoice_requested', false)
                    }
                  />
                  <span>{t('storefront.checkout_invoice')}</span>
                </label>
              )}
              {options.invoice && d.invoice_requested && invoiceDetails && (
                <div className="sf-co-invoice-verified" role="status">
                  <div className="sf-co-invoice-verified-head">
                    <span>
                      {t(
                        invoiceDetails.provider === 'local'
                          ? 'checkout.basic_checked'
                          : 'checkout.verified',
                      )}
                    </span>
                    <button type="button" onClick={editInvoice}>
                      {t('common.edit')}
                    </button>
                  </div>
                  <strong>
                    {invoiceDetails.country_code} {invoiceDetails.vat_number}
                  </strong>
                  {invoiceDetails.business_name && (
                    <span>{invoiceDetails.business_name}</span>
                  )}
                </div>
              )}
              {errors.invoice_requested && (
                <p className="sf-cart-problem" role="alert">
                  {errors.invoice_requested}
                </p>
              )}
              {options.pages.map((page) => (
                <label key={page.id} className="sf-co-consent">
                  <input
                    type="checkbox"
                    required={page.required}
                    aria-required={page.required || undefined}
                    checked={d.accepted_pages.includes(page.id)}
                    onChange={(e) =>
                      form.setData(
                        'accepted_pages',
                        e.target.checked
                          ? [...d.accepted_pages, page.id]
                          : d.accepted_pages.filter((id) => id !== page.id),
                      )
                    }
                  />
                  <span>
                    {t('storefront.checkout_accept')}{' '}
                    <button
                      type="button"
                      className="sf-co-consent-link"
                      onClick={(e) => {
                        // Reading the page does not tick its box.
                        e.preventDefault();
                        setReading(page);
                      }}
                    >
                      {page.name}
                    </button>
                    {page.required && (
                      <span className="sf-co-consent-required" aria-hidden>
                        {' '}
                        *
                      </span>
                    )}
                  </span>
                </label>
              ))}
              {options.newsletter && (
                <label className="sf-co-consent">
                  <input
                    type="checkbox"
                    checked={d.newsletter}
                    onChange={(e) =>
                      form.setData('newsletter', e.target.checked)
                    }
                  />
                  <span>{options.newsletter.label}</span>
                </label>
              )}
            </div>
          )}
          {checkingInvoice &&
            createPortal(
              <CheckoutInvoiceDialog
                countries={data.invoice_countries ?? data.countries ?? []}
                initial={{
                  country_code:
                    d.invoice_country_code || d.shipping.country_code || 'GR',
                  vat_number: d.invoice_vat_number,
                }}
                signedIn={contact !== null}
                greekSignIn={data.invoice_greek_sign_in ?? true}
                onClose={() => setCheckingInvoice(false)}
                onSignIn={() => {
                  setCheckingInvoice(false);
                  setSigningIn(true);
                }}
                onVerified={(details) => {
                  form.setData((previous) => ({
                    ...previous,
                    invoice_requested: true,
                    invoice_country_code: details.country_code,
                    invoice_vat_number: details.vat_number,
                  }));
                  setInvoiceDetails(details);
                  setCheckingInvoice(false);
                }}
              />,
              document.querySelector<HTMLElement>('.sf-default') ??
                document.body,
            )}
          {signingIn &&
            // Outside the checkout's own <form>: forms cannot nest, and a nested one would send the password in the address.
            createPortal(
              <CheckoutSignIn onClose={() => setSigningIn(false)} />,
              document.querySelector<HTMLElement>('.sf-default') ??
                document.body,
            )}
          {reading && (
            <Dialog
              title={reading.name}
              className="sf-co-page-dialog"
              onClose={() => setReading(undefined)}
            >
              <div
                className="sf-prose sf-page-content"
                dangerouslySetInnerHTML={{ __html: reading.content }}
              />
            </Dialog>
          )}
          <div className="sf-cart-next">
            <button
              type="submit"
              className="sf-cart-button"
              disabled={form.processing || quoting || blocked}
              aria-describedby={
                missing.length > 0 ? 'checkout-missing' : undefined
              }
            >
              {t(
                form.processing
                  ? 'storefront.checkout_placing'
                  : 'storefront.checkout_place',
              )}
            </button>
          </div>
          {missing.length > 0 && !quote.blocked && (
            <p id="checkout-missing" className="sf-co-missing">
              {t('storefront.checkout_missing', { steps: missing.join(', ') })}
            </p>
          )}
          <a className="sf-co-back" href="/cart">
            {t('storefront.checkout_back')}
          </a>
        </aside>
      </form>
    </div>
  );
}

/** An entered coupon or gift card: its code, why it does not apply (if so) and a way to remove it. */
function CodeLine({
  label,
  problem,
  onRemove,
}: {
  label: string;
  problem: string | null;
  onRemove: () => void;
}) {
  const t = useTranslation();
  return (
    <li>
      <span>
        {label}
        {problem && (
          <span className="sf-cart-problem" role="status">
            {problem}
          </span>
        )}
      </span>
      <button
        type="button"
        className="sf-cart-code-remove"
        aria-label={t('storefront.cart_code_remove', { code: label })}
        onClick={onRemove}
      >
        <TrashIcon />
      </button>
    </li>
  );
}

function hasAddress(savedId: string, address: Address) {
  return savedId !== '' || address.country_code !== '';
}

function FieldError({ id, message }: { id: string; message?: string }) {
  return message ? (
    <p id={id} role="alert" className="sf-acc-error">
      {message}
    </p>
  ) : null;
}

/** A customer's saved addresses to choose from, plus a new one; guests only type one. */
function AddressChoice({
  name,
  data,
  value,
  onChange,
  error,
}: {
  name: 'shipping' | 'billing';
  data: CheckoutPageData;
  value: string;
  onChange: (id: string) => void;
  error?: string;
}) {
  const t = useTranslation();
  const addresses = data.addresses ?? [];
  if (addresses.length === 0) return null;
  return (
    <fieldset className="sf-co-options">
      <legend className="sf-visually-hidden">
        {t(
          name === 'shipping'
            ? 'storefront.checkout_shipping_address'
            : 'storefront.checkout_billing_address',
        )}
      </legend>
      {addresses.map((address) => (
        <label key={address.id} className="sf-co-option">
          <input
            type="radio"
            name={`${name}_address_id`}
            checked={value === address.id}
            onChange={() => onChange(address.id)}
          />
          <span>
            {address.label && <strong>{address.label}</strong>}
            <small>{address.lines.join(', ')}</small>
          </span>
        </label>
      ))}
      <label className="sf-co-option">
        <input
          type="radio"
          name={`${name}_address_id`}
          checked={value === ''}
          onChange={() => onChange('')}
        />
        <span>
          <strong>{t('storefront.checkout_new_address')}</strong>
        </span>
      </label>
      <FieldError id={`checkout-${name}_address_id-error`} message={error} />
    </fieldset>
  );
}

function AddressFields({
  prefix,
  value,
  data,
  errors,
  onChange,
}: {
  prefix: 'shipping' | 'billing';
  value: Address;
  data: CheckoutPageData;
  errors: Record<string, string | undefined>;
  onChange: (address: Address) => void;
}) {
  const t = useTranslation();
  const regions = data.regions?.[value.country_code] ?? [];
  const set = (field: keyof Address, next: string) =>
    onChange({ ...value, [field]: next });
  const text = (
    field: keyof Address,
    label: string,
    extra: { required?: boolean; maxLength: number; autoComplete?: string },
  ) => (
    <Field
      id={`${prefix}-${field}`}
      label={label}
      required={extra.required}
      maxLength={extra.maxLength}
      autoComplete={extra.autoComplete && `${prefix} ${extra.autoComplete}`}
      value={value[field]}
      onChange={(e) => set(field, e.target.value)}
      error={errors[`${prefix}.${field}`]}
    />
  );
  const error = (field: string) => errors[`${prefix}.${field}`];
  return (
    <div className="sf-acc-grid sf-co-address">
      {text('first_name', t('customers.first_name'), {
        required: true,
        maxLength: 100,
        autoComplete: 'given-name',
      })}
      {text('last_name', t('customers.last_name'), {
        required: true,
        maxLength: 100,
        autoComplete: 'family-name',
      })}
      {text('line1', t('customers.line1'), {
        required: true,
        maxLength: 200,
        autoComplete: 'address-line1',
      })}
      {text('street_number', t('customers.street_number'), { maxLength: 20 })}
      {text('city', t('customers.city'), {
        required: true,
        maxLength: 100,
        autoComplete: 'address-level2',
      })}
      {text('postal_code', t('customers.postal_code'), {
        required: true,
        maxLength: 20,
        autoComplete: 'postal-code',
      })}
      <div className="sf-acc-field">
        <label htmlFor={`${prefix}-country_code`}>
          {t('customers.country')}
          <span aria-hidden="true"> *</span>
        </label>
        <select
          id={`${prefix}-country_code`}
          required
          autoComplete={`${prefix} country`}
          value={value.country_code}
          aria-invalid={error('country_code') ? true : undefined}
          aria-describedby={
            error('country_code') ? `${prefix}-country_code-error` : undefined
          }
          // A region belongs to one country.
          onChange={(e) =>
            onChange({
              ...value,
              country_code: e.target.value,
              region_code: '',
            })
          }
        >
          <option value="" disabled>
            {t('customers.country_choose')}
          </option>
          {(data.countries ?? []).map((c) => (
            <option key={c.code} value={c.code}>
              {c.name}
            </option>
          ))}
        </select>
        <FieldError
          id={`${prefix}-country_code-error`}
          message={error('country_code')}
        />
      </div>
      {regions.length > 0 && (
        <div className="sf-acc-field">
          <label htmlFor={`${prefix}-region_code`}>
            {t('customers.region')}
            <span aria-hidden="true"> *</span>
          </label>
          <select
            id={`${prefix}-region_code`}
            required
            value={value.region_code}
            aria-invalid={error('region_code') ? true : undefined}
            onChange={(e) => set('region_code', e.target.value)}
          >
            <option value="" disabled>
              {t('customers.region_choose')}
            </option>
            {regions.map((r) => (
              <option key={r.code} value={r.code}>
                {r.name}
              </option>
            ))}
          </select>
          <FieldError
            id={`${prefix}-region_code-error`}
            message={error('region_code')}
          />
        </div>
      )}
      {text('line2', t('customers.line2'), {
        maxLength: 200,
        autoComplete: 'address-line2',
      })}
      {text('company', t('customers.company'), {
        maxLength: 150,
        autoComplete: 'organization',
      })}
      {text('floor', t('customers.floor'), { maxLength: 20 })}
      {text('bell_name', t('customers.bell_name'), { maxLength: 100 })}
      <div className="sf-acc-field sf-co-wide">
        <label htmlFor={`${prefix}-notes`}>
          {t('customers.address_notes')}
        </label>
        <textarea
          id={`${prefix}-notes`}
          rows={2}
          maxLength={500}
          value={value.notes}
          aria-invalid={error('notes') ? true : undefined}
          aria-describedby={`${prefix}-notes-hint`}
          onChange={(e) => set('notes', e.target.value)}
        />
        <p id={`${prefix}-notes-hint`} className="sf-acc-hint">
          {t('customers.address_notes_hint')}
        </p>
        <FieldError id={`${prefix}-notes-error`} message={error('notes')} />
      </div>
    </div>
  );
}

/** Radio choices with their price: delivery methods, or payment methods with their fee and instructions. */
function Options({
  name,
  legend,
  value,
  onChange,
  options,
  format,
}: {
  name: string;
  legend: string;
  value: string;
  onChange: (id: string) => void;
  options: {
    id: string;
    name: string;
    amount: string | null;
    prefix?: string;
    description?: string;
    image?: PublicImage | null;
  }[];
  format: CurrencyFormat;
}) {
  return (
    <fieldset className="sf-co-options">
      <legend className="sf-visually-hidden">{legend}</legend>
      {options.map((option) => (
        <label key={option.id} className="sf-co-option">
          <input
            type="radio"
            name={name}
            checked={value === option.id}
            onChange={() => onChange(option.id)}
          />
          <span>
            <strong>{option.name}</strong>
            {value === option.id && option.description && (
              <small className="sf-co-description">{option.description}</small>
            )}
          </span>
          {option.image && (
            <span className="sf-co-logo">
              <Picture image={option.image} alt="" />
            </span>
          )}
          {option.amount !== null && (
            <span className="sf-co-amount">
              {option.prefix}
              <Money amount={option.amount} format={format} inline />
            </span>
          )}
        </label>
      ))}
    </fieldset>
  );
}

/** Copies a value and says so for a moment; the button stays usable by keyboard and screen readers. */
function CopyButton({ label, value }: { label: string; value: string }) {
  const t = useTranslation();
  const [copied, setCopied] = useState(false);
  return (
    <button
      type="button"
      className="sf-ty-copy"
      aria-label={t('storefront.checkout_copy', { label })}
      onClick={() => {
        void navigator.clipboard?.writeText(value).catch(() => {});
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1600);
      }}
    >
      {copied ? (
        <>
          <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="M5 12.5l4.5 4.5L19 7.5" />
          </svg>
          <span role="status">{t('storefront.checkout_copied')}</span>
        </>
      ) : (
        <svg
          aria-hidden="true"
          viewBox="0 0 24 24"
          width="18"
          height="18"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.8"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <rect x="9" y="9" width="11" height="11" rx="2" />
          <path d="M5 15V6a2 2 0 0 1 2-2h8" />
        </svg>
      )}
    </button>
  );
}

function TyIcon({
  name,
}: {
  name: 'box' | 'pin' | 'truck' | 'file' | 'item' | 'note';
}) {
  const paths = {
    box: (
      <>
        <path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z" />
        <path d="M4 7.5l8 4.5 8-4.5M12 12v9" />
      </>
    ),
    pin: (
      <>
        <path d="M12 21s7-6.2 7-11.5a7 7 0 0 0-14 0C5 14.8 12 21 12 21z" />
        <circle cx="12" cy="9.5" r="2.5" />
      </>
    ),
    truck: (
      <>
        <path d="M3 7h11v9H3zM14 10h4l3 3v3h-7" />
        <circle cx="7.5" cy="17.5" r="1.8" />
        <circle cx="17.5" cy="17.5" r="1.8" />
      </>
    ),
    file: (
      <>
        <path d="M6 3h8l4 4v14H6z" />
        <path d="M14 3v4h4M9 12h6M9 16h6" />
      </>
    ),
    note: (
      <>
        <path d="M4 4h16v13H9l-5 4V4Z" />
        <path d="M8 8h8M8 12h5" />
      </>
    ),
    item: (
      <>
        <rect x="5" y="5" width="14" height="10" rx="1.5" />
        <path d="M3 18.5h18" />
      </>
    ),
  };
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="22"
      height="22"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {paths[name]}
    </svg>
  );
}

/** The placed order: what happens next, how to pay, where it goes, and what was bought. */
function Confirmation({ placed }: { placed: CheckoutConfirmation }) {
  const t = useTranslation();
  const format = placed.currency_format;
  const totals = placed.totals;
  const payment = placed.payment;
  const due = Number(totals.due) > 0;
  const address = placed.address;
  const amount = (value: string) => (
    <Money amount={value} format={format} inline />
  );
  return (
    <div className="sf-ty">
      <header className="sf-ty-head">
        <span className="sf-ty-check" aria-hidden="true">
          <svg
            viewBox="0 0 24 24"
            width="30"
            height="30"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.4"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="M5 12.5l4.5 4.5L19 7.5" />
          </svg>
        </span>
        <div>
          <h1>{t('storefront.checkout_thanks')}</h1>
          <p>
            {t('storefront.checkout_updates', { email: '\u0000' })
              .split('\u0000')
              .flatMap((part, index) =>
                index === 0
                  ? [part]
                  : [<strong key="email">{placed.email}</strong>, part],
              )}
          </p>
        </div>
      </header>
      <div className="sf-ty-layout">
        <div className="sf-ty-main">
          <section className="sf-ty-card" aria-labelledby="ty-order">
            <div className="sf-ty-card-head is-divided">
              <span className="sf-ty-icon">
                <TyIcon name="box" />
              </span>
              <h2 id="ty-order">
                {t('storefront.checkout_order_number', {
                  number: placed.number,
                })}
              </h2>
              <span className={`sf-ty-state is-${payment.state}`}>
                {t(`storefront.checkout_state_${payment.state}`)}
              </span>
            </div>
            {payment.name && (
              <div className="sf-ty-fact">
                <span className="sf-ty-label">
                  {t('storefront.checkout_payment')}
                </span>
                <strong>{payment.name}</strong>
              </div>
            )}
            {payment.state !== 'paid' && payment.pay_url && (
              <div className="sf-ty-pay">
                <p>{t('storefront.checkout_pay_pending')}</p>
                <a className="sf-ty-primary" href={payment.pay_url}>
                  {t('storefront.checkout_pay_now')}
                </a>
              </div>
            )}
            {payment.instructions && (
              <p className="sf-ty-instructions">{payment.instructions}</p>
            )}
            {payment.instructions && due && (
              <dl className="sf-ty-copyrows">
                {[
                  {
                    label: t('storefront.checkout_reference'),
                    shown: placed.number,
                    value: placed.number,
                  },
                  {
                    label: t('storefront.checkout_due'),
                    shown: amount(totals.due),
                    value: totals.due,
                  },
                ].map((row) => (
                  <div key={row.label}>
                    <dt>{row.label}</dt>
                    <dd>{row.shown}</dd>
                    <CopyButton label={row.label} value={row.value} />
                  </div>
                ))}
              </dl>
            )}
          </section>
          {(address || placed.shipping_method) && (
            <section className="sf-ty-card" aria-labelledby="ty-address">
              {address && (
                <>
                  <div className="sf-ty-card-head">
                    <span className="sf-ty-icon">
                      <TyIcon name="pin" />
                    </span>
                    <h2 id="ty-address">{t('storefront.checkout_address')}</h2>
                  </div>
                  <div className="sf-ty-address">
                    <address>
                      <strong>{address.name}</strong>
                      {address.lines.map((line) => (
                        <span key={line}>{line}</span>
                      ))}
                      {address.phone && (
                        <span className="sf-ty-phone">{address.phone}</span>
                      )}
                    </address>
                    {address.extras.length > 0 && (
                      <dl>
                        {address.extras.map((extra) => (
                          <div key={extra.label}>
                            <dt>{extra.label}</dt>
                            <dd>{extra.value}</dd>
                          </div>
                        ))}
                      </dl>
                    )}
                  </div>
                </>
              )}
              {placed.shipping_method && (
                <div className="sf-ty-method">
                  <span className="sf-ty-icon">
                    <TyIcon name="truck" />
                  </span>
                  <div className="sf-ty-fact">
                    <span className="sf-ty-label">
                      {t('storefront.checkout_delivery')}
                    </span>
                    <strong>{placed.shipping_method}</strong>
                  </div>
                </div>
              )}
            </section>
          )}
          {placed.note.trim() !== '' && (
            <section className="sf-ty-card" aria-labelledby="ty-comments">
              <div className="sf-ty-card-head">
                <span className="sf-ty-icon">
                  <TyIcon name="note" />
                </span>
                <h2 id="ty-comments">{t('storefront.checkout_note')}</h2>
              </div>
              <p className="sf-ty-comments">{placed.note}</p>
            </section>
          )}
          {placed.invoice_requested && (
            <p className="sf-ty-note">
              <TyIcon name="file" />
              {t('storefront.invoice_requested_note')}
            </p>
          )}
          <div className="sf-ty-actions">
            {placed.account_url && (
              <a className="sf-ty-primary" href={placed.account_url}>
                {t('storefront.checkout_view_order')}
              </a>
            )}
            <a className="sf-ty-secondary" href="/">
              {t('storefront.cart_continue')}
            </a>
          </div>
        </div>
        <aside className="sf-ty-summary" aria-labelledby="ty-summary">
          <div className="sf-ty-summary-body">
            <h2 id="ty-summary">{t('storefront.checkout_summary')}</h2>
            <ul className="sf-ty-lines">
              {placed.lines.map((line, index) => (
                <li key={index}>
                  <span className="sf-ty-thumb">
                    {line.image ? (
                      <Picture image={line.image} alt="" />
                    ) : (
                      <TyIcon name="item" />
                    )}
                  </span>
                  <div>
                    <div className="sf-ty-line-head">
                      {line.url ? (
                        <a href={line.url}>{line.name}</a>
                      ) : (
                        <span>{line.name}</span>
                      )}
                      {amount(line.total)}
                    </div>
                    {line.variation && (
                      <small>
                        {line.variation.split(' · ').map((part) => (
                          <span key={part}>{part}</span>
                        ))}
                      </small>
                    )}
                    <small className="sf-ty-qty">×{line.quantity}</small>
                  </div>
                </li>
              ))}
            </ul>
            <dl className="sf-ty-totals">
              <div>
                <dt>{t('storefront.cart_subtotal')}</dt>
                <dd>{amount(totals.subtotal)}</dd>
              </div>
              {Number(totals.discount) > 0 && (
                <div>
                  <dt>{t('storefront.checkout_discount')}</dt>
                  <dd>−{amount(totals.discount)}</dd>
                </div>
              )}
              {totals.shipping !== null && (
                <div>
                  <dt>{t('storefront.cart_shipping')}</dt>
                  <dd>{amount(totals.shipping)}</dd>
                </div>
              )}
              {Number(totals.payment_fee) > 0 && (
                <div>
                  <dt>{t('storefront.checkout_payment_fee')}</dt>
                  <dd>{amount(totals.payment_fee)}</dd>
                </div>
              )}
              {Number(totals.tax_added) > 0 && (
                <div>
                  <dt>{t('storefront.checkout_tax')}</dt>
                  <dd>{amount(totals.tax_added)}</dd>
                </div>
              )}
              {totals.due !== totals.total && (
                <div>
                  <dt>{t('storefront.checkout_due')}</dt>
                  <dd>{amount(totals.due)}</dd>
                </div>
              )}
            </dl>
          </div>
          <div className="sf-ty-total">
            <span>{t('storefront.cart_total')}</span>
            <Money amount={totals.total} format={format} />
          </div>
        </aside>
      </div>
    </div>
  );
}
