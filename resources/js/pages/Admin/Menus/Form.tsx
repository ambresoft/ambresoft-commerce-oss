import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormField from '../../../components/auth/FormField';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import RequiredMark from '../../../components/admin/RequiredMark';
import MenuEditorTabs from '../../../components/admin/MenuEditorTabs';
import { useTranslation } from '../../../lib/useTranslation';
import { useUrlTab } from '../../../lib/useUrlTab';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { Menu } from '../../../types/menus';
const tabs = ['general', 'settings'] as const;

export default function MenuForm({
  menu,
  defaultLocale,
  ...shell
}: AdminShell & { menu: Menu | null; defaultLocale: string }) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const [locale, setLocale] = useState(defaultLocale);
  const [tab, setTab] = useUrlTab(tabs);
  const allowed = !!capabilities['menus.manage'];
  const form = useForm({
    version: menu?.version ?? 1,
    status: menu?.status ?? 'active',
    placement: menu?.placement ?? 'header',
    source: menu?.source ?? 'manual',
    style: menu?.style ?? 'standard',
    confirmed: false,
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        { name: menu?.translations[l.code]?.name ?? '' },
      ]),
    ),
  });
  useDirtyForm(form);
  const remove = () => {
    if (
      menu &&
      window.confirm(t('menus.delete_menu_confirm', { count: menu.item_count }))
    )
      router.delete('/admin/menus/' + menu.id, {
        data: { version: menu.version, confirmed: true },
      });
  };
  const errors = form.errors as Record<string, string>;
  return (
    <AdminLayout title={t(menu ? 'menus.edit' : 'menus.create')} {...shell}>
      <EditorHeader
        status={
          <div>
            <EditorStatusSelect
              aria-label={t('common.status')}
              aria-required
              disabled={!allowed || form.processing}
              value={form.data.status}
              onChange={(value) => form.setData('status', value)}
              options={['active', 'disabled'].map((value) => ({
                value,
                label: t('menus.' + value),
              }))}
            />
            {form.errors.status && (
              <p role="alert" className="text-xs text-red-700">
                {form.errors.status}
              </p>
            )}
          </div>
        }
        backHref="/admin/menus"
        backLabel={t('menus.back')}
        heading={t(menu ? 'menus.edit' : 'menus.create')}
        iconKey="features"
        actions={
          <>
            {allowed && (
              <EditorActionButton
                disabled={form.processing}
                form="admin-menus-form-0"
              >
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            )}
          </>
        }
        menu={
          menu &&
          allowed && (
            <button
              type="button"
              className="button-secondary text-red-700"
              onClick={remove}
            >
              <Icon name="trash" />
              {t('common.delete')}
            </button>
          )
        }
      />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          form.post(menu ? '/admin/menus/' + menu.id : '/admin/menus');
        }}
        id="admin-menus-form-0"
      >
        <MenuEditorTabs
          tabs={tabs}
          active={tab}
          onChange={(value) => setTab(value as typeof tab)}
          errors={form.errors}

          language={
            <ContentLanguageSelector
              languages={i18n.locales}
              value={locale}
              onChange={setLocale}
              errors={errors}
            />
          }
        />
        <p className="mt-4 text-xs text-slate-500">
          {t('common.required_fields')}
        </p>
        <FormErrors errors={form.errors} />
        {tab === 'general' && <div className="mt-4"></div>}
        <fieldset disabled={!allowed} className="mt-4 w-full">
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
            bodyClassName="p-5 "
          >
            <section
              role="tabpanel"
              id={'menu-panel-' + tab}
              aria-labelledby={'menu-tab-' + tab}
              className="space-y-6"
            >
              {tab === 'general' ? (
                <>
                  <FormField
                    id={'menu-name-' + locale}
                    label={t('common.name') + ' · ' + locale}
                    requiredIndicator={locale === defaultLocale}
                    value={form.data.translations[locale].name}
                    maxLength={200}
                    error={errors['translations.' + locale + '.name']}
                    onChange={(e) =>
                      form.setData('translations', {
                        ...form.data.translations,
                        [locale]: { name: e.target.value },
                      })
                    }
                  />
                </>
              ) : (
                <>
                  <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    {(['placement', 'source', 'style'] as const).map(
                      (field) => (
                        <div key={field} className="space-y-2">
                          <label
                            htmlFor={'menu-' + field}
                            className="block text-sm font-medium"
                          >
                            {t('menus.' + field)}
                            <RequiredMark />
                          </label>
                          <Select
                            id={'menu-' + field}
                            aria-required
                            disabled={!allowed}
                            value={form.data[field]}
                            onChange={(value) => {
                              form.setData(field, value);
                              // Link groups are always manual lists.
                              if (field === 'style' && value === 'link_groups')
                                form.setData('source', 'manual');
                            }}
                            options={{
                              placement: ['header', 'footer', 'other'],
                              source: ['manual', 'categories'],
                              style: ['standard', 'link_groups'],
                            }[field].map((value) => ({
                              value,
                              label: t('menus.' + value),
                            }))}
                          />
                        </div>
                      ),
                    )}
                  </div>
                  <p className="text-sm text-slate-500">
                    {t('menus.source_help')}
                  </p>
                  {form.data.style === 'link_groups' && (
                    <p className="text-sm text-slate-500">
                      {t('menus.style_link_groups_help')}
                    </p>
                  )}
                  {menu &&
                    menu.item_count > 0 &&
                    form.data.source !== menu.source && (
                      <label className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        <input
                          type="checkbox"
                          checked={form.data.confirmed}
                          onChange={(e) =>
                            form.setData('confirmed', e.target.checked)
                          }
                        />
                        {t('menus.mode_confirm', { count: menu.item_count })}
                      </label>
                    )}
                </>
              )}
            </section>
          </UnifiedCard>
        </fieldset>
      </form>
    </AdminLayout>
  );
}
