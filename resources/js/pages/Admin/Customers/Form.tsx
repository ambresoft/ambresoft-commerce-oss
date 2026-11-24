import Card from '../../../components/admin/AdminCard';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, Link } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import AddressFields, {
  emptyAddress,
} from '../../../components/admin/AddressFields';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import FormField from '../../../components/auth/FormField';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type {
  AddressInput,
  CustomerDetail,
  CustomerGroupOption,
  RegionOptions,
} from '../../../types/customers';
import type { CountryOption } from '../../../types/taxes';

type Props = AdminShell & {
  customer: CustomerDetail | null;
  groups: CustomerGroupOption[];
  countries: CountryOption[];
  regions?: RegionOptions;
};

export default function CustomerForm({
  customer,
  groups,
  countries,
  regions = {},
  ...shell
}: Props) {
  const t = useTranslation();
  const form = useForm({
    version: customer?.version,
    first_name: customer?.first_name ?? '',
    last_name: customer?.last_name ?? '',
    email: customer?.email ?? '',
    phone: customer?.phone ?? '',
    tax_exempt: customer?.tax_exempt ?? false,
    notes: customer?.notes ?? '',
    // New customers start in the configured default group; the choice stays visible and editable.
    group_ids:
      customer?.group_ids ?? groups.filter((g) => g.default).map((g) => g.id),
    default_billing_address_id: customer?.default_billing_address_id ?? '',
    default_shipping_address_id: customer?.default_shipping_address_id ?? '',
    with_address: false,
    address: emptyAddress() as AddressInput,
  });
  useDirtyForm(form);
  const errors = form.errors as Record<string, string>;
  const submit = () => {
    form.transform((data) => {
      const { with_address, address, ...rest } = data;
      if (customer === null)
        return {
          ...rest,
          default_billing_address_id: undefined,
          default_shipping_address_id: undefined,
          address: with_address
            ? {
                ...address,
                first_name: address.first_name || data.first_name,
                last_name: address.last_name || data.last_name,
              }
            : null,
        };
      return rest;
    });
    if (customer === null) form.post('/admin/customers');
    else form.put('/admin/customers/' + customer.id);
  };
  const title = customer === null ? t('customers.create') : t('customers.edit');
  const addressOptions = (customer?.addresses ?? []).map((a) => ({
    value: a.id,
    label: [a.label, a.line1, a.city].filter(Boolean).join(' · '),
  }));
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={
          customer ? customer.first_name + ' ' + customer.last_name : title
        }
        title={title}
        status={
          customer ? (
            <StatusBadge
              tone={customer.status === 'active' ? 'success' : 'neutral'}
            >
              {t(
                customer.status === 'active'
                  ? 'customers.active'
                  : 'customers.disabled',
              )}
            </StatusBadge>
          ) : undefined
        }
        iconKey="customers"
        backHref={
          customer ? '/admin/customers/' + customer.id : '/admin/customers'
        }
        backLabel={
          customer
            ? customer.first_name + ' ' + customer.last_name
            : t('customers.title')
        }
        actions={
          <>
            <div className="flex gap-3">
              <Link
                className="button-secondary"
                href={
                  customer
                    ? '/admin/customers/' + customer.id
                    : '/admin/customers'
                }
              >
                {t('common.cancel')}
              </Link>
              <EditorActionButton
                disabled={form.processing}
                form="admin-customers-form-0"
              >
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            </div>
          </>
        }
      />
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          submit();
        }}
        id="admin-customers-form-0"
      >
        <FormErrors
          errors={Object.fromEntries(
            Object.entries(errors).filter(([key]) => key === 'version'),
          )}
        />
        <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
          <div className="min-w-0 space-y-6">
            <Card
              id="customer-profile"
              icon="user"
              title={t('customers.section_profile')}
            >
              <div className="grid gap-5 sm:grid-cols-2">
                <FormField
                  id="first_name"
                  label={t('customers.first_name')}
                  requiredIndicator
                  maxLength={100}
                  value={form.data.first_name}
                  error={errors.first_name}
                  onChange={(e) => form.setData('first_name', e.target.value)}
                />
                <FormField
                  id="last_name"
                  label={t('customers.last_name')}
                  requiredIndicator
                  maxLength={100}
                  value={form.data.last_name}
                  error={errors.last_name}
                  onChange={(e) => form.setData('last_name', e.target.value)}
                />
                <FormField
                  id="email"
                  type="email"
                  label={t('customers.email')}
                  hint={t('customers.email_hint')}
                  requiredIndicator
                  maxLength={254}
                  value={form.data.email}
                  error={errors.email}
                  onChange={(e) => form.setData('email', e.target.value)}
                />
                <FormField
                  id="phone"
                  type="tel"
                  label={t('customers.phone')}
                  maxLength={40}
                  value={form.data.phone}
                  error={errors.phone}
                  onChange={(e) => form.setData('phone', e.target.value)}
                />
              </div>
            </Card>
            {customer && customer.addresses.length > 0 && (
              <Card
                id="customer-address-defaults"
                icon="pin"
                title={t('customers.section_addresses')}
              >
                <div className="grid gap-5 sm:grid-cols-2">
                  {(
                    [
                      [
                        'default_billing_address_id',
                        'customers.default_billing',
                      ],
                      [
                        'default_shipping_address_id',
                        'customers.default_shipping',
                      ],
                    ] as const
                  ).map(([field, label]) => (
                    <div key={field} className="space-y-2">
                      <label
                        htmlFor={field}
                        className="block text-sm font-medium"
                      >
                        {t(label)}
                      </label>
                      <Select
                        id={field}
                        value={form.data[field]}
                        invalid={!!errors[field]}
                        onChange={(value) => form.setData(field, value)}
                        options={addressOptions}
                      />
                      {errors[field] && (
                        <p role="alert" className="text-sm text-red-700">
                          {errors[field]}
                        </p>
                      )}
                    </div>
                  ))}
                </div>
              </Card>
            )}
            {customer === null && (
              <Card
                id="customer-new-address"
                icon="pin"
                title={t('customers.section_addresses')}
              >
                <div className="space-y-4">
                  <label className="flex items-start gap-3 text-sm">
                    <input
                      type="checkbox"
                      className="mt-0.5 size-4"
                      checked={form.data.with_address}
                      onChange={(e) =>
                        form.setData('with_address', e.target.checked)
                      }
                    />
                    <span>
                      <span className="font-medium">
                        {t('customers.address_now')}
                      </span>
                      <span className="mt-0.5 block text-xs leading-5 text-slate-500">
                        {t('customers.address_now_hint')}
                      </span>
                    </span>
                  </label>
                  {form.data.with_address && (
                    <AddressFields
                      id="address"
                      prefix="address."
                      delivery
                      value={form.data.address}
                      errors={errors}
                      countries={countries}
                      regions={regions}
                      onChange={(value) => form.setData('address', value)}
                    />
                  )}
                </div>
              </Card>
            )}
          </div>
          <aside className="min-w-0 space-y-6">
            <Card
              id="customer-account"
              iconKey="accounts"
              title={t('customers.section_account')}
            >
              <div className="space-y-5">
                <fieldset className="space-y-2">
                  <legend className="mb-1 text-sm font-medium">
                    {t('customers.groups')}
                  </legend>
                  {groups.length === 0 && (
                    <p className="text-sm text-slate-500">
                      {t('customers.groups_none')}
                    </p>
                  )}
                  {groups.map((g) => (
                    <label
                      key={g.id}
                      className="flex items-center gap-3 text-sm"
                    >
                      <input
                        type="checkbox"
                        className="size-4"
                        checked={form.data.group_ids.includes(g.id)}
                        onChange={(e) =>
                          form.setData(
                            'group_ids',
                            e.target.checked
                              ? [...form.data.group_ids, g.id]
                              : form.data.group_ids.filter((id) => id !== g.id),
                          )
                        }
                      />
                      {g.name}
                      {g.default && (
                        <span className="text-xs text-slate-500">
                          {t('customers.group_default')}
                        </span>
                      )}
                      {!g.active && (
                        <span className="text-xs text-slate-500">
                          {t('customers.disabled')}
                        </span>
                      )}
                    </label>
                  ))}
                  {errors.group_ids && (
                    <p role="alert" className="text-sm text-red-700">
                      {errors.group_ids}
                    </p>
                  )}
                </fieldset>
                <label className="flex items-start gap-3 text-sm">
                  <input
                    type="checkbox"
                    className="mt-0.5 size-4"
                    checked={form.data.tax_exempt}
                    onChange={(e) =>
                      form.setData('tax_exempt', e.target.checked)
                    }
                  />
                  <span>
                    <span className="font-medium">
                      {t('customers.tax_exempt')}
                    </span>
                    <span className="mt-0.5 block text-xs leading-5 text-slate-500">
                      {t('customers.tax_exempt_hint')}
                    </span>
                  </span>
                </label>
              </div>
            </Card>
            <Card id="customer-notes" icon="note" title={t('customers.notes')}>
              <div className="space-y-2">
                <label htmlFor="notes" className="block text-sm font-medium">
                  {t('customers.notes')}
                </label>
                <textarea
                  id="notes"
                  className="field min-h-24"
                  maxLength={5000}
                  value={form.data.notes}
                  aria-describedby="notes-hint"
                  aria-invalid={errors.notes ? true : undefined}
                  onChange={(e) => form.setData('notes', e.target.value)}
                />
                <p id="notes-hint" className="text-xs text-slate-500">
                  {t('customers.notes_hint')}
                </p>
                {errors.notes && (
                  <p role="alert" className="text-sm text-red-700">
                    {errors.notes}
                  </p>
                )}
              </div>
            </Card>
          </aside>
        </div>
      </form>
    </AdminLayout>
  );
}
