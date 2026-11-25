import UnifiedCard from '../../../components/admin/AdminCard';
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
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';

type StatusEditor = {
  id: string;
  version: number;
  code: string;
  color: string;
  stock_action: string;
  is_active: boolean;
  is_initial: boolean;
  is_final: boolean;
  sends_invoice: boolean;
  customer_can_cancel: boolean;
  is_system: boolean;
  orders: number;
  translations: Record<string, { name: string; description: string }>;
};

export default function OrderStatusForm({
  status,
  stockActions,
  defaultLocale,
  ...shell
}: AdminShell & {
  status: StatusEditor | null;
  stockActions: string[];
  defaultLocale: string;
}) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const [locale, setLocale] = useState(defaultLocale);
  const form = useForm({
    version: status?.version ?? 1,
    code: status?.code ?? '',
    color: status?.color ?? '#64748b',
    stock_action: status?.stock_action ?? 'none',
    is_active: status?.is_active ?? true,
    is_initial: status?.is_initial ?? false,
    is_final: status?.is_final ?? false,
    sends_invoice: status?.sends_invoice ?? false,
    customer_can_cancel: status?.customer_can_cancel ?? false,
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        status?.translations[l.code] ?? { name: '', description: '' },
      ]),
    ) as Record<string, { name: string; description: string }>,
  });
  const errors = form.errors as Record<string, string>;
  const text = form.data.translations[locale] ?? { name: '', description: '' };
  const title = status
    ? status.translations[defaultLocale]?.name || status.code
    : t('orders.status_create');
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="orders"
        backHref="/admin/order-statuses"
        backLabel={t('orders.statuses_back')}
        actions={
          <>
            <EditorActionButton
              disabled={form.processing}
              form="admin-orderstatuses-form-0"
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
          if (status) form.put('/admin/order-statuses/' + status.id);
          else form.post('/admin/order-statuses');
        }}
        id="admin-orderstatuses-form-0"
      >
        <FormErrors errors={errors} />
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
              onChange={(e) =>
                form.setData('translations', {
                  ...form.data.translations,
                  [locale]: { ...text, name: e.target.value },
                })
              }
            />
            <label className="block text-sm font-medium" htmlFor="description">
              {t('orders.status_description')}
            </label>
            <textarea
              id="description"
              className="field min-h-20"
              maxLength={500}
              value={text.description}
              onChange={(e) =>
                form.setData('translations', {
                  ...form.data.translations,
                  [locale]: { ...text, description: e.target.value },
                })
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
            <FormField
              id="code"
              label={t('orders.status_code')}
              requiredIndicator={!status}
              hint={t('orders.status_code_hint')}
              maxLength={40}
              disabled={!!status}
              value={form.data.code}
              error={errors.code}
              onChange={(e) => form.setData('code', e.target.value)}
            />
            <div className="space-y-2">
              <label className="block text-sm font-medium" htmlFor="color">
                {t('orders.status_color')}
              </label>
              <div className="flex items-center gap-3">
                <input
                  id="color"
                  type="color"
                  className="h-10 w-14 rounded border border-slate-200"
                  value={form.data.color}
                  onChange={(e) => form.setData('color', e.target.value)}
                />
                <span className="font-mono text-sm">{form.data.color}</span>
              </div>
              {errors.color && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.color}
                </p>
              )}
            </div>
            <label className="block text-sm font-medium">
              {t('orders.stock_action')}
              <Select
                className="mt-2"
                aria-label={t('orders.stock_action')}
                value={form.data.stock_action}
                onChange={(value) => form.setData('stock_action', value)}
                options={stockActions.map((a) => ({
                  value: a,
                  label: t(`orders.stock_${a}`),
                }))}
              />
              <span className="mt-1 block text-xs font-normal text-slate-500">
                {t('orders.stock_action_hint')}
              </span>
            </label>
            <div className="space-y-3">
              {(
                [
                  ['is_active', 'orders.active', 'orders.status_active_hint'],
                  [
                    'is_initial',
                    'orders.status_initial',
                    'orders.status_initial_hint',
                  ],
                  [
                    'is_final',
                    'orders.status_final',
                    'orders.status_final_hint',
                  ],
                  [
                    'sends_invoice',
                    'orders.status_sends_invoice',
                    'orders.status_sends_invoice_hint',
                  ],
                  [
                    'customer_can_cancel',
                    'orders.status_customer_can_cancel',
                    'orders.status_customer_can_cancel_hint',
                  ],
                ] as const
              ).map(([field, label, hint]) => (
                <label key={field} className="flex items-start gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="mt-0.5"
                    checked={form.data[field]}
                    disabled={
                      (field === 'is_initial' && !!status?.is_initial) ||
                      (field === 'is_active' && form.data.is_initial)
                    }
                    onChange={(e) => form.setData(field, e.target.checked)}
                  />
                  <span>
                    <span className="font-medium">{t(label)}</span>
                    <span className="block text-xs text-slate-500">
                      {t(hint)}
                    </span>
                  </span>
                </label>
              ))}
            </div>
          </UnifiedCard>
        </section>
        <p className="text-xs text-slate-500">
          {t('orders.status_email_note')}
        </p>
      </form>
      {status &&
        !status.is_system &&
        !status.is_initial &&
        status.orders === 0 && (
          <section className="mt-8 space-y-2 border-t border-slate-200 pt-5">
            <button
              type="button"
              className="button-secondary text-red-700"
              onClick={() => {
                if (
                  window.confirm(
                    t('orders.status_delete_confirm', {
                      name:
                        status.translations[defaultLocale]?.name || status.code,
                    }),
                  )
                )
                  router.delete('/admin/order-statuses/' + status.id, {
                    data: { version: status.version },
                  });
              }}
            >
              {t('orders.status_delete')}
            </button>
            <p className="text-xs text-slate-500">
              {t('orders.status_delete_hint')}
            </p>
          </section>
        )}
    </AdminLayout>
  );
}
