import UnifiedCard from '../../../components/admin/AdminCard';
import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormErrors from '../../../components/admin/FormErrors';
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
import RequiredMark from '../../../components/admin/RequiredMark';
import Select from '../../../components/admin/Select';
import FormField from '../../../components/auth/FormField';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type {
  PaymentMethodEditor,
  PaymentSettingField,
} from '../../../types/payments';

type Text = { name: string; description: string };

/** Editable values of a method; secret inputs always start empty (stored secrets never reach the browser). */
function initial(method: PaymentMethodEditor, locales: { code: string }[]) {
  return {
    version: method.version,
    is_active: method.active,
    paid_status_id: method.paid_status_id,
    translations: Object.fromEntries(
      locales.map((l) => [
        l.code,
        method.translations[l.code] ?? { name: '', description: '' },
      ]),
    ) as Record<string, Text>,
    settings: Object.fromEntries(
      method.fields.map((field) => [field.key, field.value]),
    ) as Record<string, string>,
    clear: {} as Record<string, boolean>,
    image: null as File | null,
    remove_image: false,
    exclude_physical: method.exclude_physical,
    exclude_digital: method.exclude_digital,
    fee: method.fee,
    max_subtotal: method.max_subtotal,
    excluded_category_ids: method.excluded_category_ids,
    excluded_categories_submitted: true,
  };
}

export default function PaymentMethodForm({
  method,
  statuses,
  defaultLocale,
  categoryOptions,
  ...shell
}: AdminShell & {
  method: PaymentMethodEditor;
  statuses: { id: string; name: string; color: string }[];
  defaultLocale: string;
  /** Every category with its full path, for the exclusions. */
  categoryOptions: { id: string; name: string }[];
}) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const { date, time } = useDateDisplay();
  const [locale, setLocale] = useState(defaultLocale);
  const [category, setCategory] = useState('');
  const form = useForm(initial(method, i18n.locales));
  // After a save or verification the server sends the stored state (new version, secrets emptied again).
  const sync = (page: { props: unknown }) => {
    const next = initial(
      (page.props as unknown as { method: PaymentMethodEditor }).method,
      i18n.locales,
    );
    form.setDefaults(next);
    form.setData(next);
  };
  const errors = form.errors as Record<string, string>;
  const text = form.data.translations[locale] ?? { name: '', description: '' };
  const setText = (patch: Partial<Text>) =>
    form.setData('translations', {
      ...form.data.translations,
      [locale]: { ...text, ...patch },
    });
  const setSetting = (key: string, value: string) =>
    form.setData('settings', { ...form.data.settings, [key]: value });
  const verifiedAt = method.verified_at ? new Date(method.verified_at) : null;

  const field = (setting: PaymentSettingField) => {
    const id = 'setting-' + setting.key;
    const error = errors['settings.' + setting.key];
    if (setting.type === 'boolean') {
      return (
        <label key={setting.key} className="flex items-start gap-2 text-sm">
          <input
            type="checkbox"
            className="mt-0.5"
            checked={form.data.settings[setting.key] === '1'}
            onChange={(e) =>
              setSetting(setting.key, e.target.checked ? '1' : '0')
            }
          />
          <span>
            <span className="font-medium">{setting.label}</span>
            {setting.hint && (
              <span className="block text-xs text-slate-500">
                {setting.hint}
              </span>
            )}
          </span>
        </label>
      );
    }
    if (setting.type === 'select') {
      return (
        <div key={setting.key} className="space-y-2">
          <label
            className="block text-sm font-medium text-slate-800"
            htmlFor={id}
          >
            {setting.label}
            {setting.required && <RequiredMark />}
          </label>
          <Select
            id={id}
            aria-label={setting.label}
            value={form.data.settings[setting.key] ?? ''}
            invalid={!!error}
            onChange={(value) => setSetting(setting.key, value)}
            options={setting.options}
          />
          {setting.hint && (
            <p className="text-xs text-slate-500">{setting.hint}</p>
          )}
          {error && (
            <p role="alert" className="text-sm text-red-700">
              {error}
            </p>
          )}
        </div>
      );
    }
    if (setting.type === 'secret') {
      return (
        <div key={setting.key} className="space-y-2">
          <FormField
            id={id}
            type="password"
            autoComplete="new-password"
            label={setting.label}
            requiredIndicator={setting.required}
            maxLength={setting.max_length}
            value={form.data.settings[setting.key] ?? ''}
            error={error}
            hint={setting.set ? t('payments.secret_set') : setting.hint}
            onChange={(e) => setSetting(setting.key, e.target.value)}
          />
          {setting.set && (
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={form.data.clear[setting.key] ?? false}
                onChange={(e) =>
                  form.setData('clear', {
                    ...form.data.clear,
                    [setting.key]: e.target.checked,
                  })
                }
              />
              {t('payments.secret_clear')}
            </label>
          )}
        </div>
      );
    }
    return (
      <FormField
        key={setting.key}
        id={id}
        label={setting.label}
        requiredIndicator={setting.required}
        maxLength={setting.max_length}
        autoComplete="off"
        value={form.data.settings[setting.key] ?? ''}
        error={error}
        hint={setting.hint}
        onChange={(e) => setSetting(setting.key, e.target.value)}
      />
    );
  };

  return (
    <AdminLayout title={method.name} {...shell}>
      <EditorHeader
        image={method.image?.url}
        heading={method.name}
        iconKey="payments"
        backHref="/admin/payment-methods"
        backLabel={t('payments.back')}
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {method.owner_title
                ? t('payments.addon', { name: method.owner_title })
                : t('payments.core')}
              {' · '}
              {t(
                method.kind === 'redirect'
                  ? 'payments.kind_redirect'
                  : 'payments.kind_offline',
              )}
            </p>
          </>
        }
        actions={
          <>
            <EditorActionButton
              disabled={form.processing}
              form="admin-paymentmethods-form-0"
            >
              {t(form.processing ? 'common.wait' : 'common.save')}
            </EditorActionButton>
          </>
        }
      />
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          const url = '/admin/payment-methods/' + method.code;
          const options = { preserveScroll: true, onSuccess: sync };
          // A chosen image travels as multipart, which PHP only parses for POST (the update is method-spoofed).
          if (form.data.image) {
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(url, { ...options, forceFormData: true });
          } else {
            form.transform((data) => data);
            form.put(url, options);
          }
        }}
        id="admin-paymentmethods-form-0"
      >
        <FormErrors errors={errors} />
        {method.issues.length > 0 && (
          <div
            role="status"
            className="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
          >
            <p className="font-medium">{t('payments.issues_title')}</p>
            <ul className="mt-2 list-inside list-disc">
              {method.issues.map((issue) => (
                <li key={issue}>{issue}</li>
              ))}
            </ul>
          </div>
        )}
        <section>
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
            bodyClassName="p-5 space-y-5"
          >
            <AdminTabs
              language={
                <ContentLanguageSelector
                  languages={i18n.locales}
                  value={locale}
                  onChange={setLocale}
                  errors={errors}
                />
              }
            />
            <FormField
              id={'name-' + locale}
              label={t('common.name') + ' · ' + locale}
              requiredIndicator={locale === defaultLocale}
              maxLength={100}
              value={text.name}
              error={errors['translations.' + locale + '.name']}
              onChange={(e) => setText({ name: e.target.value })}
            />
            <div className="space-y-2">
              <label
                className="block text-sm font-medium"
                htmlFor="description"
              >
                {t('payments.description')}
              </label>
              <textarea
                id="description"
                className="field min-h-20"
                maxLength={500}
                value={text.description}
                onChange={(e) => setText({ description: e.target.value })}
              />
            </div>
            <SingleImageUploader
              title={t('payments.image')}
              help={t('payments.image_help')}
              current={method.image}
              alt={method.name}
              file={form.data.image}
              onFile={(file) => form.setData('image', file)}
              removed={form.data.remove_image}
              onRemoved={(value) => form.setData('remove_image', value)}
              progress={
                form.processing && form.data.image
                  ? (form.progress?.percentage ?? 0)
                  : null
              }
              disabled={form.processing}
              error={
                errors.image ? (
                  <p role="alert" className="text-sm text-red-700">
                    {errors.image}
                  </p>
                ) : undefined
              }
            />
          </UnifiedCard>
        </section>
        <section>
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
            bodyClassName="p-5 grid gap-5 sm:grid-cols-2"
          >
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5"
                checked={form.data.is_active}
                onChange={(e) => form.setData('is_active', e.target.checked)}
              />
              <span>
                <span className="font-medium">
                  {t('payments.enabled_label')}
                </span>
                <span className="block text-xs text-slate-500">
                  {t('payments.enabled_hint')}
                </span>
              </span>
            </label>
            <div className="space-y-2">
              <label
                className="block text-sm font-medium text-slate-800"
                htmlFor="paid-status"
              >
                {t('payments.paid_status')}
              </label>
              <Select
                id="paid-status"
                aria-label={t('payments.paid_status')}
                value={form.data.paid_status_id}
                invalid={!!errors.paid_status_id}
                onChange={(value) => form.setData('paid_status_id', value)}
                options={[
                  { value: '', label: t('payments.paid_status_none') },
                  ...statuses.map((s) => ({
                    value: s.id,
                    label: s.name,
                    color: s.color,
                  })),
                ]}
              />
              <p className="text-xs text-slate-500">
                {t('payments.paid_status_hint')}
              </p>
            </div>
            <FormField
              id="fee"
              label={`${t('payments.fee')} (${method.fee_currency})`}
              inputMode="decimal"
              value={form.data.fee}
              hint={t('payments.fee_hint')}
              error={errors.fee}
              onChange={(e) => form.setData('fee', e.target.value)}
            />
          </UnifiedCard>
        </section>
        <section>
          <UnifiedCard
            title={t('payments.restrictions_title')}
            iconKey="restrictions_title"
            bodyClassName="p-5 space-y-5"
          >
            <p className="mt-1 text-sm text-slate-500">
              {t('payments.restrictions_hint')}
            </p>

            <FormField
              id="max_subtotal"
              label={`${t('payments.max_subtotal')} (${method.fee_currency})`}
              inputMode="decimal"
              value={form.data.max_subtotal}
              hint={t('payments.max_subtotal_hint')}
              error={errors.max_subtotal}
              onChange={(e) => form.setData('max_subtotal', e.target.value)}
            />
            <div className="grid gap-3 sm:grid-cols-2">
              {(['exclude_physical', 'exclude_digital'] as const).map((key) => (
                <label key={key} className="flex items-start gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="mt-0.5"
                    checked={form.data[key]}
                    onChange={(e) => form.setData(key, e.target.checked)}
                  />
                  <span>
                    <span className="font-medium">{t('payments.' + key)}</span>
                    <span className="block text-xs text-slate-500">
                      {t('payments.' + key + '_hint')}
                    </span>
                  </span>
                </label>
              ))}
            </div>
            {errors.exclude_digital && (
              <p role="alert" className="text-sm text-red-700">
                {errors.exclude_digital}
              </p>
            )}
            <div className="space-y-2">
              <label
                className="block text-sm font-medium text-slate-800"
                htmlFor="excluded-category"
              >
                {t('payments.excluded_categories')}
              </label>
              <div className="flex gap-2">
                <div className="min-w-0 flex-1">
                  <Select
                    id="excluded-category"
                    aria-label={t('payments.excluded_categories')}
                    value={category}
                    onChange={setCategory}
                    options={[
                      {
                        value: '',
                        label: t('payments.excluded_categories_pick'),
                      },
                      ...categoryOptions
                        .filter(
                          (o) =>
                            !form.data.excluded_category_ids.includes(o.id),
                        )
                        .map((o) => ({ value: o.id, label: o.name })),
                    ]}
                  />
                </div>
                <button
                  type="button"
                  className="button-secondary"
                  disabled={!category}
                  onClick={() => {
                    form.setData('excluded_category_ids', [
                      ...form.data.excluded_category_ids,
                      category,
                    ]);
                    setCategory('');
                  }}
                >
                  {t('payments.excluded_categories_add')}
                </button>
              </div>
              <p className="text-xs text-slate-500">
                {t('payments.excluded_categories_hint')}
              </p>
              {form.data.excluded_category_ids.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                  {form.data.excluded_category_ids.map((id) => {
                    const name =
                      categoryOptions.find((o) => o.id === id)?.name ??
                      '#' + id;
                    return (
                      <li
                        key={id}
                        className="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 py-1 pr-1 pl-3 text-sm"
                      >
                        {name}
                        <button
                          type="button"
                          className="grid size-6 place-items-center rounded-full text-slate-500 hover:bg-slate-200"
                          aria-label={t('payments.excluded_categories_remove', {
                            name,
                          })}
                          onClick={() =>
                            form.setData(
                              'excluded_category_ids',
                              form.data.excluded_category_ids.filter(
                                (c) => c !== id,
                              ),
                            )
                          }
                        >
                          ×
                        </button>
                      </li>
                    );
                  })}
                </ul>
              )}
              {Object.entries(errors)
                .filter(([key]) => key.startsWith('excluded_category_ids'))
                .map(([key, message]) => (
                  <p key={key} role="alert" className="text-sm text-red-700">
                    {message}
                  </p>
                ))}
            </div>
          </UnifiedCard>
        </section>
        <section>
          <AdminCard
            title={t('payments.settings_title')}
            iconKey="settings_title"
            bodyClassName="p-5 space-y-5"
          >
            {method.fields.length === 0 ? (
              <p className="text-sm text-slate-500">
                {t('payments.no_settings')}
              </p>
            ) : (
              method.fields.map(field)
            )}
            {method.kind === 'redirect' && (
              <div className="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-5">
                <button
                  type="button"
                  className="button-secondary"
                  disabled={form.processing || form.isDirty}
                  onClick={() =>
                    router.post(
                      '/admin/payment-methods/' + method.code + '/verify',
                      {},
                      { preserveScroll: true, onSuccess: sync },
                    )
                  }
                >
                  {t('payments.verify')}
                </button>
                {verifiedAt && (
                  <span className="text-sm text-slate-500">
                    {t('payments.verified_at', {
                      date:
                        date.format(verifiedAt) + ' ' + time.format(verifiedAt),
                    })}
                  </span>
                )}
              </div>
            )}
          </AdminCard>
        </section>
      </form>
    </AdminLayout>
  );
}
