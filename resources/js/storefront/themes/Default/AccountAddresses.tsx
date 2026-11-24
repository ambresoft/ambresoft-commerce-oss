import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { AccountAddress, AccountPageData } from '../../types';
import { AccountIcon, Field } from './AccountParts';
import Dropdown from '../../../components/admin/Dropdown';
import Dialog from './Dialog';

type AddressForm = {
  version: number;
  is_default: boolean;
  label: string;
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

const blank = (
  version: number,
  first_name: string,
  last_name: string,
  is_default = false,
): AddressForm => ({
  version,
  is_default,
  label: '',
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

const filled = (
  version: number,
  a: AccountAddress,
  is_default: boolean,
): AddressForm => ({
  version,
  is_default,
  label: a.label ?? '',
  first_name: a.first_name,
  last_name: a.last_name,
  company: a.company ?? '',
  line1: a.line1,
  street_number: a.street_number ?? '',
  line2: a.line2 ?? '',
  city: a.city,
  postal_code: a.postal_code ?? '',
  country_code: a.country_code,
  region_code: a.region_code ?? '',
  phone: a.phone ?? '',
  floor: a.floor ?? '',
  bell_name: a.bell_name ?? '',
  notes: a.notes ?? '',
});

/** The customer's address book: cards with the delivery details, and a window to add or edit an address. */
export default function AccountAddresses({ data }: { data: AccountPageData }) {
  const t = useTranslation();
  const version = data.version ?? 0;
  const addresses = data.addresses ?? [];
  const defaults = data.defaults ?? { billing: null, shipping: null };
  const profile = data.profile ?? { first_name: data.name, last_name: '' };
  // null: only the list; 'new': adding; otherwise the ID of the address being edited.
  const [editing, setEditing] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<AccountAddress | null>(null);
  const form = useForm<AddressForm>(
    blank(version, profile.first_name, profile.last_name),
  );
  // The default address is the one checkout uses unless the customer picks another.
  const isDefault = (a: AccountAddress) =>
    defaults.billing === a.id && defaults.shipping === a.id;
  const open = (address: AccountAddress | null) => {
    form.clearErrors();
    form.setData(
      address
        ? filled(version, address, isDefault(address))
        : blank(
            version,
            profile.first_name,
            profile.last_name,
            addresses.length === 0,
          ),
    );
    setEditing(address ? address.id : 'new');
  };
  const regions = data.regions?.[form.data.country_code] ?? [];
  const unlisted =
    form.data.region_code !== '' &&
    !regions.some((r) => r.code === form.data.region_code);
  const text = (
    name: keyof AddressForm,
    label: string,
    extra: { required?: boolean; hint?: string; maxLength?: number } = {},
  ) => (
    <Field
      id={`address-${name}`}
      label={label}
      required={extra.required}
      hint={extra.hint}
      maxLength={extra.maxLength}
      value={String(form.data[name] ?? '')}
      onChange={(e) => form.setData(name, e.target.value)}
      error={form.errors[name]}
    />
  );
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const options = {
      preserveScroll: true,
      onSuccess: () => setEditing(null),
    };
    if (editing === 'new') form.post('/account/addresses', options);
    else form.put(`/account/addresses/${editing}`, options);
  };
  return (
    <section className="sf-acc-card">
      <div className="sf-acc-head">
        <h2>{t('account.nav_addresses')}</h2>
        <button type="button" className="sf-pdp-add" onClick={() => open(null)}>
          {t('account.address_add')}
        </button>
      </div>
      {addresses.length === 0 ? (
        <p className="sf-acc-empty">{t('account.no_addresses')}</p>
      ) : (
        <ul className="sf-acc-addresses">
          {addresses.map((a) => (
            <li key={a.id}>
              <div className="sf-acc-address-head">
                <span className="sf-acc-address-pin" aria-hidden="true">
                  <AccountIcon name="addresses" />
                </span>
                <div className="sf-acc-tags">
                  <h3>
                    {a.label ||
                      [a.line1, a.street_number].filter(Boolean).join(' ')}
                  </h3>
                  {isDefault(a) ? (
                    <span>{t('account.default_address')}</span>
                  ) : (
                    <>
                      {defaults.billing === a.id && (
                        <span>{t('account.default_billing')}</span>
                      )}
                      {defaults.shipping === a.id && (
                        <span>{t('account.default_shipping')}</span>
                      )}
                    </>
                  )}
                </div>
                <Dropdown
                  label={t('common.actions') + ' · ' + (a.label || a.line1)}
                  triggerClassName="sf-acc-address-more"
                  panelClassName="sf-acc-address-menu"
                  trigger={
                    <svg
                      viewBox="0 0 24 24"
                      width="20"
                      height="20"
                      fill="currentColor"
                      aria-hidden="true"
                    >
                      <circle cx="5" cy="12" r="1.8" />
                      <circle cx="12" cy="12" r="1.8" />
                      <circle cx="19" cy="12" r="1.8" />
                    </svg>
                  }
                >
                  {(close) => (
                    <>
                      <button
                        type="button"
                        onClick={() => {
                          close();
                          open(a);
                        }}
                      >
                        <AccountIcon name="edit" />
                        {t('account.address_edit')}
                      </button>
                      <button
                        type="button"
                        className="is-danger"
                        onClick={() => {
                          close();
                          setDeleting(a);
                        }}
                      >
                        <AccountIcon name="trash" />
                        {t('account.address_delete')}
                      </button>
                    </>
                  )}
                </Dropdown>
              </div>
              <address className="sf-acc-address-lines">
                <p>
                  <AccountIcon name="profile" />
                  <span>
                    {a.first_name} {a.last_name}
                  </span>
                </p>
                {a.company && (
                  <p>
                    <AccountIcon name="company" />
                    <span>{a.company}</span>
                  </p>
                )}
                <p>
                  <AccountIcon name="addresses" />
                  <span>
                    {[a.line1, a.street_number].filter(Boolean).join(' ')}
                    {a.line2 && (
                      <>
                        <br />
                        {a.line2}
                      </>
                    )}
                  </span>
                </p>
                <p>
                  <AccountIcon name="company" />
                  <span>
                    {[a.postal_code, a.city].filter(Boolean).join(' ')}
                    {a.region_code && `, ${a.region ?? a.region_code}`}
                  </span>
                </p>
                <p>
                  <AccountIcon name="globe" />
                  <span>{a.country}</span>
                </p>
                {a.phone && (
                  <p>
                    <AccountIcon name="phone" />
                    <span>{a.phone}</span>
                  </p>
                )}
              </address>
              {(a.floor || a.bell_name || a.notes) && (
                <dl className="sf-acc-delivery">
                  {a.floor && (
                    <div>
                      <dt>
                        <AccountIcon name="orders" />
                        {t('customers.floor')}
                      </dt>
                      <dd>{a.floor}</dd>
                    </div>
                  )}
                  {a.bell_name && (
                    <div>
                      <dt>
                        <AccountIcon name="bell" />
                        {t('customers.bell_name')}
                      </dt>
                      <dd>{a.bell_name}</dd>
                    </div>
                  )}
                  {a.notes && (
                    <div>
                      <dt>
                        <AccountIcon name="notes" />
                        {t('customers.address_notes')}
                      </dt>
                      <dd>{a.notes}</dd>
                    </div>
                  )}
                </dl>
              )}
            </li>
          ))}
        </ul>
      )}
      {editing !== null && (
        <Dialog
          title={t(
            editing === 'new' ? 'account.address_add' : 'account.address_edit',
          )}
          onClose={() => setEditing(null)}
          className="sf-acc-dialog"
        >
          <form className="sf-acc-form" onSubmit={submit}>
            {form.errors.version && (
              <p role="alert" className="sf-pdp-form-error">
                {form.errors.version}
              </p>
            )}
            <div className="sf-acc-grid">
              {text('label', t('customers.address_label'), {
                hint: t('customers.address_label_hint'),
                maxLength: 60,
              })}
              {text('company', t('customers.company'), { maxLength: 150 })}
              {text('first_name', t('customers.first_name'), {
                required: true,
                maxLength: 100,
              })}
              {text('last_name', t('customers.last_name'), {
                required: true,
                maxLength: 100,
              })}
              {text('line1', t('customers.line1'), {
                required: true,
                maxLength: 200,
              })}
              {text('street_number', t('customers.street_number'), {
                maxLength: 20,
              })}
              {text('city', t('customers.city'), {
                required: true,
                maxLength: 100,
              })}
              {text('postal_code', t('customers.postal_code'), {
                required: true,
                maxLength: 20,
              })}
              <div className="sf-acc-field">
                <label htmlFor="address-country_code">
                  {t('customers.country')}
                  <span aria-hidden="true"> *</span>
                </label>
                <select
                  id="address-country_code"
                  required
                  value={form.data.country_code}
                  aria-invalid={form.errors.country_code ? true : undefined}
                  onChange={(e) =>
                    form.setData((d) => ({
                      ...d,
                      country_code: e.target.value,
                      // A region belongs to one country.
                      region_code: '',
                    }))
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
                {form.errors.country_code && (
                  <p role="alert" className="sf-acc-error">
                    {form.errors.country_code}
                  </p>
                )}
              </div>
              {(form.data.country_code === '' ||
                regions.length > 0 ||
                unlisted) && (
                <div className="sf-acc-field">
                  <label htmlFor="address-region_code">
                    {t('customers.region')}
                    <span aria-hidden="true"> *</span>
                  </label>
                  <select
                    id="address-region_code"
                    required
                    disabled={form.data.country_code === ''}
                    value={form.data.region_code}
                    aria-invalid={form.errors.region_code ? true : undefined}
                    onChange={(e) =>
                      form.setData('region_code', e.target.value)
                    }
                  >
                    <option value="" disabled>
                      {t(
                        form.data.country_code === ''
                          ? 'customers.region_choose_country'
                          : 'customers.region_choose',
                      )}
                    </option>
                    {unlisted && (
                      <option value={form.data.region_code}>
                        {t('customers.region_unlisted', {
                          code: form.data.region_code,
                        })}
                      </option>
                    )}
                    {regions.map((r) => (
                      <option key={r.code} value={r.code}>
                        {r.name}
                      </option>
                    ))}
                  </select>
                  {form.errors.region_code && (
                    <p role="alert" className="sf-acc-error">
                      {form.errors.region_code}
                    </p>
                  )}
                </div>
              )}
              {text('line2', t('customers.line2'), { maxLength: 200 })}
              {text('phone', t('customers.phone'), { maxLength: 40 })}
              {text('floor', t('customers.floor'), { maxLength: 20 })}
              {text('bell_name', t('customers.bell_name'), {
                hint: t('customers.bell_name_hint'),
                maxLength: 100,
              })}
            </div>
            <div className="sf-acc-field">
              <label htmlFor="address-notes">
                {t('customers.address_notes')}
              </label>
              <textarea
                id="address-notes"
                rows={3}
                maxLength={500}
                value={form.data.notes}
                onChange={(e) => form.setData('notes', e.target.value)}
              />
              <p className="sf-acc-hint">{t('customers.address_notes_hint')}</p>
              {form.errors.notes && (
                <p role="alert" className="sf-acc-error">
                  {form.errors.notes}
                </p>
              )}
            </div>
            <label className="sf-acc-check">
              <input
                type="checkbox"
                checked={form.data.is_default}
                disabled={
                  editing !== 'new' &&
                  addresses.some((a) => a.id === editing && isDefault(a))
                }
                onChange={(e) => form.setData('is_default', e.target.checked)}
              />
              <span>
                {t('account.set_default')}
                <small>{t('account.set_default_hint')}</small>
              </span>
            </label>
            <div className="sf-wl-confirm">
              <button
                type="button"
                className="sf-acc-secondary"
                onClick={() => setEditing(null)}
              >
                {t('storefront.wishlist_cancel')}
              </button>
              <button className="sf-pdp-add" disabled={form.processing}>
                {t(form.processing ? 'common.wait' : 'account.address_save')}
              </button>
            </div>
          </form>
        </Dialog>
      )}
      {deleting && (
        <Dialog
          title={t('account.address_delete_title')}
          onClose={() => setDeleting(null)}
        >
          <p>{t('account.address_delete_hint')}</p>
          <p>
            <strong>
              {[
                [deleting.line1, deleting.street_number]
                  .filter(Boolean)
                  .join(' '),
                deleting.city,
              ]
                .filter(Boolean)
                .join(', ')}
            </strong>
          </p>
          <div className="sf-wl-confirm">
            <button
              type="button"
              className="sf-acc-secondary"
              onClick={() => setDeleting(null)}
            >
              {t('storefront.wishlist_cancel')}
            </button>
            <button
              type="button"
              className="sf-acc-danger is-filled"
              onClick={() =>
                router.delete(`/account/addresses/${deleting.id}`, {
                  data: { version },
                  preserveScroll: true,
                  onFinish: () => setDeleting(null),
                })
              }
            >
              {t('account.address_delete')}
            </button>
          </div>
        </Dialog>
      )}
    </section>
  );
}
