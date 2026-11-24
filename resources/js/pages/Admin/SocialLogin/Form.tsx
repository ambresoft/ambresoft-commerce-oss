import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import SettingsFields, {
  fieldValues,
} from '../../../components/admin/SettingsFields';
import FormField from '../../../components/auth/FormField';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type {
  SocialLoginCatalogEntry,
  SocialLoginEditor,
} from '../../../types/socialLogin';

type Tab = 'general' | 'callback';

export default function SocialLoginForm({
  provider,
  catalog,
  ...shell
}: AdminShell & {
  provider: SocialLoginEditor | null;
  catalog: SocialLoginCatalogEntry[];
}) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const canManage = !!capabilities['social-login.manage'];
  const [tab, setTab] = useState<Tab>('general');
  const [copied, setCopied] = useState(false);
  const form = useForm({
    version: provider?.version,
    provider: provider?.provider ?? catalog[0]?.id ?? '',
    name: provider?.name ?? '',
    is_active: provider?.active ?? true,
    settings: fieldValues(
      provider?.fields ?? catalog[0]?.fields ?? ([] as never[]),
    ),
    clear: {} as Record<string, boolean>,
  });
  useDirtyForm(form);
  const errors = form.errors as Record<string, string>;
  const entry = catalog.find((c) => c.id === form.data.provider) ?? catalog[0];
  // Stored values only exist for an existing provider; a new one starts from the catalog's empty fields.
  const fields = provider?.fields ?? entry?.fields ?? [];
  const title = provider ? t('social_login.edit') : t('social_login.create');
  const pickProvider = (id: string) => {
    const next = catalog.find((c) => c.id === id);
    form.setData({
      ...form.data,
      provider: id,
      settings: fieldValues(next?.fields ?? []),
      clear: {},
    });
  };
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(entry?.callback_url ?? '');
      setCopied(true);
    } catch {
      // The address stays selectable in the field.
    }
  };
  const remove = () => {
    if (!provider) return;
    if (
      !window.confirm(t('social_login.delete_confirm', { name: provider.name }))
    )
      return;
    router.delete('/admin/social-login/' + provider.id, {
      data: { version: provider.version },
      preserveScroll: true,
    });
  };

  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        status={
          <div>
            <EditorStatusSelect
              aria-label={t('common.status')}
              value={form.data.is_active ? 'active' : 'disabled'}
              disabled={!canManage || form.processing}
              invalid={!!errors.is_active}
              onChange={(value) =>
                form.setData('is_active', value === 'active')
              }
              options={[
                { value: 'active', label: t('social_login.active') },
                { value: 'disabled', label: t('social_login.disabled') },
              ]}
            />
            {errors.is_active && (
              <p role="alert" className="text-xs text-red-700">
                {errors.is_active}
              </p>
            )}
          </div>
        }
        heading={title}
        iconKey="security"
        backHref="/admin/social-login"
        backLabel={t('social_login.title')}
        actions={
          canManage && (
            <>
              <div className="flex gap-3">
                <EditorActionButton
                  disabled={form.processing}
                  form="admin-sociallogin-form-0"
                >
                  {t(form.processing ? 'common.wait' : 'common.save')}
                </EditorActionButton>
                <Link className="button-secondary" href="/admin/social-login">
                  {t('common.cancel')}
                </Link>
              </div>
            </>
          )
        }
        menu={
          provider &&
          canManage && (
            <button
              type="button"
              className="button-secondary text-red-700 hover:bg-red-50"
              onClick={remove}
            >
              <Icon name="trash" />
              {t('common.delete')}
            </button>
          )
        }
      />
      <AdminTabs label={title} className="mb-4">
        {(['general', 'callback'] as const).map((item) => (
          <AdminTab
            key={item}
            type="button"
            role="tab"
            aria-selected={tab === item}
            onClick={() => setTab(item)}
            iconKey={item}
          >
            {t(
              item === 'general'
                ? 'social_login.tab_general'
                : 'social_login.tab_callback',
            )}
          </AdminTab>
        ))}
      </AdminTabs>
      <form
        className="w-full space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          if (provider === null) form.post('/admin/social-login');
          else form.put('/admin/social-login/' + provider.id);
        }}
        id="admin-sociallogin-form-0"
      >
        <FormErrors
          errors={Object.fromEntries(
            Object.entries(errors).filter(([key]) =>
              ['version', 'provider', 'is_active'].includes(key),
            ),
          )}
        />
        {/* Both panels stay mounted so edits on one tab survive a visit to the other. */}
        <section role="tabpanel" hidden={tab !== 'general'}>
          <UnifiedCard
            title={t('social_login.tab_general')}
            iconKey="general"
            bodyClassName="p-5 space-y-5"
          >
            <div className="space-y-2 sm:max-w-xs">
              <label
                htmlFor="social-provider"
                className="block text-sm font-medium"
              >
                {t('social_login.provider')}
              </label>
              <Select
                id="social-provider"
                value={form.data.provider}
                disabled={provider !== null || !canManage}
                onChange={pickProvider}
                options={catalog.map((c) => ({ value: c.id, label: c.name }))}
              />
            </div>
            <FormField
              id="social-name"
              label={t('social_login.name')}
              hint={t('social_login.name_hint')}
              requiredIndicator
              maxLength={100}
              value={form.data.name}
              error={errors.name}
              onChange={(e) => form.setData('name', e.target.value)}
            />
            <div className="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
              <p>{t('social_login.credentials_tip')}</p>
              {entry?.console_url && (
                <a
                  className="font-medium underline"
                  href={entry.console_url}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {t('social_login.open_console')}
                </a>
              )}
            </div>
            <SettingsFields
              fields={fields}
              values={form.data.settings}
              clear={form.data.clear}
              errors={errors}
              prefix="settings."
              idPrefix="social-"
              onChange={(key, value) =>
                form.setData('settings', {
                  ...form.data.settings,
                  [key]: value,
                })
              }
              onClear={(key, value) =>
                form.setData('clear', { ...form.data.clear, [key]: value })
              }
            />
          </UnifiedCard>
        </section>
        <section role="tabpanel" hidden={tab !== 'callback'}>
          <UnifiedCard
            title={t('social_login.tab_callback')}
            iconKey="general"
            bodyClassName="p-5 space-y-4"
          >
            <p className="text-sm text-slate-600">
              {t('social_login.callback_intro', {
                provider: entry?.name ?? '',
              })}
            </p>
            <div className="space-y-2">
              <label
                htmlFor="social-callback"
                className="block text-sm font-medium"
              >
                {t('social_login.callback_url')}
              </label>
              <div className="flex gap-2">
                <input
                  id="social-callback"
                  readOnly
                  className="field font-mono"
                  value={entry?.callback_url ?? ''}
                  onFocus={(e) => e.currentTarget.select()}
                />
                <button
                  type="button"
                  className="button-secondary"
                  onClick={copy}
                >
                  {t(copied ? 'social_login.copied' : 'social_login.copy')}
                </button>
              </div>
            </div>
            <p className="text-xs text-slate-500">
              {t('social_login.callback_note')}
            </p>
          </UnifiedCard>
        </section>
      </form>
    </AdminLayout>
  );
}
