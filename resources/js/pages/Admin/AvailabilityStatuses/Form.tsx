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
import FormField from '../../../components/auth/FormField';
import Select from '../../../components/admin/Select';
import { useTranslation } from '../../../lib/useTranslation';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { AvailabilityStatus } from './Index';
type Props = AdminShell & {
  status: AvailabilityStatus | null;
  options: AvailabilityStatus[];
  nameMax: number;
};
export default function Form(props: Props) {
  return (
    <Editor
      key={`${props.status?.id ?? 'new'}:${props.status?.version ?? 0}`}
      {...props}
    />
  );
}
function Editor({ status, options, nameMax, ...shell }: Props) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const [locale, setLocale] = useState(i18n.locale);
  const [replacement, setReplacement] = useState('');
  const [deleting, setDeleting] = useState(false);
  const form = useForm({
    version: status?.version ?? null,
    position: status?.position ?? 0,
    default_in_stock: status?.default_in_stock ?? false,
    default_out_of_stock: status?.default_out_of_stock ?? false,
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        status?.translations?.[l.code] ?? { name: '' },
      ]),
    ),
  });
  useDirtyForm(form);
  const errors = form.errors as Record<string, string>;
  const needsReplacement =
    !!status &&
    (status.usage > 0 ||
      status.default_in_stock ||
      status.default_out_of_stock);
  return (
    <AdminLayout
      title={t(status ? 'availability.edit' : 'availability.create')}
      {...shell}
    >
      <EditorHeader
        heading={t(status ? 'availability.edit' : 'availability.create')}
        iconKey="inventory"
        backHref="/admin/availability-statuses"
        backLabel={t('availability.title')}
        actions={
          <>
            <EditorActionButton
              disabled={form.processing}
              form="admin-availabilitystatuses-form-0"
            >
              {t(form.processing ? 'common.wait' : 'common.save')}
            </EditorActionButton>
          </>
        }
      />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (status) form.put(`/admin/availability-statuses/${status.id}`);
          else form.post('/admin/availability-statuses');
        }}
        id="admin-availabilitystatuses-form-0"
      >
        <FormErrors errors={errors} />
        <section className="mt-4">
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
              id={`availability-name-${locale}`}
              label={t('common.name')}
              requiredIndicator
              maxLength={nameMax}
              value={form.data.translations[locale]?.name ?? ''}
              error={errors[`translations.${locale}.name`]}
              onChange={(e) =>
                form.setData('translations', {
                  ...form.data.translations,
                  [locale]: { name: e.target.value },
                })
              }
            />
            <FormField
              id="availability-position"
              label={t('availability.position')}
              type="number"
              min={0}
              max={2147483647}
              value={form.data.position}
              error={errors.position}
              onChange={(e) => form.setData('position', Number(e.target.value))}
            />
            <p className="text-xs leading-5 text-slate-500">
              {t('availability.default_help')}
            </p>
            {(['default_in_stock', 'default_out_of_stock'] as const).map(
              (field) => (
                <label className="flex items-start gap-3 text-sm" key={field}>
                  <input
                    type="checkbox"
                    className="mt-0.5 size-4"
                    checked={form.data[field]}
                    disabled={status?.[field] || form.processing}
                    onChange={(e) => form.setData(field, e.target.checked)}
                  />
                  <span>{t(`availability.${field}`)}</span>
                </label>
              ),
            )}
          </UnifiedCard>
        </section>
      </form>
      {status && (
        <section className="mt-5">
          <AdminCard
            title={t('availability.delete')}
            iconKey="delete"
            bodyClassName="p-5 space-y-4"
          >
            <p className="text-sm text-slate-500">
              {t('availability.delete_help', { count: status.usage })}
            </p>
            <label
              htmlFor="availability-replacement"
              className="block text-sm font-medium"
            >
              {t('availability.replacement')}
            </label>
            <Select
              id="availability-replacement"
              value={replacement}
              onChange={setReplacement}
              options={[
                {
                  value: '',
                  label: t(
                    needsReplacement
                      ? 'availability.choose_replacement'
                      : 'availability.no_replacement',
                  ),
                },
                ...options
                  .filter((s) => s.id !== status.id)
                  .map((s) => ({ value: s.id, label: s.name })),
              ]}
            />
            {errors.replacement && (
              <p role="alert" className="text-sm text-red-700">
                {errors.replacement}
              </p>
            )}
            <button
              type="button"
              className="button-secondary"
              disabled={
                form.processing ||
                deleting ||
                (needsReplacement && !replacement)
              }
              onClick={() => {
                if (window.confirm(t('availability.delete_confirm')))
                  router.delete(`/admin/availability-statuses/${status.id}`, {
                    onStart: () => setDeleting(true),
                    onFinish: () => setDeleting(false),
                    onError: (messages) =>
                      form.setError(messages as typeof form.errors),
                    data: {
                      version: status.version,
                      replacement: replacement || null,
                    },
                  });
              }}
            >
              {t('common.delete')}
            </button>
          </AdminCard>
        </section>
      )}
    </AdminLayout>
  );
}
