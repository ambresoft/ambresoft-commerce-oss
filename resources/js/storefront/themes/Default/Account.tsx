import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { AccountPageData, PublicBlock } from '../../types';
import AccountAddresses from './AccountAddresses';
import AccountNewsletters from './AccountNewsletters';
import AccountOrder from './AccountOrder';
import {
  AccountMenu,
  Card,
  Field,
  Notices,
  useDateFormat,
} from './AccountParts';
import AccountReviews from './AccountReviews';
import Dialog from './Dialog';

/** Errors the open section shows beside its own fields. */
const ownErrors: Record<AccountPageData['section'], string[]> = {
  profile: ['first_name', 'last_name', 'phone', 'confirmation'],
  orders: [],
  order: [],
  newsletters: [],
  addresses: [
    'label',
    'first_name',
    'last_name',
    'company',
    'line1',
    'line2',
    'city',
    'postal_code',
    'country_code',
    'region_code',
    'phone',
    'floor',
    'bell_name',
    'notes',
  ],
  reviews: ['rating', 'title', 'body', 'product'],
  password: ['current_password', 'password', 'password_confirmation'],
};

type Profile = NonNullable<AccountPageData['profile']>;

function Profile({ profile }: { profile: Profile }) {
  const t = useTranslation();
  const format = useDateFormat();
  const form = useForm({
    version: profile.version,
    first_name: profile.first_name,
    last_name: profile.last_name,
    phone: profile.phone,
  });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.patch('/account', { preserveScroll: true });
  };
  return (
    <Card
      title={t('account.profile')}
      hint={t('account.profile_hint', {
        email: profile.email,
        date: format(profile.since),
      })}
    >
      <form className="sf-acc-form" onSubmit={submit}>
        <div className="sf-acc-grid">
          <Field
            id="first_name"
            label={t('auth.first_name')}
            autoComplete="given-name"
            required
            maxLength={100}
            value={form.data.first_name}
            onChange={(e) => form.setData('first_name', e.target.value)}
            error={form.errors.first_name}
          />
          <Field
            id="last_name"
            label={t('auth.last_name')}
            autoComplete="family-name"
            required
            maxLength={100}
            value={form.data.last_name}
            onChange={(e) => form.setData('last_name', e.target.value)}
            error={form.errors.last_name}
          />
          <Field
            id="email"
            label={t('auth.email')}
            value={profile.email}
            readOnly
            hint={t('account.email_hint')}
          />
          <Field
            id="phone"
            label={t('customers.phone')}
            type="tel"
            autoComplete="tel"
            maxLength={40}
            value={form.data.phone}
            onChange={(e) => form.setData('phone', e.target.value)}
            error={form.errors.phone}
          />
        </div>
        <button className="sf-pdp-add" disabled={form.processing}>
          {t(form.processing ? 'common.wait' : 'common.save')}
        </button>
      </form>
    </Card>
  );
}

/** Closing the account is a soft delete; typing the e-mail address confirms it. */
function DeleteAccount({ profile }: { profile: Profile }) {
  const t = useTranslation();
  const [open, setOpen] = useState(false);
  const form = useForm({ version: profile.version, confirmation: '' });
  return (
    <Card
      danger
      title={t('account.delete_account')}
      hint={t('account.delete_account_hint')}
    >
      <button
        type="button"
        className="sf-acc-danger"
        onClick={() => setOpen(true)}
      >
        {t('account.delete_account')}
      </button>
      {open && (
        <Dialog
          title={t('account.delete_account')}
          onClose={() => setOpen(false)}
        >
          <form
            className="sf-acc-form"
            onSubmit={(e) => {
              e.preventDefault();
              form.delete('/account', { preserveScroll: true });
            }}
          >
            <p>{t('account.delete_account_hint')}</p>
            <Field
              id="delete-confirmation"
              label={t('account.delete_confirmation_label', {
                email: profile.email,
              })}
              type="email"
              autoComplete="off"
              required
              value={form.data.confirmation}
              onChange={(e) => form.setData('confirmation', e.target.value)}
              error={form.errors.confirmation ?? form.errors.version}
            />
            <div className="sf-wl-confirm">
              <button
                type="button"
                className="sf-acc-secondary"
                onClick={() => setOpen(false)}
              >
                {t('storefront.wishlist_cancel')}
              </button>
              <button
                className="sf-acc-danger is-filled"
                disabled={form.processing || form.data.confirmation === ''}
              >
                {t(form.processing ? 'common.wait' : 'account.delete_confirm')}
              </button>
            </div>
          </form>
        </Dialog>
      )}
    </Card>
  );
}

function Password({ staffRule }: { staffRule: boolean }) {
  const t = useTranslation();
  const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
  });
  return (
    <Card title={t('account.nav_password')}>
      <form
        className="sf-acc-form sf-acc-narrow"
        onSubmit={(e) => {
          e.preventDefault();
          form.put('/account/password', {
            preserveScroll: true,
            onFinish: () => form.reset(),
          });
        }}
      >
        <Field
          id="current_password"
          label={t('account.current_password')}
          type="password"
          autoComplete="current-password"
          revealable
          required
          value={form.data.current_password}
          onChange={(e) => form.setData('current_password', e.target.value)}
          error={form.errors.current_password}
        />
        <Field
          id="password"
          label={t('auth.new_password')}
          type="password"
          autoComplete="new-password"
          revealable
          hint={t(
            staffRule ? 'auth.password_hint' : 'auth.customer_password_hint',
          )}
          required
          value={form.data.password}
          onChange={(e) => form.setData('password', e.target.value)}
          error={form.errors.password}
        />
        <Field
          id="password_confirmation"
          label={t('auth.confirm_new')}
          type="password"
          autoComplete="new-password"
          revealable
          required
          value={form.data.password_confirmation}
          onChange={(e) =>
            form.setData('password_confirmation', e.target.value)
          }
          error={form.errors.password_confirmation}
        />
        <p className="sf-acc-hint">{t('account.password_sessions')}</p>
        <button className="sf-pdp-add" disabled={form.processing}>
          {t(form.processing ? 'common.wait' : 'account.password_save')}
        </button>
      </form>
    </Card>
  );
}

function Orders({
  orders,
}: {
  orders: NonNullable<AccountPageData['orders']>;
}) {
  const t = useTranslation();
  const format = useDateFormat();
  return (
    <Card title={t('account.orders_title')}>
      {orders.rows.length === 0 ? (
        <p className="sf-acc-empty">{t('account.orders_empty')}</p>
      ) : (
        <>
          <div className="sf-acc-table-wrap">
            <table className="sf-acc-table">
              <thead>
                <tr>
                  <th scope="col">{t('account.order_number')}</th>
                  <th scope="col">{t('account.order_date')}</th>
                  <th scope="col">{t('account.order_status')}</th>
                  <th scope="col" className="is-number">
                    {t('account.order_items')}
                  </th>
                  <th scope="col" className="is-number">
                    {t('account.order_total')}
                  </th>
                  <td aria-hidden="true" />
                </tr>
              </thead>
              <tbody>
                {orders.rows.map((o) => (
                  <tr key={o.id}>
                    <th scope="row">
                      <a href={`/account/orders/${o.id}`}>#{o.number}</a>
                    </th>
                    <td>{format(o.created_at)}</td>
                    <td>
                      {o.status && (
                        <span className="sf-acc-status">
                          <span
                            aria-hidden="true"
                            style={{ background: o.status.color }}
                          />
                          {o.status.name}
                        </span>
                      )}
                    </td>
                    <td className="is-number">{o.units}</td>
                    <td className="is-number">{o.total}</td>
                    <td className="sf-acc-order-go">
                      {o.invoice_url && (
                        <a
                          href={o.invoice_url}
                          download
                          className="sf-acc-order-invoice"
                          aria-label={`${t('account.download_invoice')} #${o.number}`}
                          title={t('account.download_invoice')}
                        >
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
                            <path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5" />
                          </svg>
                        </a>
                      )}
                      <a
                        href={`/account/orders/${o.id}`}
                        aria-label={`${t('account.order_view')} #${o.number}`}
                      >
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
                          <path d="m9 6 6 6-6 6" />
                        </svg>
                      </a>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {orders.last_page > 1 && (
            <nav
              className="sf-acc-pages"
              aria-label={t('account.orders_title')}
            >
              {orders.page > 1 ? (
                <a href={`/account/orders?page=${orders.page - 1}`} rel="prev">
                  ‹ {t('account.orders_previous')}
                </a>
              ) : (
                <span />
              )}
              <span>
                {t('account.orders_page', {
                  current: orders.page,
                  last: orders.last_page,
                })}
              </span>
              {orders.page < orders.last_page ? (
                <a href={`/account/orders?page=${orders.page + 1}`} rel="next">
                  {t('account.orders_next')} ›
                </a>
              ) : (
                <span />
              )}
            </nav>
          )}
        </>
      )}
    </Card>
  );
}

/** The account page's main content block: a menu of the account's parts and the open part beside it. */
export default function Account({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const data = block.content.account;
  if (!data) return null;
  return (
    <div className="sf-account">
      <h1>{t('account.menu_title')}</h1>
      <div className="sf-acc-layout">
        <AccountMenu
          current={data.section === 'order' ? 'orders' : data.section}
          wishlist={data.wishlist_enabled}
        />
        <div className="sf-acc-panel">
          <Notices skip={ownErrors[data.section]} />
          {data.section === 'profile' && data.profile && (
            <>
              <Profile profile={data.profile} />
              <DeleteAccount profile={data.profile} />
            </>
          )}
          {data.section === 'orders' && data.orders && (
            <Orders orders={data.orders} />
          )}
          {data.section === 'order' && data.order && (
            <AccountOrder order={data.order} />
          )}
          {data.section === 'addresses' && <AccountAddresses data={data} />}
          {data.section === 'reviews' && <AccountReviews data={data} />}
          {data.section === 'newsletters' && <AccountNewsletters data={data} />}
          {data.section === 'password' && (
            <Password staffRule={!!data.staff_rule} />
          )}
        </div>
      </div>
    </div>
  );
}
