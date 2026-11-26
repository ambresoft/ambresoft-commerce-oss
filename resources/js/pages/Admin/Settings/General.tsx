import CheckoutSettingsFields, {
  type CheckoutSettings,
  type CheckoutValues,
} from '../../../components/admin/CheckoutSettingsFields';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import StorefrontColorFields, {
  validColors,
  type StorefrontColorForm,
  type StorefrontColors,
} from '../../../components/admin/StorefrontColorFields';
import {
  GeneralFields,
  type BasicValues,
  basicDefaults,
  basicFields,
} from '../../../components/admin/GeneralFields';
import AiSettingsFields, {
  type AiSettings,
  type AiValues,
} from '../../../components/admin/AiSettingsFields';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '../../../types/page';

import { useForm, Link } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import CountrySelect from '../../../components/admin/CountrySelect';
import FormField from '../../../components/auth/FormField';
import { useTranslation } from '../../../lib/useTranslation';
import { useUrlTab } from '../../../lib/useUrlTab';
import type { AdminShell } from '../../../types/admin';
import type { CountryOption } from '../../../types/taxes';

type Settings = Partial<BasicValues> & {
  out_of_stock_action: string;
  version: number;
  tax_calculation_method: string;
  display_catalog_prices_with_tax: boolean;
  display_cart_prices_with_tax: boolean;
  tax_estimate_without_address: boolean;
  tax_default_country: string | null;
  tax_default_region: string | null;
  order_email_from?: string | null;
  order_email_from_name?: string | null;
  order_email_reply_to?: string | null;
  mail_method?: string;
  smtp_host?: string | null;
  smtp_port?: number | null;
  smtp_encryption?: string;
  smtp_auth?: boolean;
  smtp_username?: string | null;
  smtp_password_set?: boolean;
  sendmail_path?: string;
};
const tabs = [
  'basic',
  'appearance',
  'catalog',
  'checkout',
  'inventory',
  'promotions',
  'taxes',
  'emails',
  'storefront',
  'ai',
] as const;
const tabFields = {
  ai: ['ai_settings'],
  checkout: ['checkout_settings'],
  storefront: [],
  ...basicFields,
  inventory: ['out_of_stock_action', 'track_inventory', 'low_stock_threshold'],
  taxes: [
    'tax_calculation_method',
    'display_catalog_prices_with_tax',
    'display_cart_prices_with_tax',
    'tax_estimate_without_address',
    'tax_default_country',
    'tax_default_region',
  ],
  emails: [
    'order_email_from',
    'order_email_from_name',
    'order_email_reply_to',
    'mail_method',
    'smtp_host',
    'smtp_port',
    'smtp_encryption',
    'smtp_auth',
    'smtp_username',
    'smtp_password',
    'sendmail_path',
  ],
} as const;
const tabLabel = {
  basic: 'settings.tab_basic',
  appearance: 'settings.tab_appearance',
  catalog: 'settings.tab_catalog',
  promotions: 'settings.tab_promotions',
  inventory: 'settings.tab_inventory',
  taxes: 'settings.tab_taxes',
  emails: 'settings.tab_emails',
  storefront: 'layouts.colors_title',
  ai: 'ai.settings',
  checkout: 'checkout.settings_title',
} as const;
type Props = AdminShell & {
  options?: { id: string; name: string }[];
  settings: Settings;
  aiSettings?: AiSettings;
  checkoutSettings?: CheckoutSettings;
  invoiceCountryCoverage?: Record<string, string[]>;
  aiModels?: { value: string; label: string }[];
  overrides: number;
  storefrontColors?: {
    colors: StorefrontColors;
    auth_header: string;
    defaults: StorefrontColors;
    version: number;
  };
  countries?: CountryOption[];
  timezones?: string[];
  messageLocales?: { code: string; name: string; defaultMessage: string }[];
};

export default function General(props: Props) {
  return <GeneralEditor key={props.settings.version} {...props} />;
}

function GeneralEditor({
  settings,
  options = [],
  overrides,
  aiSettings,
  checkoutSettings,
  invoiceCountryCoverage = {},
  aiModels = [],
  storefrontColors,
  countries = [],
  timezones = ['UTC'],
  messageLocales = [],
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const [tab, setTab] = useUrlTab(tabs);
  const form = useForm({
    checkout_settings: checkoutSettings
      ? ({
          version: checkoutSettings.version,
          enabled: checkoutSettings.enabled,
          greek_provider: checkoutSettings.greek_provider,
          international_provider: checkoutSettings.international_provider,
          country_codes: checkoutSettings.country_codes,
          aade_username: '',
          aade_password: '',
          vatsense_key: '',
          clear_aade_username: false,
          clear_aade_password: false,
          clear_vatsense_key: false,
        } as CheckoutValues)
      : undefined,
    ai_settings: aiSettings
      ? ({
          version: aiSettings.version,
          enabled: aiSettings.enabled,
          api_key: '',
          clear_key: false,
          model: aiSettings.model,
          max_tokens: aiSettings.max_tokens,
          hourly_limit: aiSettings.hourly_limit,
          daily_limit: aiSettings.daily_limit,
        } as AiValues)
      : undefined,
    ...basicDefaults,
    ...settings,
    closed_messages: Object.fromEntries(
      messageLocales.map((locale) => [
        locale.code,
        settings.closed_messages?.[locale.code] ?? locale.defaultMessage,
      ]),
    ),
    price_hidden_messages: Object.fromEntries(
      messageLocales.map((locale) => [
        locale.code,
        settings.price_hidden_messages?.[locale.code] ?? '',
      ]),
    ),
    version: settings.version,
    out_of_stock_action: settings.out_of_stock_action,
    tax_calculation_method: settings.tax_calculation_method ?? 'unit_price',
    display_catalog_prices_with_tax:
      settings.display_catalog_prices_with_tax ?? false,
    display_cart_prices_with_tax:
      settings.display_cart_prices_with_tax ?? false,
    tax_estimate_without_address:
      settings.tax_estimate_without_address ?? false,
    tax_default_country: settings.tax_default_country ?? '',
    tax_default_region: settings.tax_default_region ?? '',
    order_email_from: settings.order_email_from ?? '',
    order_email_from_name: settings.order_email_from_name ?? '',
    order_email_reply_to: settings.order_email_reply_to ?? '',
    mail_method: settings.mail_method ?? 'php_mail',
    smtp_host: settings.smtp_host ?? '',
    smtp_port: settings.smtp_port == null ? '' : String(settings.smtp_port),
    smtp_encryption: settings.smtp_encryption ?? 'none',
    smtp_auth: settings.smtp_auth ?? false,
    smtp_username: settings.smtp_username ?? '',
    // Write-only: empty keeps the saved password.
    smtp_password: '',
    smtp_password_clear: false,
    sendmail_path: settings.sendmail_path ?? '/usr/sbin/sendmail',
  });
  useDirtyForm(form);
  // Colors have their own save endpoint (it needs the layouts permission); the Save button submits the open tab.
  const colors = useForm<StorefrontColorForm & { version: number }>({
    ...(storefrontColors?.colors ?? ({} as StorefrontColors)),
    auth_header: storefrontColors?.auth_header ?? '',
    version: storefrontColors?.version ?? 1,
  });
  useDirtyForm(colors);
  const visibleTabs = tabs.filter(
    (section) =>
      (section !== 'storefront' || storefrontColors) &&
      (section !== 'checkout' || checkoutSettings),
  );
  const overrideKey =
    overrides === 0
      ? 'settings.overrides_zero'
      : overrides === 1
        ? 'settings.overrides_one'
        : 'settings.overrides_other';
  return (
    <AdminLayout title={t('settings.title')} {...shell}>
      <EditorHeader
        heading={t('settings.title')}
        iconKey="settings"
        actions={
          <>
            <EditorActionButton
              disabled={form.processing || colors.processing}
              form="admin-settings-form-0"
            >
              {t(
                form.processing || colors.processing
                  ? 'common.wait'
                  : 'settings.save',
              )}
            </EditorActionButton>
          </>
        }
      />
      <p className="mt-2 mb-6 max-w-3xl text-sm text-slate-600">
        {t('settings.description')}
      </p>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (tab === 'storefront') {
            if (validColors(colors.data))
              colors.put('/admin/settings/storefront-colors', {
                preserveScroll: true,
              });
            return;
          }
          form.put('/admin/settings/general', {
            onSuccess: () => form.setDefaults(),
          });
        }}
        id="admin-settings-form-0"
      >
        <AdminTabs label={t('settings.title')}>
          {visibleTabs.map((section) => (
            <AdminTab
              key={section}
              id={'settings-tab-' + section}
              role="tab"
              type="button"
              aria-selected={tab === section}
              aria-controls={'settings-panel-' + section}
              tabIndex={tab === section ? 0 : -1}
              onClick={() => setTab(section)}
              iconKey={section}
            >
              {t(tabLabel[section])}
              {tabFields[section].some((field) =>
                Object.keys(form.errors).some(
                  (key) => key === field || key.startsWith(field + '.'),
                ),
              ) && (
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

        <FormErrors errors={form.errors} />
        <div
          role="tabpanel"
          id={'settings-panel-' + tab}
          aria-labelledby={'settings-tab-' + tab}
          className="mt-4 w-full space-y-6 rounded-lg border border-slate-200 bg-white p-5 sm:p-8"
        >
          {[
            'basic',
            'appearance',
            'catalog',
            'inventory',
            'promotions',
          ].includes(tab) && (
            <GeneralFields
              categoryOptions={options}
              tab={tab}
              form={form}
              canManageBrandLandings={
                capabilities['settings.brand-landing-pages'] === true
              }
              timezones={timezones}
              messageLocales={messageLocales}
            />
          )}
          {tab === 'storefront' && storefrontColors && (
            <>
              <FormErrors errors={colors.errors} />
              <StorefrontColorFields
                key={storefrontColors.version}
                values={colors.data}
                saved={storefrontColors.colors}
                defaults={storefrontColors.defaults}
                canManage={!!capabilities['layouts.manage']}
                onChange={(name, value) => colors.setData(name, value)}
              />
            </>
          )}
          {tab === 'checkout' &&
            checkoutSettings &&
            form.data.checkout_settings && (
              <CheckoutSettingsFields
                key={Object.keys(form.errors)
                  .filter((key) => key.startsWith('checkout_settings.'))
                  .join(',')}
                value={form.data.checkout_settings}
                saved={checkoutSettings}
                countries={countries}
                countryCoverage={invoiceCountryCoverage}
                onChange={(value) => form.setData('checkout_settings', value)}
                errors={Object.fromEntries(
                  Object.entries(form.errors)
                    .filter(([key]) => key.startsWith('checkout_settings.'))
                    .map(([key, value]) => [
                      key.slice('checkout_settings.'.length),
                      value,
                    ]),
                )}
              />
            )}
          {tab === 'ai' && form.data.ai_settings && (
            <>
              <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-base font-semibold">{t('ai.settings')}</h2>
                {capabilities['ai.logs.view'] && (
                  <Link
                    className="button-secondary"
                    href="/admin/settings/ai-log"
                  >
                    {t('ai.log')}
                  </Link>
                )}
              </div>
              <AiSettingsFields
                value={form.data.ai_settings}
                onChange={(value) => form.setData('ai_settings', value)}
                hasKey={aiSettings?.has_key ?? false}
                models={aiModels}
                errors={Object.fromEntries(
                  Object.entries(form.errors)
                    .filter(([key]) => key.startsWith('ai_settings.'))
                    .map(([key, value]) => [
                      key.slice('ai_settings.'.length),
                      value,
                    ]),
                )}
              />
            </>
          )}
          {tab === 'inventory' && (
            <section
              className="space-y-3 border-t border-slate-200 pt-6"
              aria-labelledby="out-of-stock-action"
            >
              <label
                htmlFor="out-of-stock-action"
                className="block text-sm font-medium text-slate-800"
              >
                {t('settings.out_of_stock_action')}
              </label>
              <Select
                id="out-of-stock-action"
                value={form.data.out_of_stock_action}
                invalid={!!form.errors.out_of_stock_action}
                onChange={(value) => form.setData('out_of_stock_action', value)}
                options={['N', 'B'].map((value) => ({
                  value,
                  label: t(`settings.out_of_stock_${value}`),
                }))}
              />
              <p className="text-xs leading-5 text-slate-500">
                {t('settings.out_of_stock_help')}
              </p>
              <p className="text-xs leading-5 text-slate-500">
                {t('settings.out_of_stock_override_note')}
              </p>
              <p className="text-sm text-slate-700">
                {t(overrideKey, { count: overrides })}{' '}
                {overrides > 0 && (
                  <Link
                    className="font-medium underline"
                    href="/admin/products?stock_action=custom"
                  >
                    {t('settings.view_overrides')}
                  </Link>
                )}
              </p>
              <p className="text-xs leading-5 text-amber-800">
                {t('settings.storefront_note')}
              </p>
            </section>
          )}
          {tab === 'emails' && (
            <section className="space-y-5" aria-labelledby="settings-emails">
              <div>
                <h2 id="settings-emails" className="text-base font-semibold">
                  {t('settings.section_order_emails')}
                </h2>
                <p className="mt-1 max-w-2xl text-xs leading-5 text-slate-500">
                  {t('settings.order_emails_help')}
                </p>
              </div>
              {(
                [
                  ['order_email_from', 'email', 254],
                  ['order_email_from_name', 'text', 200],
                  ['order_email_reply_to', 'email', 254],
                ] as const
              ).map(([field, type, max]) => (
                <FormField
                  key={field}
                  id={field}
                  type={type}
                  label={t('settings.' + field)}
                  hint={t(`settings.${field}_help`)}
                  requiredIndicator={field === 'order_email_from'}
                  maxLength={max}
                  value={form.data[field]}
                  error={form.errors[field]}
                  onChange={(e) => form.setData(field, e.target.value)}
                />
              ))}
            </section>
          )}
          {tab === 'emails' && (
            <MailDelivery
              form={form}
              passwordSaved={settings.smtp_password_set ?? false}
            />
          )}
          {tab === 'taxes' && (
            <section className="space-y-4" aria-labelledby="settings-taxes">
              <div>
                <h2 id="settings-taxes" className="text-base font-semibold">
                  {t('settings.section_taxes')}
                </h2>
                <p className="mt-1 text-xs leading-5 text-slate-500">
                  {t('settings.taxes_help')}
                </p>
              </div>
              <div className="space-y-2">
                <label
                  htmlFor="tax-method"
                  className="block text-sm font-medium text-slate-800"
                >
                  {t('settings.tax_calculation_method')}
                </label>
                <Select
                  id="tax-method"
                  value={form.data.tax_calculation_method}
                  invalid={!!form.errors.tax_calculation_method}
                  onChange={(value) =>
                    form.setData('tax_calculation_method', value)
                  }
                  options={[
                    {
                      value: 'unit_price',
                      label: t('settings.tax_method_unit_price'),
                    },
                    {
                      value: 'subtotal',
                      label: t('settings.tax_method_subtotal'),
                    },
                  ]}
                />
                <p className="text-xs leading-5 text-slate-500">
                  {t('settings.tax_method_help')}
                </p>
              </div>
              {(
                [
                  [
                    'display_cart_prices_with_tax',
                    'settings.display_cart_with_tax',
                  ],
                ] as const
              ).map(([field, label]) => (
                <label key={field} className="flex items-start gap-3 text-sm">
                  <input
                    type="checkbox"
                    className="mt-0.5 size-4"
                    checked={form.data[field]}
                    onChange={(e) => form.setData(field, e.target.checked)}
                  />
                  <span>
                    <span className="font-medium">{t(label)}</span>
                    <span className="mt-0.5 block text-xs leading-5 text-slate-500">
                      {t(label + '_help')}
                    </span>
                  </span>
                </label>
              ))}
            </section>
          )}
          {tab === 'taxes' && (
            <section
              className="space-y-4 border-t border-slate-200 pt-6"
              aria-labelledby="settings-tax-address"
            >
              <div>
                <h2
                  id="settings-tax-address"
                  className="text-base font-semibold"
                >
                  {t('settings.section_tax_address')}
                </h2>
                <p className="mt-1 text-xs leading-5 text-slate-500">
                  {t('settings.tax_address_help')}
                </p>
              </div>
              <label className="flex items-start gap-3 text-sm">
                <input
                  type="checkbox"
                  className="mt-0.5 size-4"
                  checked={form.data.tax_estimate_without_address}
                  onChange={(e) =>
                    form.setData(
                      'tax_estimate_without_address',
                      e.target.checked,
                    )
                  }
                />
                <span>
                  <span className="font-medium">
                    {t('settings.tax_estimate_without_address')}
                  </span>
                  <span className="mt-0.5 block text-xs leading-5 text-slate-500">
                    {t('settings.tax_estimate_without_address_help')}
                  </span>
                </span>
              </label>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <label
                    htmlFor="tax-country"
                    className="block text-sm font-medium text-slate-800"
                  >
                    {t('settings.tax_default_country')}
                  </label>
                  <CountrySelect
                    id="tax-country"
                    value={form.data.tax_default_country}
                    invalid={!!form.errors.tax_default_country}
                    onChange={(value) =>
                      form.setData('tax_default_country', value)
                    }
                    options={[
                      { value: '', label: t('settings.tax_default_none') },
                      ...countries.map((c) => ({
                        value: c.code,
                        label: c.name + ' (' + c.code + ')',
                      })),
                    ]}
                  />
                  {form.errors.tax_default_country && (
                    <p role="alert" className="text-sm text-red-700">
                      {form.errors.tax_default_country}
                    </p>
                  )}
                </div>
                <div className="space-y-2">
                  <label
                    htmlFor="tax-region"
                    className="block text-sm font-medium text-slate-800"
                  >
                    {t('settings.tax_default_region')}
                  </label>
                  <input
                    id="tax-region"
                    className="field"
                    maxLength={10}
                    value={form.data.tax_default_region}
                    aria-invalid={
                      form.errors.tax_default_region ? true : undefined
                    }
                    onChange={(e) =>
                      form.setData('tax_default_region', e.target.value)
                    }
                  />
                  {form.errors.tax_default_region && (
                    <p role="alert" className="text-sm text-red-700">
                      {form.errors.tax_default_region}
                    </p>
                  )}
                </div>
              </div>
            </section>
          )}
        </div>
      </form>
    </AdminLayout>
  );
}

type DeliveryForm = {
  data: Record<string, unknown>;
  errors: Partial<Record<string, string>>;
  setData: (key: never, value: never) => void;
};

/** CS-Cart-like delivery settings: PHP mail(), an SMTP server or the sendmail program. Same layout as the other settings. */
function MailDelivery({
  form,
  passwordSaved,
}: {
  form: DeliveryForm;
  passwordSaved: boolean;
}) {
  const t = useTranslation();
  const data = form.data as Record<string, string | boolean>;
  const set = (key: string, value: string | boolean) =>
    (form.setData as unknown as (k: string, v: string | boolean) => void)(
      key,
      value,
    );
  const method = String(data.mail_method);
  const text = (field: string, type = 'text', hint?: string, max = 255) => (
    <FormField
      id={field}
      type={type}
      label={t('settings.' + field)}
      hint={hint}
      maxLength={max}
      value={String(data[field] ?? '')}
      error={form.errors[field]}
      onChange={(e) => set(field, e.target.value)}
    />
  );
  const heading = (id: string, title: string, help?: string) => (
    <div>
      <h2 id={id} className="text-base font-semibold">
        {title}
      </h2>
      {help && <p className="mt-1 text-xs leading-5 text-slate-500">{help}</p>}
    </div>
  );
  const select = (
    id: string,
    field: string,
    options: { value: string; label: string }[],
  ) => (
    <div className="space-y-2">
      <label htmlFor={id} className="block text-sm font-medium text-slate-800">
        {t('settings.' + field)}
      </label>
      <Select
        id={id}
        value={String(data[field])}
        invalid={!!form.errors[field]}
        onChange={(value) => set(field, value)}
        options={options}
      />
      {form.errors[field] && (
        <p role="alert" className="text-sm text-red-700">
          {form.errors[field]}
        </p>
      )}
    </div>
  );
  return (
    <>
      <section
        className="space-y-5 border-t border-slate-200 pt-6"
        aria-labelledby="settings-mail-delivery"
      >
        {heading(
          'settings-mail-delivery',
          t('settings.section_mail_delivery'),
          t('settings.mail_method_help'),
        )}
        {select(
          'mail-method',
          'mail_method',
          ['php_mail', 'smtp', 'sendmail'].map((value) => ({
            value,
            label: t(`settings.mail_method_${value}`),
          })),
        )}
      </section>
      {method === 'smtp' && (
        <section
          className="space-y-5 border-t border-slate-200 pt-6"
          aria-labelledby="settings-smtp"
        >
          {heading('settings-smtp', t('settings.section_smtp'))}
          {text('smtp_host', 'text', t('settings.smtp_host_help'))}
          {text('smtp_port', 'number', t('settings.smtp_port_help'), 5)}
          {select(
            'smtp-encryption',
            'smtp_encryption',
            ['none', 'tls', 'ssl'].map((value) => ({
              value,
              label: t(`settings.smtp_encryption_${value}`),
            })),
          )}
          <label className="flex items-start gap-3 text-sm">
            <input
              type="checkbox"
              className="mt-0.5 size-4"
              checked={!!data.smtp_auth}
              onChange={(e) => set('smtp_auth', e.target.checked)}
            />
            <span className="font-medium">{t('settings.smtp_auth')}</span>
          </label>
          {!!data.smtp_auth && (
            <>
              {text('smtp_username')}
              <FormField
                id="smtp_password"
                type="password"
                autoComplete="new-password"
                label={t('settings.smtp_password')}
                hint={t(
                  passwordSaved
                    ? 'settings.smtp_password_saved'
                    : 'settings.smtp_password_help',
                )}
                maxLength={255}
                value={String(data.smtp_password ?? '')}
                error={form.errors.smtp_password}
                onChange={(e) => set('smtp_password', e.target.value)}
              />
              {passwordSaved && (
                <label className="flex items-start gap-3 text-sm">
                  <input
                    type="checkbox"
                    className="mt-0.5 size-4"
                    checked={!!data.smtp_password_clear}
                    onChange={(e) =>
                      set('smtp_password_clear', e.target.checked)
                    }
                  />
                  <span>{t('settings.smtp_password_clear')}</span>
                </label>
              )}
            </>
          )}
        </section>
      )}
      {method === 'sendmail' && (
        <section
          className="space-y-5 border-t border-slate-200 pt-6"
          aria-labelledby="settings-sendmail"
        >
          {heading('settings-sendmail', t('settings.section_sendmail'))}
          {text('sendmail_path', 'text', t('settings.sendmail_path_help'))}
        </section>
      )}
    </>
  );
}
