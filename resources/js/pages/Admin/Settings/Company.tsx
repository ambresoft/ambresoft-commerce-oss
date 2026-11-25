import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';

import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormField from '../../../components/auth/FormField';
import FormErrors from '../../../components/admin/FormErrors';
import CountrySelect from '../../../components/admin/CountrySelect';
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
import { useTranslation } from '../../../lib/useTranslation';
import { useUrlTab } from '../../../lib/useUrlTab';
import PaymentImages, { type PaymentImage } from './PaymentImages';
import CompanyLinks, { type CompanyLink } from './CompanyLinks';
import type { AdminShell } from '../../../types/admin';
import type { CountryOption } from '../../../types/taxes';

const sections = {
  company: ['name', 'phone', 'mobile', 'website', 'opening_year'],
  address_tab: ['address', 'city', 'country_code', 'region', 'postal_code'],
  legal: [
    'legal_name',
    'business_activity',
    'tax_id',
    'tax_office',
    'gemi_number',
  ],
  contacts: [
    'email_customers',
    'email_admin',
    'email_orders',
    'email_support',
    'email_newsletter',
  ],
  social: [],
  logo: [],
  payments: [],
} as const;
const tabs = [
  'company',
  'address_tab',
  'legal',
  'contacts',
  'social',
  'logo',
  'payments',
] as const;
type Field = (typeof sections)[keyof typeof sections][number];
type Logo = {
  url: string;
  meta: { size: number; width: number; height: number };
} | null;
type Settings = Record<Exclude<Field, 'opening_year'>, string | null> & {
  opening_year: number | null;
  version: number;
  logo: Logo;
  logo_mobile: Logo;
  logo_email: Logo;
  favicon: Logo;
  payment_icons: PaymentImage[];
  links: CompanyLink[];
};
type Props = AdminShell & { settings: Settings; countries: CountryOption[] };
const limits: Partial<Record<Field, number>> = {
  name: 200,
  phone: 64,
  mobile: 64,
  website: 2048,
  address: 500,
  city: 200,
  region: 200,
  postal_code: 32,
  legal_name: 200,
  business_activity: 200,
  tax_id: 32,
  tax_office: 100,
  gemi_number: 12,
};

export default function Company({ settings, countries, ...shell }: Props) {
  const t = useTranslation();
  const [paymentsBusy, setPaymentsBusy] = useState(false);
  const [tab, setTab] = useUrlTab(tabs);
  const form = useForm({
    version: settings.version,
    name: settings.name ?? '',
    phone: settings.phone ?? '',
    mobile: settings.mobile ?? '',
    website: settings.website ?? '',
    opening_year: settings.opening_year?.toString() ?? '',
    address: settings.address ?? '',
    city: settings.city ?? '',
    country_code: settings.country_code ?? '',
    region: settings.region ?? '',
    postal_code: settings.postal_code ?? '',
    legal_name: settings.legal_name ?? '',
    business_activity: settings.business_activity ?? '',
    tax_id: settings.tax_id ?? '',
    tax_office: settings.tax_office ?? '',
    gemi_number: settings.gemi_number ?? '',
    email_customers: settings.email_customers ?? '',
    email_admin: settings.email_admin ?? '',
    email_orders: settings.email_orders ?? '',
    email_support: settings.email_support ?? '',
    email_newsletter: settings.email_newsletter ?? '',
  });
  // Payment pictures are added one at a time with their own request.
  const icon = useForm<{ icon: File | null; name: string }>({
    icon: null,
    name: '',
  });
  useDirtyForm(form);
  useDirtyForm(icon);
  const saveIcon = () => {
    if (icon.data.icon)
      icon.post('/admin/settings/company/payment-icons', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => icon.reset(),
      });
  };
  // The logos have their own multipart save; the sticky Save button submits whichever tab is open.
  const logos = useForm<{
    version: number;
    logo: File | null;
    logo_mobile: File | null;
    logo_email: File | null;
    favicon: File | null;
    remove_logo: boolean;
    remove_logo_mobile: boolean;
    remove_logo_email: boolean;
    remove_favicon: boolean;
  }>({
    version: settings.version,
    logo: null,
    logo_mobile: null,
    logo_email: null,
    favicon: null,
    remove_logo: false,
    remove_logo_mobile: false,
    remove_logo_email: false,
    remove_favicon: false,
  });
  useDirtyForm(logos);
  const baseBusy = form.processing || logos.processing || icon.processing;
  const busy = baseBusy || paymentsBusy;
  return (
    <AdminLayout title={t('company.title')} {...shell}>
      <EditorHeader
        heading={t('company.title')}
        iconKey="company"
        actions={
          <>
            <EditorActionButton
              disabled={busy || tab === 'social'}
              form="admin-settings-form-0"
            >
              {t(busy ? 'common.wait' : 'common.save')}
            </EditorActionButton>
          </>
        }
      />
      <p className="mt-2 mb-6 max-w-3xl text-sm text-slate-600">
        {t('company.description')}
      </p>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (tab === 'logo')
            logos.post('/admin/settings/company/logos', {
              forceFormData: true,
              preserveScroll: true,
              onSuccess: () =>
                logos.reset(
                  'logo',
                  'logo_mobile',
                  'logo_email',
                  'favicon',
                  'remove_logo',
                  'remove_logo_mobile',
                  'remove_logo_email',
                  'remove_favicon',
                ),
            });
          else if (tab === 'payments') saveIcon();
          // Footer links save one at a time from their own panel.
          else if (tab === 'social') return;
          else {
            const options: NonNullable<Parameters<typeof form.post>[1]> = {
              preserveScroll: true,
              onSuccess: (page) => {
                const saved = page.props.settings as Settings;
                const next = { ...form.data, version: saved.version };
                form.setData(next);
                form.setDefaults(next);
                logos.setData('version', saved.version);
              },
            };
            form.put('/admin/settings/company', options);
          }
        }}
        id="admin-settings-form-0"
      >
        <AdminTabs label={t('company.title')}>
          {tabs.map((section) => (
            <AdminTab
              key={section}
              id={'company-tab-' + section}
              role="tab"
              type="button"
              aria-selected={tab === section}
              aria-controls={'company-panel-' + section}
              tabIndex={tab === section ? 0 : -1}
              onClick={() => setTab(section)}
              iconKey={section}
            >
              {t('company.' + section)}
              {(sections[section].some((field) => form.errors[field]) ||
                (section === 'logo' &&
                  (logos.errors.logo ||
                    logos.errors.logo_mobile ||
                    logos.errors.logo_email ||
                    logos.errors.favicon))) && (
                <span
                  className="ml-2 text-red-600"
                  aria-label={t('common.errors')}
                >
                  ●
                </span>
              )}
            </AdminTab>
          ))}
        </AdminTabs>

        <FormErrors
          errors={
            tab === 'logo'
              ? logos.errors
              : tab === 'payments'
                ? icon.errors
                : form.errors
          }
        />
        <section
          role="tabpanel"
          id={'company-panel-' + tab}
          aria-labelledby={'company-tab-' + tab}
          className="mt-4 w-full space-y-6 rounded-lg border border-slate-200 bg-white p-5 sm:p-8"
        >
          {tab === 'contacts' && (
            <p className="text-sm text-slate-500">
              {t('company.contacts_help')}
            </p>
          )}
          {tab === 'logo' && (
            <div className="space-y-8">
              <p className="text-sm text-slate-600">
                {t('company.logo_description')}
              </p>
              <div className="grid gap-8 lg:grid-cols-2">
                <div className="space-y-2">
                  <SingleImageUploader
                    title={t('company.logo_desktop')}
                    help={t('company.logo_desktop_help')}
                    current={
                      settings.logo
                        ? { url: settings.logo.url, meta: settings.logo.meta }
                        : null
                    }
                    alt={t('company.logo_desktop')}
                    file={logos.data.logo}
                    onFile={(file) => logos.setData('logo', file)}
                    removed={logos.data.remove_logo}
                    onRemoved={(value) => logos.setData('remove_logo', value)}
                    progress={
                      logos.processing && logos.data.logo
                        ? (logos.progress?.percentage ?? 0)
                        : null
                    }
                    disabled={busy}
                    error={
                      logos.errors.logo ? (
                        <p role="alert" className="text-sm text-red-700">
                          {logos.errors.logo}
                        </p>
                      ) : undefined
                    }
                  />
                </div>
                <div className="space-y-2">
                  <SingleImageUploader
                    title={t('company.logo_mobile')}
                    help={t('company.logo_mobile_help')}
                    current={
                      settings.logo_mobile
                        ? {
                            url: settings.logo_mobile.url,
                            meta: settings.logo_mobile.meta,
                          }
                        : null
                    }
                    alt={t('company.logo_mobile')}
                    file={logos.data.logo_mobile}
                    onFile={(file) => logos.setData('logo_mobile', file)}
                    removed={logos.data.remove_logo_mobile}
                    onRemoved={(value) =>
                      logos.setData('remove_logo_mobile', value)
                    }
                    progress={
                      logos.processing && logos.data.logo_mobile
                        ? (logos.progress?.percentage ?? 0)
                        : null
                    }
                    disabled={busy}
                    error={
                      logos.errors.logo_mobile ? (
                        <p role="alert" className="text-sm text-red-700">
                          {logos.errors.logo_mobile}
                        </p>
                      ) : undefined
                    }
                  />
                </div>
                <div className="space-y-2">
                  <SingleImageUploader
                    title={t('company.favicon')}
                    help={t('company.favicon_help')}
                    current={
                      settings.favicon
                        ? {
                            url: settings.favicon.url,
                            meta: settings.favicon.meta,
                          }
                        : null
                    }
                    alt={t('company.favicon')}
                    file={logos.data.favicon}
                    onFile={(file) => logos.setData('favicon', file)}
                    removed={logos.data.remove_favicon}
                    onRemoved={(value) =>
                      logos.setData('remove_favicon', value)
                    }
                    progress={
                      logos.processing && logos.data.favicon
                        ? (logos.progress?.percentage ?? 0)
                        : null
                    }
                    disabled={busy}
                    error={
                      logos.errors.favicon ? (
                        <p role="alert" className="text-sm text-red-700">
                          {logos.errors.favicon}
                        </p>
                      ) : undefined
                    }
                  />
                </div>
                <div className="space-y-2">
                  <SingleImageUploader
                    title={t('company.logo_email')}
                    help={t('company.logo_email_help')}
                    current={
                      settings.logo_email
                        ? {
                            url: settings.logo_email.url,
                            meta: settings.logo_email.meta,
                          }
                        : null
                    }
                    alt={t('company.logo_email')}
                    file={logos.data.logo_email}
                    onFile={(file) => logos.setData('logo_email', file)}
                    removed={logos.data.remove_logo_email}
                    onRemoved={(value) =>
                      logos.setData('remove_logo_email', value)
                    }
                    progress={
                      logos.processing && logos.data.logo_email
                        ? (logos.progress?.percentage ?? 0)
                        : null
                    }
                    disabled={busy}
                    error={
                      logos.errors.logo_email ? (
                        <p role="alert" className="text-sm text-red-700">
                          {logos.errors.logo_email}
                        </p>
                      ) : undefined
                    }
                  />
                </div>
              </div>
            </div>
          )}
          {tab === 'social' && (
            <CompanyLinks
              links={settings.links}
              disabled={baseBusy}
              onBusyChange={setPaymentsBusy}
            />
          )}
          {tab === 'payments' && (
            <PaymentImages
              items={settings.payment_icons}
              file={icon.data.icon}
              name={icon.data.name}
              onFile={(file) => icon.setData('icon', file)}
              onName={(name) => icon.setData('name', name)}
              onAdd={saveIcon}
              onClear={() => icon.reset()}
              processing={baseBusy}
              onBusyChange={setPaymentsBusy}
              progress={
                icon.processing ? (icon.progress?.percentage ?? 0) : null
              }
              errors={icon.errors}
            />
          )}
          <div className="grid gap-6 sm:grid-cols-2">
            {sections[tab]
              // The GEMI number exists only for Greek companies.
              .filter(
                (field) =>
                  field !== 'gemi_number' || form.data.country_code === 'GR',
              )
              .map((field) =>
                field === 'country_code' ? (
                  <div key={field} className="space-y-2">
                    <label
                      htmlFor="company-country_code"
                      className="block text-sm font-medium text-slate-800"
                    >
                      {t('company.country_code')}
                    </label>
                    <CountrySelect
                      id="company-country_code"
                      value={form.data.country_code}
                      invalid={!!form.errors.country_code}
                      aria-describedby={
                        form.errors.country_code
                          ? 'company-country-error'
                          : undefined
                      }
                      options={[
                        { value: '', label: t('company.none') },
                        ...countries.map((country) => ({
                          value: country.code,
                          label: country.name,
                        })),
                      ]}
                      onChange={(value) => form.setData('country_code', value)}
                    />
                    {form.errors.country_code && (
                      <p
                        id="company-country-error"
                        role="alert"
                        className="text-sm text-red-700"
                      >
                        {form.errors.country_code}
                      </p>
                    )}
                  </div>
                ) : (
                  <FormField
                    key={field}
                    id={'company-' + field}
                    label={t('company.' + field)}
                    value={form.data[field]}
                    type={
                      field.startsWith('email_')
                        ? 'email'
                        : field === 'website'
                          ? 'url'
                          : field === 'opening_year'
                            ? 'number'
                            : field === 'phone' || field === 'mobile'
                              ? 'tel'
                              : 'text'
                    }
                    min={field === 'opening_year' ? 1900 : undefined}
                    step={field === 'opening_year' ? 1 : undefined}
                    maxLength={field.startsWith('email_') ? 254 : limits[field]}
                    inputMode={
                      field === 'tax_id' || field === 'gemi_number'
                        ? 'numeric'
                        : undefined
                    }
                    hint={
                      field === 'region'
                        ? t('company.region_help')
                        : field === 'legal_name' || field === 'tax_id'
                          ? t('company.' + field + '_help')
                          : undefined
                    }
                    error={form.errors[field]}
                    onChange={(e) => form.setData(field, e.target.value)}
                  />
                ),
              )}
          </div>
        </section>
      </form>
    </AdminLayout>
  );
}
