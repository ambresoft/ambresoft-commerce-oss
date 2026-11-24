import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
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
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
import { useTranslation } from '../../../lib/useTranslation';
import { useUrlTab } from '../../../lib/useUrlTab';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { Menu, MenuItem, MenuText } from '../../../types/menus';
const tabs = ['general', 'appearance'] as const;
type Option = { id: string; name: string; status?: string };
export default function ItemForm({
  menu,
  item,
  initialParent,
  parents,
  categories,
  defaultLocale,
  ...shell
}: AdminShell & {
  menu: Menu;
  item: MenuItem | null;
  initialParent: string | null;
  parents: Option[];
  categories: Option[];
  defaultLocale: string;
}) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const [locale, setLocale] = useState(defaultLocale);
  const [tab, setTab] = useUrlTab(tabs);
  const [parentSearch, setParentSearch] = useState('');
  const [categorySearch, setCategorySearch] = useState('');
  const allowed = !!capabilities['menus.manage'];
  const base = '/admin/menus/' + menu.id;
  const form = useForm({
    version: menu.version,
    parent_id: item?.parent_id ?? initialParent ?? '',
    status: item?.status ?? 'active',
    position: item?.position ?? 10,
    kind: item?.kind ?? 'url',
    url: item?.url ?? '',
    category_id: item?.category_id ?? '',
    generate_children: item?.generate_children ?? false,
    new_window: item?.new_window ?? false,
    image: null as File | null,
    remove_image: false,
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        {
          name: item?.translations[l.code]?.name ?? '',
          description: item?.translations[l.code]?.description ?? '',
          image_alt: item?.translations[l.code]?.image_alt ?? '',
        },
      ]),
    ),
  });
  useDirtyForm(form);
  const text = form.data.translations[locale];
  const setText = (field: keyof MenuText, value: string) =>
    form.setData('translations', {
      ...form.data.translations,
      [locale]: { ...text, [field]: value },
    });
  const find = (field: 'parent' | 'category') =>
    router.get(
      base + '/items/' + (item ? item.id + '/edit' : 'create'),
      {
        parent: initialParent ?? undefined,
        parent_search: parentSearch,
        category_search: categorySearch,
      },
      {
        only: [field === 'parent' ? 'parents' : 'categories'],
        preserveState: true,
        preserveScroll: true,
      },
    );
  const errors = form.errors as Record<string, string>;
  return (
    <AdminLayout
      title={t(item ? 'menus.edit_item' : 'menus.add_item')}
      {...shell}
    >
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
        backHref={'/admin/menus/' + menu.id + '/items'}
        backLabel={t('menus.back')}
        heading={t(item ? 'menus.edit_item' : 'menus.add_item')}
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
      />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          form.post(base + '/items' + (item ? '/' + item.id : ''), {
            forceFormData: true,
          });
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
        <div className="mt-4"></div>
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
                    id={'item-name-' + locale}
                    label={t('common.name') + ' · ' + locale}
                    requiredIndicator={locale === defaultLocale}
                    value={text.name}
                    maxLength={200}
                    error={errors['translations.' + locale + '.name']}
                    onChange={(e) => setText('name', e.target.value)}
                  />
                  <div className="grid gap-6 sm:grid-cols-2">
                    <div className="space-y-3">
                      <div className="flex items-end gap-3">
                        <div className="grow">
                          <FormField
                            id="parent-search"
                            label={t('menus.find_parent')}
                            value={parentSearch}
                            maxLength={100}
                            onChange={(e) => setParentSearch(e.target.value)}
                          />
                        </div>
                        <button
                          type="button"
                          className="button-secondary"
                          onClick={() => find('parent')}
                        >
                          {t('common.search')}
                        </button>
                      </div>
                      <label
                        htmlFor="item-parent"
                        className="block text-sm font-medium"
                      >
                        {t('menus.parent')}
                      </label>
                      <Select
                        id="item-parent"
                        disabled={!allowed}
                        value={form.data.parent_id}
                        onChange={(value) => form.setData('parent_id', value)}
                        search={{
                          label: t('common.search_options'),
                          emptyMessage: t('common.no_options'),
                        }}
                        options={[
                          { value: '', label: t('menus.root') },
                          ...parents.map((p) => ({
                            value: p.id,
                            label: p.name + ' (#' + p.id + ')',
                          })),
                        ]}
                      />
                      {form.errors.parent_id && (
                        <p role="alert" className="text-sm text-red-700">
                          {form.errors.parent_id}
                        </p>
                      )}
                    </div>
                    <FormField
                      id="item-position"
                      type="number"
                      min={0}
                      max={2147483647}
                      step={1}
                      label={t('menus.position')}
                      requiredIndicator
                      value={form.data.position}
                      error={form.errors.position}
                      onChange={(e) =>
                        form.setData('position', Number(e.target.value))
                      }
                    />
                  </div>
                  <div className="grid gap-6 sm:grid-cols-2">
                    {(['kind'] as const).map((field) => (
                      <div className="space-y-2" key={field}>
                        <label
                          htmlFor={'item-' + field}
                          className="block text-sm font-medium"
                        >
                          {t('menus.target')}
                          <RequiredMark />
                        </label>
                        <Select
                          id={'item-' + field}
                          aria-required
                          disabled={!allowed}
                          value={form.data[field]}
                          onChange={(value) => {
                            form.setData(field, value);
                            if (field === 'kind' && value !== 'category')
                              form.setData('generate_children', false);
                          }}
                          options={(menu.style === 'link_groups'
                            ? ['url', 'heading']
                            : ['url', 'category', 'heading']
                          ).map((value) => ({
                            value,
                            label: t('menus.' + value),
                          }))}
                        />
                      </div>
                    ))}
                  </div>
                  {form.data.kind === 'url' && (
                    <FormField
                      id="item-url"
                      label={t('menus.url')}
                      requiredIndicator
                      value={form.data.url}
                      maxLength={2048}
                      hint={t('menus.url_help')}
                      error={form.errors.url}
                      onChange={(e) => form.setData('url', e.target.value)}
                    />
                  )}
                  {form.data.kind === 'category' && (
                    <div className="space-y-4">
                      <div className="flex items-end gap-3">
                        <div className="grow">
                          <FormField
                            id="category-search"
                            label={t('menus.find_category')}
                            value={categorySearch}
                            maxLength={100}
                            onChange={(e) => setCategorySearch(e.target.value)}
                          />
                        </div>
                        <button
                          type="button"
                          className="button-secondary"
                          onClick={() => find('category')}
                        >
                          {t('common.search')}
                        </button>
                      </div>
                      <label
                        htmlFor="item-category"
                        className="block text-sm font-medium"
                      >
                        {t('menus.category')}
                        <RequiredMark />
                      </label>
                      <Select
                        id="item-category"
                        aria-required
                        disabled={!allowed}
                        value={form.data.category_id}
                        onChange={(value) => form.setData('category_id', value)}
                        search={{
                          label: t('common.search_options'),
                          emptyMessage: t('common.no_options'),
                        }}
                        options={[
                          { value: '', label: '—' },
                          ...categories.map((c) => ({
                            value: c.id,
                            label:
                              c.name +
                              ' (#' +
                              c.id +
                              ')' +
                              (c.status === 'disabled'
                                ? ' · ' + t('menus.disabled')
                                : ''),
                          })),
                        ]}
                      />
                      {form.errors.category_id && (
                        <p role="alert" className="text-sm text-red-700">
                          {form.errors.category_id}
                        </p>
                      )}
                      <label className="flex items-center gap-3 text-sm">
                        <input
                          type="checkbox"
                          checked={form.data.generate_children}
                          onChange={(e) =>
                            form.setData('generate_children', e.target.checked)
                          }
                        />
                        {t('menus.generate_children')}
                      </label>
                      <p className="text-xs text-slate-500">
                        {t('menus.generated_help')}
                      </p>
                    </div>
                  )}
                  {form.data.kind !== 'heading' && (
                    <label className="flex items-center gap-3 text-sm">
                      <input
                        type="checkbox"
                        checked={form.data.new_window}
                        onChange={(e) =>
                          form.setData('new_window', e.target.checked)
                        }
                      />
                      {t('menus.new_window')}
                    </label>
                  )}
                </>
              ) : (
                <>
                  <FormField
                    id={'item-description-' + locale}
                    label={t('menus.description')}
                    value={text.description}
                    maxLength={500}
                    error={errors['translations.' + locale + '.description']}
                    onChange={(e) => setText('description', e.target.value)}
                  />
                  <FormField
                    id={'item-alt-' + locale}
                    label={t('menus.image_alt')}
                    value={text.image_alt}
                    maxLength={200}
                    error={errors['translations.' + locale + '.image_alt']}
                    onChange={(e) => setText('image_alt', e.target.value)}
                  />
                  <SingleImageUploader
                    title={t('menus.image')}
                    help={t('menus.image_help')}
                    current={
                      item?.imageUrl
                        ? { url: item.imageUrl, meta: item.imageMeta }
                        : null
                    }
                    alt={text.image_alt || text.name}
                    file={form.data.image}
                    onFile={(file) => form.setData('image', file)}
                    removed={form.data.remove_image}
                    onRemoved={(value) => form.setData('remove_image', value)}
                    disabled={!allowed}
                    error={form.errors.image}
                  />
                </>
              )}
            </section>
          </UnifiedCard>
        </fieldset>
      </form>
    </AdminLayout>
  );
}
