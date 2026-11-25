import { useState } from 'react';
import FormField from '../auth/FormField';
import { Section, CheckField } from './form/fields';
import Select from './Select';
import UnderlineTabs from './UnderlineTabs';
import { useTranslation } from '../../lib/useTranslation';
import CheckoutInvoiceCountries from './CheckoutInvoiceCountries';

export type CheckoutCredentials =
  'aade_username' | 'aade_password' | 'vatsense_key';
export type CheckoutSettings = {
  enabled: boolean;
  version: number;
  greek_provider: 'aade' | 'vies' | 'local';
  international_provider: 'vies' | 'vatsense';
  country_codes: string[];
  aade_configured: boolean;
  vatsense_configured: boolean;
};
export type CheckoutValues = Pick<
  CheckoutSettings,
  | 'version'
  | 'enabled'
  | 'greek_provider'
  | 'international_provider'
  | 'country_codes'
> &
  Record<CheckoutCredentials, string> &
  Record<`clear_${CheckoutCredentials}`, boolean>;
const tabs = ['general', 'greek', 'international', 'countries'] as const;

export default function CheckoutSettingsFields({
  value,
  saved,
  onChange,
  errors,
  countries,
  countryCoverage,
}: {
  value: CheckoutValues;
  saved: CheckoutSettings;
  onChange: (value: CheckoutValues) => void;
  errors: Record<string, string | undefined>;
  countries: { code: string; name: string }[];
  countryCoverage: Record<string, string[]>;
}) {
  const t = useTranslation();
  const [tab, setTab] = useState<(typeof tabs)[number]>(() =>
    Object.keys(errors).some(
      (key) => key === 'country_codes' || key.startsWith('country_codes.'),
    )
      ? 'countries'
      : errors.aade_username || errors.aade_password || errors.greek_provider
        ? 'greek'
        : errors.vatsense_key || errors.international_provider
          ? 'international'
          : 'general',
  );
  const secret = (key: CheckoutCredentials) => {
    const configured =
      key === 'vatsense_key'
        ? saved.vatsense_configured
        : saved.aade_configured;
    return (
      <div key={key} className="space-y-3">
        <FormField
          id={'checkout-' + key}
          label={t('checkout.' + key)}
          type="password"
          autoComplete="new-password"
          maxLength={500}
          value={value[key]}
          onChange={(e) => onChange({ ...value, [key]: e.target.value })}
          error={errors[key]}
          hint={configured ? t('checkout.saved_secret') : undefined}
        />
        {configured && (
          <CheckField
            id={'checkout-clear-' + key}
            label={t('checkout.clear_secret', { field: t('checkout.' + key) })}
            checked={value[`clear_${key}`]}
            onChange={(clear) =>
              onChange({ ...value, [`clear_${key}`]: clear })
            }
          />
        )}
      </div>
    );
  };
  const provider = (
    field: 'greek_provider' | 'international_provider',
    choices: string[],
  ) => (
    <div className="space-y-2">
      <label
        htmlFor={'checkout-' + field}
        className="block text-sm font-medium text-slate-800"
      >
        {t('checkout.provider')}
      </label>
      <Select
        id={'checkout-' + field}
        value={value[field]}
        invalid={!!errors[field]}
        options={choices.map((key) => ({
          value: key,
          label: t(`checkout.provider_${key}`),
        }))}
        onChange={(selected) => onChange({ ...value, [field]: selected })}
      />
      {errors[field] && (
        <p role="alert" className="text-sm text-red-700">
          {errors[field]}
        </p>
      )}
    </div>
  );
  return (
    <div className="space-y-5">
      <UnderlineTabs
        label={t('checkout.settings_title')}
        idPrefix="checkout-settings"
        active={tab}
        onSelect={setTab}
        tabs={tabs.map((key) => ({
          key,
          label: t(`checkout.tab_${key}`),
          error:
            key === 'greek'
              ? !!(
                  errors.aade_username ||
                  errors.aade_password ||
                  errors.greek_provider
                )
              : key === 'international'
                ? !!(errors.vatsense_key || errors.international_provider)
                : key === 'countries'
                  ? Object.keys(errors).some(
                      (field) =>
                        field === 'country_codes' ||
                        field.startsWith('country_codes.'),
                    )
                  : !!errors.enabled,
        }))}
      />
      <div
        role="tabpanel"
        id={'checkout-settings-panel-' + tab}
        aria-labelledby={'checkout-settings-tab-' + tab}
      >
        <Section
          title={t(`checkout.tab_${tab}`)}
          iconKey="orders"
          description={t(
            'checkout.' + (tab === 'general' ? 'settings_help' : tab + '_help'),
          )}
        >
          {tab === 'general' && (
            <>
              <CheckField
                id="checkout-enabled"
                label={t('checkout.enabled')}
                checked={value.enabled}
                onChange={(enabled) => onChange({ ...value, enabled })}
              />
              {errors.enabled && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.enabled}
                </p>
              )}
              <p className="text-sm text-slate-600">
                {t('checkout.coverage_help')}
              </p>
            </>
          )}
          {tab === 'greek' && (
            <>
              {provider('greek_provider', ['vies', 'aade', 'local'])}
              <p className="text-sm text-slate-600">
                {t(
                  'checkout.' +
                    (value.greek_provider === 'aade'
                      ? 'aade_help'
                      : value.greek_provider === 'local'
                        ? 'local_help'
                        : 'vies_help'),
                )}
              </p>
              {value.greek_provider === 'aade' && (
                <>
                  {secret('aade_username')}
                  {secret('aade_password')}
                </>
              )}
            </>
          )}
          {tab === 'international' && (
            <>
              {provider('international_provider', ['vies', 'vatsense'])}
              <p className="text-sm text-slate-600">
                {t(
                  'checkout.' +
                    (value.international_provider === 'vies'
                      ? 'vies_help'
                      : 'global_help'),
                )}
              </p>
              {value.international_provider === 'vatsense' &&
                secret('vatsense_key')}
            </>
          )}
          {tab === 'countries' && (
            <>
              <CheckoutInvoiceCountries
                countries={countries}
                selected={value.country_codes}
                covered={countries
                  .filter(({ code }) =>
                    (
                      countryCoverage[
                        code === 'GR'
                          ? value.greek_provider
                          : value.international_provider
                      ] ?? []
                    ).includes(code),
                  )
                  .map(({ code }) => code)}
                onChange={(country_codes) =>
                  onChange({ ...value, country_codes })
                }
              />
              {Object.entries(errors)
                .filter(
                  ([key]) =>
                    key === 'country_codes' || key.startsWith('country_codes.'),
                )
                .map(([key, error]) => (
                  <p key={key} role="alert" className="text-sm text-red-700">
                    {error}
                  </p>
                ))}
            </>
          )}
        </Section>
      </div>
    </div>
  );
}
