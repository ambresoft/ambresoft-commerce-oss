import AccountSecuritySummary from '../../../components/admin/AccountSecuritySummary';
import type { SecuritySummary } from '../../../types/accountSecurity';
import ConfirmDialog from '../../../components/admin/ConfirmDialog';
import Drawer from '../../../components/admin/Drawer';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import Card from '../../../components/admin/AdminCard';
import Dropdown from '../../../components/admin/Dropdown';
import EditorHeader from '../../../components/admin/EditorHeader';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import AddressFields, {
  addressInput,
  emptyAddress,
} from '../../../components/admin/AddressFields';
import FormErrors from '../../../components/admin/FormErrors';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type {
  AddressInput,
  CustomerAddress,
  CustomerDetail,
  RegionOptions,
} from '../../../types/customers';
import type { SharedProps } from '../../../types/page';
import type { CountryOption } from '../../../types/taxes';
import BlacklistScopeFields, {
  type BlacklistScope,
} from '../../../components/admin/BlacklistScopeFields';

type Props = AdminShell & {
  security?: SecuritySummary | null;
  customer: CustomerDetail;
  countries: CountryOption[];
  regions?: RegionOptions;
  paymentMethods?: { id: string; name: string }[];
};

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium text-slate-500">{label}</dt>
      <dd className="mt-1 text-sm font-medium break-words text-slate-900">
        {children}
      </dd>
    </div>
  );
}

export default function CustomerShow({
  security,
  customer,
  countries,
  regions = {},
  paymentMethods = [],
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const canEdit = !!capabilities['customers.update'];
  const [editing, setEditing] = useState<string | null>(null);
  const [listing, setListing] = useState(false);
  const canBlacklist = !!capabilities['customer-blacklist.manage'];
  const blacklist = useForm({
    kinds: ['email'] as string[],
    reason: '',
    scope: 'full' as BlacklistScope,
    payment_method_ids: [] as string[],
  });
  const blocks = customer.blacklist.filter((entry) => entry.scope === 'full');
  const restrictions = customer.blacklist.filter(
    (entry) => entry.scope === 'payment_methods',
  );
  useDirtyForm(blacklist);
  const release = (id: string, value: string) => {
    if (!window.confirm(t('customers.blacklist_release_confirm', { value })))
      return;
    router.delete('/admin/customer-blacklist/' + id, { preserveScroll: true });
  };
  const address = useForm<AddressInput & { version: number }>({
    version: customer.version,
    ...emptyAddress(customer.first_name, customer.last_name),
  });
  useDirtyForm(address);
  const { date: stamp } = useDateDisplay();
  const name = customer.first_name + ' ' + customer.last_name;
  const disabled = customer.status === 'disabled';
  const [inviting, setInviting] = useState(false);
  const [confirmInvite, setConfirmInvite] = useState(false);

  const closeAddress = () => {
    if (address.processing) return;
    setEditing(null);
    address.reset();
    address.clearErrors();
  };
  const closeBlacklist = () => {
    if (blacklist.processing) return;
    setListing(false);
    blacklist.reset();
    blacklist.clearErrors();
  };
  const openAddress = (target: CustomerAddress | null) => {
    address.clearErrors();
    address.setData({
      version: customer.version,
      ...(target
        ? addressInput(target)
        : emptyAddress(customer.first_name, customer.last_name)),
    });
    setEditing(target ? target.id : 'new');
  };
  const saveAddress = () => {
    const options = {
      preserveScroll: true,
      onSuccess: () => {
        setEditing(null);
        address.reset();
        address.clearErrors();
      },
    };
    if (editing === 'new')
      address.post('/admin/customers/' + customer.id + '/addresses', options);
    else
      address.put(
        '/admin/customers/' + customer.id + '/addresses/' + editing,
        options,
      );
  };
  const removeAddress = (a: CustomerAddress) => {
    if (!window.confirm(t('customers.address_delete_confirm'))) return;
    router.delete('/admin/customers/' + customer.id + '/addresses/' + a.id, {
      data: { version: customer.version },
      preserveScroll: true,
    });
  };
  const makeDefault = (
    field: 'default_billing_address_id' | 'default_shipping_address_id',
    id: string,
  ) =>
    router.put(
      '/admin/customers/' + customer.id,
      {
        version: customer.version,
        first_name: customer.first_name,
        last_name: customer.last_name,
        email: customer.email,
        phone: customer.phone ?? '',
        tax_exempt: customer.tax_exempt,
        notes: customer.notes ?? '',
        [field]: id,
      },
      { preserveScroll: true },
    );
  const changeStatus = () => {
    const next = disabled ? 'active' : 'disabled';
    if (
      !window.confirm(
        t(
          next === 'disabled'
            ? 'customers.disable_confirm'
            : 'customers.restore_confirm',
          { name },
        ),
      )
    )
      return;
    router.patch(
      '/admin/customers/' + customer.id + '/status',
      { version: customer.version, status: next },
      { preserveScroll: true },
    );
  };
  const errors = address.errors as Record<string, string>;
  const page = usePage<SharedProps & { errors: Record<string, string> }>();
  const pageErrors = Object.fromEntries(
    Object.entries(page.props.errors ?? {}).filter(([key]) =>
      [
        'version',
        'status',
        'default_billing_address_id',
        'default_shipping_address_id',
      ].includes(key),
    ),
  );

  return (
    <AdminLayout title={name} {...shell}>
      <EditorHeader
        heading={name}
        iconKey="customers"
        backHref="/admin/customers"
        backLabel={t('customers.title')}
        metadata={
          <>
            <span>#{customer.id}</span>
            <span>
              {t('customers.created')}:{' '}
              {stamp.format(new Date(customer.created_at))}
            </span>
          </>
        }
        status={
          <StatusBadge tone={disabled ? 'neutral' : 'success'}>
            {t(
              customer.deleted_at
                ? 'customers.deleted'
                : disabled
                  ? 'customers.disabled'
                  : 'customers.active',
            )}
          </StatusBadge>
        }
        actions={
          canEdit && (
            <Link
              className="button-primary gap-2"
              href={'/admin/customers/' + customer.id + '/edit'}
            >
              <Icon name="edit" className="size-5" />
              {t('common.edit')}
            </Link>
          )
        }
        menu={[
          capabilities['customers.status'] && (
            <button
              type="button"
              className={disabled ? '' : 'text-red-700'}
              onClick={changeStatus}
            >
              <Icon name={disabled ? 'restore' : 'ban'} />
              {t(disabled ? 'customers.restore' : 'customers.disable')}
            </button>
          ),
          canBlacklist && (
            <button
              type="button"
              className="text-red-700"
              onClick={() => setListing(true)}
            >
              <Icon name="shield" />
              {t('customers.blacklist_add_customer')}
            </button>
          ),
        ]}
      />
      {blocks.length > 0 && (
        <section
          aria-labelledby="customer-blacklist"
          className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900"
        >
          <h2 id="customer-blacklist" className="font-semibold">
            {t('customers.blacklist_banner')}
          </h2>
          <ul className="mt-3 space-y-3">
            {blocks.map((entry) => (
              <li
                key={entry.id}
                className="flex flex-wrap items-start justify-between gap-3"
              >
                <div>
                  <p>
                    <span className="font-medium">
                      {t(
                        entry.kind === 'email'
                          ? 'customers.blacklist_email'
                          : 'customers.blacklist_phone',
                      )}
                      :
                    </span>{' '}
                    {entry.value}
                  </p>
                  <p className="mt-0.5">
                    <span className="font-medium">
                      {t('customers.blacklist_reason')}:
                    </span>{' '}
                    {entry.reason}
                  </p>
                  <p className="mt-0.5 text-xs text-red-800">
                    {entry.actor && entry.actor + ' · '}
                    {stamp.format(new Date(entry.created_at))}
                  </p>
                </div>
                {canBlacklist && (
                  <button
                    type="button"
                    className="button-secondary h-8 px-3 text-xs"
                    onClick={() => release(entry.id, entry.value)}
                  >
                    {t('customers.blacklist_release')}
                  </button>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}
      {restrictions.length > 0 && (
        <section
          aria-labelledby="customer-payment-restrictions"
          className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        >
          <h2 id="customer-payment-restrictions" className="font-semibold">
            {t('customers.blacklist_payment_banner')}
          </h2>
          <ul className="mt-3 space-y-3">
            {restrictions.map((entry) => (
              <li
                key={entry.id}
                className="flex flex-wrap items-start justify-between gap-3"
              >
                <div>
                  <p>
                    <span className="font-medium">
                      {t(
                        entry.kind === 'email'
                          ? 'customers.blacklist_email'
                          : 'customers.blacklist_phone',
                      )}
                      :
                    </span>{' '}
                    {entry.value}
                  </p>
                  <p className="mt-0.5">
                    <span className="font-medium">
                      {t('customers.blacklist_reason')}:
                    </span>{' '}
                    {entry.reason}
                  </p>
                  <p className="mt-0.5">
                    <span className="font-medium">
                      {t('customers.blacklist_disabled_methods')}:
                    </span>{' '}
                    {entry.payment_methods.join(', ')}
                  </p>
                  <p className="mt-0.5 text-xs text-amber-800 dark:text-amber-200">
                    {entry.actor && entry.actor + ' · '}
                    {stamp.format(new Date(entry.created_at))}
                  </p>
                </div>
                {canBlacklist && (
                  <button
                    type="button"
                    className="button-secondary h-8 px-3 text-xs"
                    onClick={() => release(entry.id, entry.value)}
                  >
                    {t('customers.blacklist_release')}
                  </button>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}
      {disabled && (
        <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          {customer.deleted_at
            ? t('customers.deleted_notice', {
                date: stamp.format(new Date(customer.deleted_at)),
              })
            : t('customers.disabled_notice')}
        </p>
      )}
      <FormErrors errors={pageErrors} />
      {listing && canBlacklist && (
        <Drawer
          title={t('customers.blacklist_add_customer')}
          description={t('customers.blacklist_drawer_subtitle')}
          busy={blacklist.processing}
          onClose={closeBlacklist}
          footer={
            <>
              <button
                type="button"
                className="button-secondary"
                disabled={blacklist.processing}
                onClick={closeBlacklist}
              >
                {t('common.cancel')}
              </button>
              <EditorActionButton
                form="customer-blacklist-form"
                disabled={
                  blacklist.processing || blacklist.data.kinds.length === 0
                }
              >
                {t('customers.blacklist_save')}
              </EditorActionButton>
            </>
          }
        >
          <form
            id="customer-blacklist-form"
            className="space-y-6"
            onSubmit={(e) => {
              e.preventDefault();
              blacklist.post('/admin/customers/' + customer.id + '/blacklist', {
                preserveScroll: true,
                onSuccess: () => {
                  setListing(false);
                  blacklist.reset();
                },
              });
            }}
          >
            <fieldset className="flex flex-wrap gap-5 text-sm">
              <legend className="sr-only">
                {t('customers.blacklist_kind')}
              </legend>
              {(['email', 'phone'] as const).map((kind) => (
                <label key={kind} className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    className="size-4"
                    disabled={kind === 'phone' && !customer.phone}
                    checked={blacklist.data.kinds.includes(kind)}
                    onChange={(e) =>
                      blacklist.setData(
                        'kinds',
                        e.target.checked
                          ? [...blacklist.data.kinds, kind]
                          : blacklist.data.kinds.filter((k) => k !== kind),
                      )
                    }
                  />
                  {kind === 'email'
                    ? t('customers.blacklist_email') + ': ' + customer.email
                    : t('customers.blacklist_phone') +
                      ': ' +
                      (customer.phone ?? t('customers.none'))}
                </label>
              ))}
            </fieldset>
            {blacklist.errors.kinds && (
              <p role="alert" className="text-sm text-red-700">
                {blacklist.errors.kinds}
              </p>
            )}
            <BlacklistScopeFields
              scope={blacklist.data.scope}
              methods={blacklist.data.payment_method_ids}
              options={paymentMethods}
              onScope={(scope) => blacklist.setData('scope', scope)}
              onMethods={(ids) => blacklist.setData('payment_method_ids', ids)}
              error={
                blacklist.errors.payment_method_ids ?? blacklist.errors.scope
              }
            />
            <div className="space-y-2">
              <label
                htmlFor="blacklist-reason"
                className="block text-sm font-medium"
              >
                {t('customers.blacklist_reason')}
              </label>
              <textarea
                id="blacklist-reason"
                className="field min-h-20"
                maxLength={1000}
                value={blacklist.data.reason}
                onChange={(e) => blacklist.setData('reason', e.target.value)}
              />
              <p className="text-xs text-slate-500">
                {t('customers.blacklist_reason_hint')}
              </p>
              {blacklist.errors.reason && (
                <p role="alert" className="text-sm text-red-700">
                  {blacklist.errors.reason}
                </p>
              )}
            </div>
          </form>
        </Drawer>
      )}
      <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="min-w-0 space-y-6">
          <Card
            id="customer-profile"
            icon="user"
            title={t('customers.section_profile')}
          >
            <dl className="grid gap-4 sm:grid-cols-2">
              <Fact label={t('customers.first_name')}>
                {customer.first_name}
              </Fact>
              <Fact label={t('customers.last_name')}>{customer.last_name}</Fact>
              <Fact label={t('customers.email')}>
                <a className="text-link" href={'mailto:' + customer.email}>
                  {customer.email}
                </a>
              </Fact>
              <Fact label={t('customers.phone')}>
                {customer.phone ? (
                  <a
                    className="hover:text-primary"
                    href={'tel:' + customer.phone}
                  >
                    {customer.phone}
                  </a>
                ) : (
                  <span className="text-slate-400">{t('customers.none')}</span>
                )}
              </Fact>
            </dl>
          </Card>
          <Card
            id="customer-addresses"
            icon="pin"
            count={customer.addresses.length}
            title={t('customers.section_addresses')}
            action={
              canEdit && (
                <button
                  type="button"
                  className="button-secondary gap-2"
                  onClick={() => openAddress(null)}
                >
                  <Icon name="plus" className="size-4" />
                  {t('customers.address_add')}
                </button>
              )
            }
          >
            {customer.addresses.length === 0 && (
              <p className="text-sm text-slate-500">
                {t('customers.addresses_empty')}
              </p>
            )}
            <ul className="grid gap-4 md:grid-cols-2">
              {customer.addresses.map((a) => {
                const billing = customer.default_billing_address_id === a.id;
                const shipping = customer.default_shipping_address_id === a.id;
                return (
                  <li
                    key={a.id}
                    aria-label={a.label ?? a.line1}
                    className={
                      'flex flex-col gap-4 rounded-xl border p-4 text-sm ' +
                      (billing || shipping
                        ? 'border-primary/30 bg-primary-tint/20'
                        : 'border-slate-200')
                    }
                  >
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-semibold">
                        {a.label ?? t('customers.address')}
                      </span>
                      {billing && (
                        <StatusBadge tone="success">
                          {t('customers.default_billing')}
                        </StatusBadge>
                      )}
                      {shipping && (
                        <StatusBadge tone="neutral">
                          {t('customers.default_shipping')}
                        </StatusBadge>
                      )}
                    </div>
                    <address className="leading-6 text-slate-700 not-italic">
                      {a.first_name} {a.last_name}
                      {a.company && (
                        <>
                          <br />
                          {a.company}
                        </>
                      )}
                      <br />
                      {[a.line1, a.street_number].filter(Boolean).join(' ')}
                      {a.line2 && (
                        <>
                          <br />
                          {a.line2}
                        </>
                      )}
                      <br />
                      {[a.postal_code, a.city].filter(Boolean).join(' ')}
                      {a.region_code && ' · ' + (a.region ?? a.region_code)}
                      <br />
                      {a.country}
                      {a.phone && (
                        <>
                          <br />
                          {a.phone}
                        </>
                      )}
                      {a.floor && (
                        <>
                          <br />
                          {t('customers.floor')}: {a.floor}
                        </>
                      )}
                      {a.bell_name && (
                        <>
                          <br />
                          {t('customers.bell_name')}: {a.bell_name}
                        </>
                      )}
                      {a.notes && (
                        <>
                          <br />
                          {a.notes}
                        </>
                      )}
                    </address>
                    {canEdit && (
                      <div className="mt-auto flex items-center justify-end gap-2 border-t border-slate-200 pt-4">
                        <div className="flex items-center gap-1">
                          <IconButton
                            icon="edit"
                            label={t('common.edit')}
                            onClick={() => openAddress(a)}
                          />
                          <Dropdown
                            label={
                              t('common.actions') + ': ' + (a.label ?? a.line1)
                            }
                            trigger={<Icon name="more" className="size-4" />}
                            triggerClassName="icon-button"
                            panelClassName="w-64 p-1.5"
                            floating
                          >
                            {(close) => (
                              <div className="flex flex-col gap-1">
                                {!billing && (
                                  <button
                                    type="button"
                                    className="flex items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-slate-50"
                                    onClick={() => {
                                      close();
                                      makeDefault(
                                        'default_billing_address_id',
                                        a.id,
                                      );
                                    }}
                                  >
                                    <Icon name="receipt" />
                                    {t('customers.make_default_billing')}
                                  </button>
                                )}
                                {!shipping && (
                                  <button
                                    type="button"
                                    className="flex items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-slate-50"
                                    onClick={() => {
                                      close();
                                      makeDefault(
                                        'default_shipping_address_id',
                                        a.id,
                                      );
                                    }}
                                  >
                                    <Icon name="truck" />
                                    {t('customers.make_default_shipping')}
                                  </button>
                                )}
                                <button
                                  type="button"
                                  className="flex items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50"
                                  onClick={() => {
                                    close();
                                    removeAddress(a);
                                  }}
                                >
                                  <Icon name="trash" />
                                  {t('customers.address_delete')}
                                </button>
                              </div>
                            )}
                          </Dropdown>
                        </div>
                      </div>
                    )}
                  </li>
                );
              })}
            </ul>
          </Card>
        </div>
        <aside className="min-w-0 space-y-6">
          <Card
            id="customer-account"
            iconKey="accounts"
            title={t('customers.section_account')}
          >
            <dl className="space-y-4">
              <Fact label={t('common.status')}>
                {t(disabled ? 'customers.disabled' : 'customers.active')}
              </Fact>
              <Fact label={t('customers.groups')}>
                {customer.groups.length > 0 ? (
                  <div className="flex flex-wrap gap-1.5">
                    {customer.groups.map((group) => (
                      <StatusBadge key={group} tone="neutral">
                        {group}
                      </StatusBadge>
                    ))}
                  </div>
                ) : (
                  <span className="text-slate-400">
                    {t('customers.no_groups')}
                  </span>
                )}
              </Fact>
              <Fact label={t('customers.tax_exempt')}>
                {t(customer.tax_exempt ? 'customers.yes' : 'customers.no')}
              </Fact>
              <Fact label={t('customers.created')}>
                {stamp.format(new Date(customer.created_at))}
              </Fact>
              <Fact label={t('customers.updated')}>
                {stamp.format(new Date(customer.updated_at))}
              </Fact>
            </dl>
          </Card>
          <Card
            id="customer-online-account"
            icon="shield"
            title={t('customers.online_account')}
            action={
              canEdit &&
              !disabled &&
              ['none', 'invited'].includes(customer.account.state) && (
                <button
                  type="button"
                  className="button-secondary h-8 gap-2 px-3 text-xs"
                  disabled={inviting}
                  onClick={() => setConfirmInvite(true)}
                >
                  {t(
                    customer.account.state === 'invited'
                      ? 'customers.invite_resend'
                      : 'customers.invite',
                  )}
                </button>
              )
            }
          >
            <p className="text-sm text-slate-700">
              {t(`customers.account_${customer.account.state}`, {
                date: customer.account.since
                  ? stamp.format(new Date(customer.account.since))
                  : '',
              })}
            </p>
            {customer.account.state === 'linked' &&
              !customer.account.active && (
                <p className="mt-2 text-sm text-red-700">
                  {t('customers.account_inactive')}
                </p>
              )}
          </Card>
          {customer.notes && (
            <Card id="customer-notes" icon="note" title={t('customers.notes')}>
              <p className="text-sm whitespace-pre-line text-slate-700">
                {customer.notes}
              </p>
            </Card>
          )}
        </aside>
      </div>
      {security && capabilities['account-security.view'] && (
        <div className="mt-6">
          <AccountSecuritySummary security={security} />
        </div>
      )}
      {confirmInvite && canEdit && !disabled && (
        <ConfirmDialog
          title={t('customers.invite')}
          description={t('customers.invite_confirmation', {
            email: customer.email,
          })}
          confirmLabel={t('customers.invite')}
          busy={inviting}
          onCancel={() => setConfirmInvite(false)}
          onConfirm={() => {
            if (inviting) return;
            router.post(
              '/admin/customers/' + customer.id + '/invitation',
              {},
              {
                preserveScroll: true,
                onStart: () => setInviting(true),
                onFinish: () => setInviting(false),
                onSuccess: () => setConfirmInvite(false),
              },
            );
          }}
        />
      )}
      {editing !== null && canEdit && (
        <Drawer
          title={t(
            editing === 'new'
              ? 'customers.address_add'
              : 'customers.address_edit',
          )}
          description={t('customers.address_drawer_hint')}
          busy={address.processing}
          onClose={closeAddress}
          footer={
            <>
              <button
                type="button"
                className="button-secondary"
                disabled={address.processing}
                onClick={closeAddress}
              >
                {t('common.cancel')}
              </button>
              <EditorActionButton
                form="customer-address-form"
                disabled={address.processing}
              >
                {t(
                  address.processing ? 'common.wait' : 'customers.address_save',
                )}
              </EditorActionButton>
            </>
          }
        >
          <form
            id="customer-address-form"
            className="space-y-6"
            onSubmit={(event) => {
              event.preventDefault();
              saveAddress();
            }}
          >
            <FormErrors
              errors={Object.fromEntries(
                Object.entries(errors).filter(([key]) => key === 'version'),
              )}
            />
            <fieldset disabled={address.processing}>
              <AddressFields
                id="address"
                delivery
                value={address.data}
                errors={errors}
                countries={countries}
                regions={regions}
                onChange={(value) =>
                  address.setData({ ...value, version: customer.version })
                }
              />
            </fieldset>
          </form>
        </Drawer>
      )}
    </AdminLayout>
  );
}
