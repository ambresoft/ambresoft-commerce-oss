import HelpPopover from '../../../components/admin/HelpPopover';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormField from '../../../components/auth/FormField';
import FormErrors from '../../../components/admin/FormErrors';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type {
  CurrencySettings,
  PrimaryChangePreview,
  PrimaryCurrency,
} from '../../../types/currencies';
import type { SharedProps } from '../../../types/page';
import Select from '../../../components/admin/Select';

type Props = AdminShell & {
  currency: CurrencySettings | null;
  primary: PrimaryCurrency;
  primaryChange: PrimaryChangePreview | null;
};
const separators: Record<string, string> = {
  comma: 'currencies.sep_comma',
  period: 'currencies.sep_period',
  space: 'currencies.sep_space',
  apostrophe: 'currencies.sep_apostrophe',
  none: 'currencies.sep_none',
};
const thousands = ['comma', 'period', 'space', 'apostrophe', 'none'] as const;
const decimal = ['period', 'comma'] as const;

export default function CurrencyForm(props: Props) {
  return (
    <Editor
      key={(props.currency?.id ?? 'new') + ':' + props.currency?.version}
      {...props}
    />
  );
}

function Editor({ currency, primary, primaryChange, ...shell }: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const form = useForm({
    code: currency?.code ?? '',
    name: currency?.name ?? '',
    symbol: currency?.symbol ?? '',
    symbol_position: currency?.symbol_position ?? 'before',
    thousands_separator: currency?.thousands_separator ?? 'comma',
    decimal_separator: currency?.decimal_separator ?? 'period',
    decimals: String(currency?.decimals ?? 2),
    rate: currency?.rate ?? '',
    status: currency?.status ?? 'active',
  });
  useDirtyForm(form);
  const errors = form.errors as Record<string, string>;
  const code = form.data.code;
  const isPrimary = currency?.primary ?? false;
  const title = currency
    ? t('currencies.edit') + ' ' + currency.code
    : t('currencies.create');
  const canSave = currency
    ? capabilities['currencies.update'] ||
      capabilities['currencies.rates'] ||
      capabilities['currencies.status']
    : capabilities['currencies.create'];
  const select = (
    field: 'symbol_position' | 'thousands_separator' | 'decimal_separator',
    options: readonly string[],
    label: (option: string) => string,
  ) => (
    <div className="space-y-2">
      <label htmlFor={field} className="block text-sm font-medium">
        {t('currencies.' + field)}
      </label>
      <Select
        id={field}
        value={form.data[field]}
        invalid={!!errors[field]}
        onChange={(value) => form.setData(field, value)}
        options={options.map((option) => ({
          value: option,
          label: label(option),
        }))}
      />
      {errors[field] && (
        <p role="alert" className="text-sm text-red-700">
          {errors[field]}
        </p>
      )}
    </div>
  );
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        status={
          <>
            <div className="flex items-center gap-2">
              <EditorStatusSelect
                aria-label={t('common.status')}
                id="status"
                value={form.data.status}
                disabled={isPrimary}
                onChange={(value) =>
                  form.setData('status', value as 'active' | 'disabled')
                }
                options={[
                  { value: 'active', label: t('currencies.active') },
                  { value: 'disabled', label: t('currencies.disabled') },
                ]}
              />
              <HelpPopover label={t('common.status')}>
                {t(
                  isPrimary
                    ? 'currencies.primary_status_hint'
                    : 'currencies.status_hint',
                )}
              </HelpPopover>
              {errors.status && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.status}
                </p>
              )}
            </div>
          </>
        }
        heading={title}
        iconKey="pricing"
        backHref="/admin/settings/currencies"
        backLabel={t('currencies.title')}
        description={
          <>
            <p className="mt-2 max-w-3xl text-sm text-slate-600">
              {t('currencies.primary_help', primary)}
            </p>
          </>
        }
        actions={
          canSave && (
            <>
              <EditorActionButton
                disabled={form.processing}
                form="admin-settings-form-0"
              >
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            </>
          )
        }
        menu={
          currency &&
          !isPrimary &&
          capabilities['currencies.delete'] && (
            <DeleteCurrency currency={currency} />
          )
        }
      />
      <form
        className="w-full space-y-6 rounded-lg border border-slate-200 bg-white p-6"
        onSubmit={(e) => {
          e.preventDefault();
          if (currency) {
            // The code is immutable after creation; send the version instead.
            form.transform((data) => {
              const update: Partial<typeof data> & { version: number } = {
                ...data,
                version: currency.version,
              };
              delete update.code;
              return update;
            });
            form.put('/admin/settings/currencies/' + currency.id);
          } else form.post('/admin/settings/currencies');
        }}
        id="admin-settings-form-0"
      >
        <FormErrors errors={form.errors} />
        <div className="grid gap-5 sm:grid-cols-2">
          {currency ? (
            <div className="space-y-2">
              <p className="text-sm font-medium">{t('currencies.code')}</p>
              <p className="font-mono">
                {currency.code}{' '}
                {isPrimary && (
                  <span className="ml-2 rounded-full bg-slate-900 px-2 py-0.5 font-sans text-xs font-medium text-white">
                    {t('currencies.primary')}
                  </span>
                )}
              </p>
            </div>
          ) : (
            <FormField
              id="code"
              label={t('currencies.code')}
              hint={t('currencies.code_hint')}
              maxLength={3}
              autoCapitalize="characters"
              value={code}
              error={errors.code}
              onChange={(e) =>
                form.setData('code', e.target.value.toUpperCase())
              }
            />
          )}
          <FormField
            id="name"
            label={t('currencies.name')}
            maxLength={80}
            value={form.data.name}
            error={errors.name}
            onChange={(e) => form.setData('name', e.target.value)}
          />
          <FormField
            id="symbol"
            label={t('currencies.symbol')}
            maxLength={10}
            value={form.data.symbol}
            error={errors.symbol}
            onChange={(e) => form.setData('symbol', e.target.value)}
          />
          {select('symbol_position', ['before', 'after'], (o) =>
            t(o === 'before' ? 'currencies.before' : 'currencies.after'),
          )}
          {select('thousands_separator', thousands, (o) => t(separators[o]))}
          {select('decimal_separator', decimal, (o) => t(separators[o]))}
          <div className="space-y-2">
            <label htmlFor="decimals" className="block text-sm font-medium">
              {t('currencies.decimals')}
            </label>
            <Select
              id="decimals"
              value={form.data.decimals}
              aria-describedby="decimals-hint"
              invalid={!!errors.decimals}
              onChange={(value) => form.setData('decimals', value)}
              options={[0, 1, 2, 3, 4, 5, 6].map((n) => ({
                value: String(n),
                label: String(n),
              }))}
            />
            <p id="decimals-hint" className="text-xs leading-5 text-slate-500">
              {t('currencies.decimals_hint')}
            </p>
            {errors.decimals && (
              <p role="alert" className="text-sm text-red-700">
                {errors.decimals}
              </p>
            )}
          </div>
          <FormField
            id="rate"
            label={t('currencies.rate_label', {
              code: code || '…',
              primary: primary.code,
            })}
            hint={
              isPrimary
                ? t('currencies.rate_primary')
                : t('currencies.rate_hint', {
                    code: code || '…',
                    primary: primary.code,
                  })
            }
            inputMode="decimal"
            readOnly={isPrimary}
            value={form.data.rate}
            error={errors.rate}
            onChange={(e) => form.setData('rate', e.target.value)}
          />
        </div>
      </form>
      {currency &&
        !isPrimary &&
        primaryChange &&
        capabilities['currencies.primary'] && (
          <MakePrimary
            currency={currency}
            primary={primary}
            preview={primaryChange}
          />
        )}
    </AdminLayout>
  );
}

function MakePrimary({
  currency,
  primary,
  preview,
}: {
  currency: CurrencySettings;
  primary: PrimaryCurrency;
  preview: PrimaryChangePreview;
}) {
  const t = useTranslation();
  const [understood, setUnderstood] = useState(false);
  const form = useForm({ version: currency.version, confirmation: '' });
  useDirtyForm(form);
  const blocked = preview.error !== null || currency.status !== 'active';
  return (
    <section
      aria-labelledby="make-primary"
      className="mt-6 w-full space-y-4 rounded-lg border border-amber-300 bg-amber-50 p-6"
    >
      <h2 id="make-primary" className="text-lg font-semibold">
        {t('currencies.make_primary')}
      </h2>
      <p className="text-sm">
        {t('currencies.make_primary_intro', { code: currency.code })}
      </p>
      {preview.error ? (
        <p role="alert" className="text-sm text-red-700">
          {preview.error}
        </p>
      ) : currency.status !== 'active' ? (
        <p role="alert" className="text-sm text-red-700">
          {t('currencies.primary_requires_active', { code: currency.code })}
        </p>
      ) : (
        <ul className="list-disc space-y-2 pl-5 text-sm">
          <li>
            {t('currencies.consequence_prices', {
              count: preview.products,
              from: primary.code,
              to: currency.code,
              rate: currency.rate,
              decimals: currency.decimals,
            })}
          </li>
          <li>
            {t('currencies.consequence_example', {
              from: preview.from,
              to: preview.to ?? '',
            })}
          </li>
          <li>
            {t('currencies.consequence_rates', { to: currency.code })}
            <table className="mt-2 text-left font-mono text-xs tabular-nums">
              <tbody>
                {preview.rates.map((r) => (
                  <tr key={r.code}>
                    <th className="pr-4 font-medium">{r.code}</th>
                    <td className="pr-2">{r.from}</td>
                    <td className="pr-2" aria-hidden="true">
                      →
                    </td>
                    <td>{r.to}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </li>
          <li>{t('currencies.consequence_editors')}</li>
          <li>{t('currencies.consequence_reverse')}</li>
        </ul>
      )}
      {!blocked && (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            form.post('/admin/settings/currencies/' + currency.id + '/primary');
          }}
        >
          <FormErrors errors={form.errors} />
          <label className="flex items-start gap-3 text-sm">
            <input
              type="checkbox"
              className="mt-1"
              checked={understood}
              onChange={(e) => setUnderstood(e.target.checked)}
            />
            {t('currencies.acknowledge')}
          </label>
          <FormField
            id="confirmation"
            label={t('currencies.confirm_label', { code: currency.code })}
            autoComplete="off"
            value={form.data.confirmation}
            error={form.errors.confirmation}
            onChange={(e) => form.setData('confirmation', e.target.value)}
          />
          <button
            className="button-primary"
            disabled={
              form.processing ||
              !understood ||
              form.data.confirmation !== currency.code
            }
          >
            {t(form.processing ? 'common.wait' : 'currencies.make_primary')}
          </button>
        </form>
      )}
    </section>
  );
}

function DeleteCurrency({ currency }: { currency: CurrencySettings }) {
  const t = useTranslation();
  const form = useForm({ version: currency.version });
  useDirtyForm(form);
  return (
    <form
      className="mt-6 w-full"
      onSubmit={(e) => {
        e.preventDefault();
        if (
          window.confirm(
            t('currencies.confirm_delete', { code: currency.code }),
          )
        )
          form.delete('/admin/settings/currencies/' + currency.id);
      }}
    >
      <FormErrors errors={form.errors} />
      <button
        className="button-secondary text-red-700"
        disabled={form.processing}
      >
        {form.processing ? t('common.wait') : t('currencies.delete')}
      </button>
    </form>
  );
}
