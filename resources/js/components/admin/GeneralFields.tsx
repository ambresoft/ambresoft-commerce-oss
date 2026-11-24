import type { InertiaFormProps } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import Select from './Select';
import TargetPicker from '../../pages/Admin/Promotions/TargetPicker';
import { Icon } from './list/IconButton';
import { useTranslation } from '../../lib/useTranslation';

export type BasicValues = {
  storefront_open: boolean;
  storefront_noindex: boolean;
  closed_messages: Record<string, string>;
  date_format: string;
  time_format: string;
  timezone: string;
  calendar_date_format: string;
  calendar_week_start: number;
  warn_unsaved_changes: boolean;
  show_menu_descriptions: boolean;
  notification_duration: number;
  site_max_width: number;
  show_back_to_top: boolean;
  phone_validation: string;
  weight_unit: string;
  grams_per_weight_unit: string;
  low_stock_threshold: number;
  allow_product_comparison: boolean;
  track_inventory: boolean;
  single_coupon: boolean;
  cart_codes_enabled: boolean;
  hide_product_prices: boolean;
  hide_out_of_stock_listings: boolean;
  blend_product_images: boolean;
  product_card_style: string;
  wishlist_enabled: boolean;
  reviews_enabled: boolean;
  cart_enabled: boolean;
  checkout_requires_account: boolean;
  brand_landing_pages: boolean;
  brand_landing_pages_confirmed: boolean;
  show_prices_without_tax: boolean;
  bestsellers_days: number;
  bestsellers_min_units: number;
  bestsellers_min_orders: number;
  bestsellers_excluded_categories: string[];
  new_products_days: number;
  reels_days: number;
  comparison_max_products: number;
  price_hidden_messages: Record<string, string>;
};

export const basicDefaults: BasicValues = {
  storefront_open: true,
  storefront_noindex: false,
  closed_messages: {},
  date_format: 'd/m/Y',
  time_format: 'H:i',
  timezone: 'UTC',
  calendar_date_format: 'd/m/Y',
  calendar_week_start: 1,
  warn_unsaved_changes: true,
  show_menu_descriptions: true,
  notification_duration: 5,
  site_max_width: 1320,
  show_back_to_top: true,
  phone_validation: 'formatted',
  weight_unit: 'kg',
  grams_per_weight_unit: '1000',
  low_stock_threshold: 0,
  allow_product_comparison: true,
  track_inventory: true,
  single_coupon: true,
  cart_codes_enabled: true,
  hide_product_prices: false,
  hide_out_of_stock_listings: false,
  blend_product_images: true,
  product_card_style: 'classic',
  wishlist_enabled: true,
  reviews_enabled: true,
  cart_enabled: true,
  checkout_requires_account: false,
  brand_landing_pages: true,
  brand_landing_pages_confirmed: false,
  show_prices_without_tax: false,
  bestsellers_days: 0,
  bestsellers_min_units: 1,
  bestsellers_min_orders: 1,
  bestsellers_excluded_categories: [],
  new_products_days: 90,
  reels_days: 90,
  comparison_max_products: 4,
  price_hidden_messages: {},
};

export const basicFields = {
  basic: [
    'storefront_open',
    'storefront_noindex',
    'closed_messages',
    'phone_validation',
    'weight_unit',
    'grams_per_weight_unit',
  ],
  appearance: [
    'site_max_width',
    'show_back_to_top',
    'date_format',
    'time_format',
    'timezone',
    'calendar_date_format',
    'calendar_week_start',
    'warn_unsaved_changes',
    'show_menu_descriptions',
    'notification_duration',
  ],
  catalog: [
    'allow_product_comparison',
    'hide_out_of_stock_listings',
    'blend_product_images',
    'product_card_style',
    'wishlist_enabled',
    'reviews_enabled',
    'cart_enabled',
    'checkout_requires_account',
    'brand_landing_pages',
    'brand_landing_pages_confirmed',
    'show_prices_without_tax',
    'bestsellers_days',
    'bestsellers_min_units',
    'bestsellers_min_orders',
    'bestsellers_excluded_categories',
    'new_products_days',
    'reels_days',
    'comparison_max_products',
    'hide_product_prices',
    'price_hidden_messages',
  ],
  promotions: ['cart_codes_enabled', 'single_coupon'],
} as const;

export function GeneralFields({
  tab,
  form,
  timezones,
  messageLocales,
  canManageBrandLandings,
  categoryOptions = [],
}: {
  categoryOptions?: { id: string; name: string }[];
  tab: string;
  canManageBrandLandings: boolean;
  form: Pick<InertiaFormProps<BasicValues>, 'data' | 'errors' | 'setData'>;
  timezones: string[];
  messageLocales: { code: string; name: string; defaultMessage: string }[];
}) {
  const t = useTranslation();
  const { data, setData } = form;
  const [landingChange, setLandingChange] = useState<boolean | null>(null);
  const landingDialog = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    if (landingChange !== null) landingDialog.current?.showModal();
    else landingDialog.current?.close();
  }, [landingChange]);
  const error = (field: string) =>
    (form.errors as Record<string, string>)[field];
  const toggle = (
    field:
      | 'storefront_open'
      | 'storefront_noindex'
      | 'show_back_to_top'
      | 'warn_unsaved_changes'
      | 'show_menu_descriptions'
      | 'allow_product_comparison'
      | 'track_inventory'
      | 'single_coupon'
      | 'cart_codes_enabled'
      | 'hide_product_prices'
      | 'hide_out_of_stock_listings'
      | 'blend_product_images'
      | 'wishlist_enabled'
      | 'reviews_enabled'
      | 'cart_enabled'
      | 'checkout_requires_account'
      | 'show_prices_without_tax',
    inverted = false,
  ) => (
    <div>
      <label className="flex items-start gap-3 text-sm">
        <input
          id={field}
          type="checkbox"
          className="mt-0.5 size-4"
          checked={inverted ? !data[field] : data[field]}
          onChange={(e) =>
            setData(field, inverted ? !e.target.checked : e.target.checked)
          }
        />
        <span>
          <span className="font-medium">{t('settings.' + field)}</span>
          <span className="mt-0.5 block text-xs leading-5 text-slate-500">
            {t('settings.' + field + '_help')}
          </span>
        </span>
      </label>
      {error(field) && (
        <p role="alert" className="mt-1 text-sm text-red-700">
          {error(field)}
        </p>
      )}
    </div>
  );
  const choice = (
    field:
      | 'date_format'
      | 'time_format'
      | 'timezone'
      | 'calendar_date_format'
      | 'phone_validation'
      | 'product_card_style',
    options: { value: string; label: string }[],
  ) => (
    <SettingsField
      id={field}
      label={t('settings.' + field)}
      error={error(field)}
    >
      <div className="w-full">
        <Select
          id={field}
          value={data[field]}
          options={options}
          invalid={!!error(field)}
          onChange={(value) => setData(field, value)}
        />
      </div>
    </SettingsField>
  );
  const dates = [
    { value: 'd/m/Y', label: '29/09/2005' },
    { value: 'm/d/Y', label: '09/29/2005' },
    { value: 'Y-m-d', label: '2005-09-29' },
    { value: 'd.m.Y', label: '29.09.2005' },
  ];
  return (
    <div className="space-y-6">
      {tab === 'basic' && (
        <>
          <SettingsSection title={t('settings.section_storefront')}>
            {toggle('storefront_open')}
            {toggle('storefront_noindex')}
            <p
              role="status"
              className={
                'rounded-lg px-4 py-3 text-sm ' +
                (data.storefront_open
                  ? 'bg-emerald-50 text-emerald-900'
                  : 'bg-amber-50 text-amber-900')
              }
            >
              {t(
                data.storefront_open
                  ? 'settings.store_open'
                  : 'settings.store_closed',
              )}
            </p>
            {messageLocales.map((locale) => (
              <SettingsField
                key={locale.code}
                id={'closed-message-' + locale.code}
                label={t('settings.closed_message') + ' · ' + locale.name}
                help={t('settings.closed_message_help')}
                error={error('closed_messages.' + locale.code)}
                required
              >
                <textarea
                  id={'closed-message-' + locale.code}
                  className="field min-h-24"
                  maxLength={2000}
                  value={data.closed_messages[locale.code] ?? ''}
                  onChange={(e) =>
                    setData('closed_messages', {
                      ...data.closed_messages,
                      [locale.code]: e.target.value,
                    })
                  }
                />
              </SettingsField>
            ))}
          </SettingsSection>
          <SettingsSection title={t('settings.section_contact')}>
            {choice('phone_validation', [
              { value: 'formatted', label: t('settings.phone_formatted') },
              { value: 'digits', label: t('settings.phone_digits') },
            ])}
            <p className="text-xs text-slate-500">
              {t('settings.phone_validation_help')}
            </p>
          </SettingsSection>
          <SettingsSection title={t('settings.section_weight')}>
            <SettingsField
              id="weight_unit"
              label={t('settings.weight_unit')}
              error={error('weight_unit')}
            >
              <input
                id="weight_unit"
                className="field"
                value={data.weight_unit}
                maxLength={20}
                onChange={(e) => setData('weight_unit', e.target.value)}
              />
            </SettingsField>
            <SettingsField
              id="grams_per_weight_unit"
              label={t('settings.grams_per_weight_unit')}
              help={t('settings.weight_help')}
              error={error('grams_per_weight_unit')}
            >
              <input
                id="grams_per_weight_unit"
                className="field"
                inputMode="decimal"
                value={data.grams_per_weight_unit}
                onChange={(e) =>
                  setData('grams_per_weight_unit', e.target.value)
                }
              />
            </SettingsField>
          </SettingsSection>
        </>
      )}
      {tab === 'appearance' && (
        <>
          <SettingsSection title={t('settings.section_storefront_layout')}>
            {toggle('show_back_to_top')}
            <SettingsField
              id="site_max_width"
              label={t('settings.site_max_width')}
              help={t('settings.site_max_width_help')}
              error={error('site_max_width')}
            >
              <input
                id="site_max_width"
                type="number"
                min={320}
                max={3840}
                step={1}
                required
                className="field"
                value={data.site_max_width}
                onChange={(e) =>
                  setData('site_max_width', Number(e.target.value))
                }
              />
            </SettingsField>
          </SettingsSection>
          <SettingsSection title={t('settings.section_datetime')}>
            {choice('date_format', dates)}
            {choice('time_format', [
              { value: 'H:i', label: '15:43' },
              { value: 'h:i A', label: '03:43 PM' },
            ])}
            {choice(
              'timezone',
              timezones.map((value) => ({
                value,
                label: value.replaceAll('_', ' '),
              })),
            )}
            {choice('calendar_date_format', dates)}
            <SettingsField
              id="calendar_week_start"
              label={t('settings.calendar_week_start')}
              error={error('calendar_week_start')}
            >
              <div className="w-full">
                <Select
                  id="calendar_week_start"
                  value={String(data.calendar_week_start)}
                  onChange={(value) =>
                    setData('calendar_week_start', Number(value))
                  }
                  options={[
                    { value: '1', label: t('settings.monday') },
                    { value: '0', label: t('settings.sunday') },
                  ]}
                />
              </div>
            </SettingsField>
          </SettingsSection>
          <SettingsSection title={t('settings.section_admin')}>
            {toggle('warn_unsaved_changes')}
            {toggle('show_menu_descriptions')}
            <SettingsField
              id="notification_duration"
              label={t('settings.notification_duration')}
              help={t('settings.notification_duration_help')}
              error={error('notification_duration')}
            >
              <input
                id="notification_duration"
                type="number"
                min={0}
                max={300}
                step={1}
                className="field"
                value={data.notification_duration}
                onChange={(e) =>
                  setData('notification_duration', Number(e.target.value))
                }
              />
            </SettingsField>
          </SettingsSection>
        </>
      )}
      {tab === 'catalog' && (
        <>
          <SettingsSection title={t('settings.tab_catalog')}>
            {toggle('allow_product_comparison')}
            {data.allow_product_comparison && (
              <SettingsField
                id="comparison_max_products"
                label={t('settings.comparison_max_products')}
                help={t('settings.comparison_max_products_help')}
                error={error('comparison_max_products')}
              >
                <input
                  id="comparison_max_products"
                  type="number"
                  min={2}
                  max={10}
                  step={1}
                  required
                  className="field"
                  value={data.comparison_max_products}
                  onChange={(e) =>
                    setData('comparison_max_products', Number(e.target.value))
                  }
                />
              </SettingsField>
            )}
            {toggle('hide_out_of_stock_listings')}
            {toggle('blend_product_images')}
            {toggle('wishlist_enabled')}
            {toggle('reviews_enabled')}
            {toggle('show_prices_without_tax')}
            <fieldset className="space-y-4 rounded-lg border border-slate-200 p-4">
              <legend className="px-2 font-semibold">
                {t('settings.bestsellers')}
              </legend>
              <p className="text-xs text-slate-500">
                {t('settings.bestsellers_help')}
              </p>
              {(
                [
                  'bestsellers_days',
                  'bestsellers_min_units',
                  'bestsellers_min_orders',
                ] as const
              ).map((field) => (
                <SettingsField
                  key={field}
                  id={field}
                  label={t('settings.' + field)}
                  help={t('settings.' + field + '_help')}
                  error={error(field)}
                >
                  <input
                    id={field}
                    type="number"
                    min={field === 'bestsellers_days' ? 0 : 1}
                    max={field === 'bestsellers_days' ? 3650 : 1000000}
                    step={1}
                    required
                    className="field"
                    value={data[field]}
                    onChange={(e) => setData(field, Number(e.target.value))}
                  />
                </SettingsField>
              ))}
              <TargetPicker
                label={t('settings.bestsellers_excluded_categories')}
                kind="categories"
                values={data.bestsellers_excluded_categories}
                options={categoryOptions}
                path="/admin/settings/general"
                query={{ tab: 'catalog' }}
                onChange={(ids) =>
                  setData('bestsellers_excluded_categories', ids)
                }
              />
              <p className="text-xs text-slate-500">
                {t('settings.bestsellers_excluded_categories_help')}
              </p>
              {Object.entries(form.errors)
                .filter(([key]) =>
                  key.startsWith('bestsellers_excluded_categories'),
                )
                .map(([key, message]) => (
                  <p key={key} role="alert" className="text-sm text-red-700">
                    {message}
                  </p>
                ))}
            </fieldset>
            <SettingsField
              id="new_products_days"
              label={t('settings.new_products_days')}
              help={t('settings.new_products_days_help')}
              error={error('new_products_days')}
            >
              <input
                id="new_products_days"
                type="number"
                min={1}
                max={3650}
                step={1}
                required
                className="field"
                value={data.new_products_days}
                onChange={(e) =>
                  setData('new_products_days', Number(e.target.value))
                }
              />
            </SettingsField>
            <SettingsField
              id="reels_days"
              label={t('reels.days')}
              help={t('reels.days_help')}
              error={error('reels_days')}
            >
              <input
                id="reels_days"
                type="number"
                min={1}
                max={3650}
                required
                className="field"
                value={data.reels_days}
                onChange={(e) => setData('reels_days', Number(e.target.value))}
              />
            </SettingsField>
            {choice('product_card_style', [
              {
                value: 'classic',
                label: t('settings.product_card_style_classic'),
              },
              {
                value: 'showcase',
                label: t('settings.product_card_style_showcase'),
              },
              {
                value: 'signature',
                label: t('settings.product_card_style_signature'),
              },
            ])}
            <p className="-mt-2 text-xs text-slate-500">
              {t('settings.product_card_style_help')}
            </p>
          </SettingsSection>
          <SettingsSection title={t('settings.section_landing_pages')}>
            <label
              className={
                'flex items-start gap-3 text-sm' +
                (!canManageBrandLandings
                  ? ' cursor-not-allowed opacity-50 [&_*]:cursor-not-allowed'
                  : '')
              }
            >
              <input
                id="brand_landing_pages"
                type="checkbox"
                className="mt-0.5 size-4"
                checked={data.brand_landing_pages}
                disabled={!canManageBrandLandings}
                onChange={(e) => {
                  if (canManageBrandLandings)
                    setLandingChange(e.target.checked);
                }}
              />
              <span>
                <span className="font-medium">
                  {t('settings.brand_landing_pages')}
                </span>
                <span className="mt-0.5 block text-xs leading-5 text-slate-500">
                  {t('settings.brand_landing_pages_help')}
                </span>
              </span>
            </label>
            {!canManageBrandLandings && (
              <p className="text-xs text-slate-500">
                {t('settings.landing_source_permission_required')}
              </p>
            )}
            <p className="flex items-start gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <Icon name="warning" className="mt-0.5 size-5 shrink-0" />
              <span>{t('settings.landing_source_warning')}</span>
            </p>
            {error('brand_landing_pages') && (
              <p role="alert" className="text-sm text-red-700">
                {error('brand_landing_pages')}
              </p>
            )}
            <dialog
              ref={landingDialog}
              aria-labelledby="landing-source-title"
              className="m-auto w-[calc(100%_-_2rem)] max-w-lg rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-2xl backdrop:bg-black/50"
              onCancel={() => setLandingChange(null)}
            >
              <h2 id="landing-source-title" className="text-lg font-semibold">
                {t('settings.landing_source_title')}
              </h2>
              <p className="my-4 flex items-start gap-3 rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
                <Icon name="warning" className="mt-0.5 size-5 shrink-0" />
                <span>{t('settings.landing_source_warning')}</span>
              </p>
              <p className="text-sm text-slate-500">
                {t('settings.landing_source_preserved')}
              </p>
              <div className="mt-6 flex justify-end gap-2">
                <button
                  type="button"
                  className="button-secondary"
                  onClick={() => setLandingChange(null)}
                >
                  {t('common.cancel')}
                </button>
                <button
                  type="button"
                  className="button-primary"
                  onClick={() => {
                    if (landingChange === null) return;
                    setData('brand_landing_pages', landingChange);
                    setData('brand_landing_pages_confirmed', true);
                    setLandingChange(null);
                  }}
                >
                  {t('settings.landing_source_confirm')}
                </button>
              </div>
            </dialog>
          </SettingsSection>
          <SettingsSection title={t('settings.section_prices')}>
            {toggle('hide_product_prices')}
            {toggle('cart_enabled', true)}
            {data.cart_enabled && toggle('checkout_requires_account')}
            {data.hide_product_prices && (
              <>
                <p
                  role="status"
                  className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                  {t('settings.prices_hidden_notice')}
                </p>
                {messageLocales.map((locale) => (
                  <SettingsField
                    key={locale.code}
                    id={'price-hidden-message-' + locale.code}
                    label={
                      t('settings.price_hidden_message') + ' · ' + locale.name
                    }
                    help={t('settings.price_hidden_message_help')}
                    error={error('price_hidden_messages.' + locale.code)}
                  >
                    <input
                      id={'price-hidden-message-' + locale.code}
                      className="field"
                      maxLength={500}
                      value={data.price_hidden_messages[locale.code] ?? ''}
                      onChange={(e) =>
                        setData('price_hidden_messages', {
                          ...data.price_hidden_messages,
                          [locale.code]: e.target.value,
                        })
                      }
                    />
                  </SettingsField>
                ))}
              </>
            )}
          </SettingsSection>
        </>
      )}
      {tab === 'inventory' && (
        <SettingsSection title={t('settings.section_inventory')}>
          {toggle('track_inventory')}
          <SettingsField
            id="low_stock_threshold"
            label={t('settings.low_stock_threshold')}
            help={t('settings.low_stock_threshold_help')}
            error={error('low_stock_threshold')}
          >
            <input
              id="low_stock_threshold"
              type="number"
              min={0}
              max={2147483647}
              step={1}
              className="field"
              value={data.low_stock_threshold}
              onChange={(e) =>
                setData('low_stock_threshold', Number(e.target.value))
              }
            />
          </SettingsField>
        </SettingsSection>
      )}
      {tab === 'promotions' && (
        <SettingsSection title={t('settings.tab_promotions')}>
          {toggle('cart_codes_enabled')}
          {toggle('single_coupon')}
        </SettingsSection>
      )}
    </div>
  );
}

function SettingsSection({
  title,
  children,
}: {
  title: string;
  children: ReactNode;
}) {
  return (
    <section className="space-y-4 border-t border-slate-200 pt-6 first:border-0 first:pt-0">
      <h2 className="text-base font-semibold">{title}</h2>
      {children}
    </section>
  );
}

function SettingsField({
  id,
  label,
  help,
  error,
  required,
  children,
}: {
  id: string;
  label: string;
  help?: string;
  error?: string;
  required?: boolean;
  children: ReactNode;
}) {
  return (
    <div className="space-y-2">
      <label htmlFor={id} className="block text-sm font-medium text-slate-800">
        {label}
        {required && (
          <span aria-hidden="true" className="ml-0.5 text-red-600">
            *
          </span>
        )}
      </label>
      {children}
      {help && <p className="text-xs leading-5 text-slate-500">{help}</p>}
      {error && (
        <p role="alert" className="text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  );
}
