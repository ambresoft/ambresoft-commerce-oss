import AdminTabs from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import IconButton from '../../../components/admin/list/IconButton';
import FormField from '../../../components/auth/FormField';
import { useTranslation } from '../../../lib/useTranslation';
import type {
  PageOption,
  PageSettings,
  PageText,
} from '../../../types/layouts';

const blank: PageText = {
  name: '',
  page_title: '',
  meta_description: '',
  meta_keywords: '',
};

/**
 * Page settings of one layout (name, page title, meta description and keywords, custom head HTML) or, without a
 * layout key, the form that adds a layout and links it to a static page.
 */
export default function LayoutSettingsDialog({
  layoutKey,
  settings,
  pageOptions,
  languages,
  defaultLocale,
  manage,
  onClose,
}: {
  /** Absent when a new layout is being added. */
  layoutKey?: string;
  settings: PageSettings | null;
  pageOptions: PageOption[];
  languages: { code: string; name: string }[];
  defaultLocale: string;
  manage: boolean;
  onClose: () => void;
}) {
  const t = useTranslation();
  const dialog = useRef<HTMLDialogElement>(null);
  const creating = layoutKey === undefined;
  const custom = creating || !!settings?.custom;
  const [locale, setLocale] = useState(defaultLocale);
  const [deleting, setDeleting] = useState(false);
  const form = useForm({
    version: settings?.version ?? 1,
    translations: Object.fromEntries(
      languages.map((l) => [l.code, settings?.translations[l.code] ?? blank]),
    ) as Record<string, PageText>,
    custom_html: settings?.custom_html ?? '',
    indexable: settings?.indexable ?? true,
    page_id: settings?.page_id ?? '',
    position: settings?.position ?? 0,
    copy_meta_keywords: false,
    copy_custom_html: false,
  });
  useEffect(() => {
    dialog.current?.showModal();
  }, []);
  const text = form.data.translations[locale] ?? blank;
  const setText = (change: Partial<PageText>) =>
    form.setData('translations', {
      ...form.data.translations,
      [locale]: { ...text, ...change },
    });
  const options = [
    { value: '', label: t('layouts.page_none') },
    ...pageOptions.map((p) => ({
      value: p.id,
      label: `${'— '.repeat(p.depth)}${p.name}`,
      disabled: p.layout !== null && p.layout !== layoutKey,
    })),
  ];
  const submit = () => {
    form.transform((data) => ({ ...data, page_id: data.page_id || null }));
    const done = { preserveScroll: true, onSuccess: () => onClose() };
    if (creating) form.post('/admin/layouts', done);
    else form.put(`/admin/layouts/${layoutKey}/settings`, done);
  };
  return (
    <dialog
      ref={dialog}
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      aria-labelledby="layout-settings-title"
      className="m-auto max-h-[90vh] w-[min(52rem,95vw)] overflow-y-auto rounded-xl border border-slate-200 bg-white p-0 text-slate-900 shadow-xl backdrop:bg-black/50"
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          submit();
        }}
      >
        <div className="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-slate-200 bg-white p-5">
          <h2 id="layout-settings-title" className="text-lg font-semibold">
            {t(creating ? 'layouts.new_layout' : 'layouts.page_settings')}
          </h2>
          <IconButton icon="x" label={t('common.cancel')} onClick={onClose} />
        </div>
        <div className="space-y-5 p-5">
          <FormErrors errors={form.errors} />
          <AdminTabs
            language={
              <ContentLanguageSelector
                languages={languages}
                value={locale}
                onChange={setLocale}
              />
            }
          />
          {custom && (
            <>
              <div className="space-y-2 text-sm">
                <label htmlFor="layout-page">{t('layouts.linked_page')}</label>
                <Select
                  id="layout-page"
                  value={form.data.page_id}
                  options={options}
                  disabled={!manage}
                  onChange={(value) => form.setData('page_id', value)}
                  search={{
                    label: t('layouts.find_page'),
                    emptyMessage: t('layouts.no_pages'),
                  }}
                />
                <p className="text-xs text-slate-500">
                  {t('layouts.linked_page_help')}
                </p>
              </div>
              <FormField
                id="layout-name"
                label={t('layouts.layout_name')}
                required={locale === defaultLocale}
                maxLength={100}
                disabled={!manage}
                value={text.name}
                onChange={(e) => setText({ name: e.target.value })}
              />
            </>
          )}
          <FormField
            id="layout-page-title"
            label={t('layouts.page_title')}
            hint={t(
              [
                'products',
                'category_landing',
                'category',
                'catalog_landing',
                'new_products',
                'bestsellers',
                'offers',
                'blog',
                'blog_post',
              ].includes(layoutKey ?? '')
                ? 'layouts.page_title_suffix_help'
                : 'layouts.page_title_help',
            )}
            maxLength={200}
            disabled={!manage}
            value={text.page_title}
            onChange={(e) => setText({ page_title: e.target.value })}
          />
          <div className="space-y-2 text-sm">
            <label htmlFor="layout-meta-description">
              {t('layouts.meta_description')}
            </label>
            <textarea
              id="layout-meta-description"
              className="min-h-24 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
              rows={3}
              maxLength={500}
              disabled={!manage}
              value={text.meta_description}
              onChange={(e) => setText({ meta_description: e.target.value })}
            />
          </div>
          <div className="space-y-2 text-sm">
            <label htmlFor="layout-meta-keywords">
              {t('layouts.meta_keywords')}
            </label>
            <textarea
              id="layout-meta-keywords"
              className="min-h-24 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
              rows={3}
              maxLength={500}
              disabled={!manage}
              value={text.meta_keywords}
              onChange={(e) => setText({ meta_keywords: e.target.value })}
            />
            {!creating && manage && (
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={form.data.copy_meta_keywords}
                  onChange={(e) =>
                    form.setData('copy_meta_keywords', e.target.checked)
                  }
                />
                {t('layouts.copy_to_others')}
              </label>
            )}
          </div>
          <div className="space-y-2 text-sm">
            <label htmlFor="layout-custom-html">
              {t('layouts.custom_html')}
            </label>
            <textarea
              id="layout-custom-html"
              className="min-h-32 w-full rounded-md border border-slate-300 bg-white px-3 py-2 font-mono text-sm"
              rows={5}
              maxLength={20000}
              spellCheck={false}
              disabled={!manage}
              value={form.data.custom_html}
              onChange={(e) => form.setData('custom_html', e.target.value)}
            />
            {!creating && manage && (
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={form.data.copy_custom_html}
                  onChange={(e) =>
                    form.setData('copy_custom_html', e.target.checked)
                  }
                />
                {t('layouts.copy_to_others')}
              </label>
            )}
            <p className="text-xs text-slate-500">
              {t('layouts.custom_html_help')}
            </p>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.data.indexable}
              disabled={!manage}
              onChange={(e) => form.setData('indexable', e.target.checked)}
            />
            {t('layouts.indexable')}
          </label>
          <p className="-mt-3 text-xs text-slate-500">
            {t('layouts.indexable_help')}
          </p>
          {custom && (
            <FormField
              id="layout-position"
              label={t('layouts.position')}
              type="number"
              min={-99999}
              max={99999}
              disabled={!manage}
              value={String(form.data.position)}
              onChange={(e) =>
                form.setData('position', Number(e.target.value) || 0)
              }
            />
          )}
        </div>
        <div className="sticky bottom-0 flex items-center justify-between gap-4 border-t border-slate-200 bg-white p-5">
          {custom && !creating && manage ? (
            <button
              type="button"
              className="button-secondary"
              onClick={() => setDeleting(true)}
            >
              {t('layouts.delete_layout')}
            </button>
          ) : (
            <span />
          )}
          <div className="flex gap-3">
            <button
              type="button"
              className="button-secondary"
              onClick={onClose}
            >
              {t('common.cancel')}
            </button>
            {manage && (
              <button className="button-primary" disabled={form.processing}>
                {t(
                  creating ? 'layouts.create_layout' : 'layouts.save_settings',
                )}
              </button>
            )}
          </div>
        </div>
      </form>
      {deleting && (
        <ConfirmDeleteDialog
          title={t('layouts.delete_layout')}
          facts={[t('layouts.delete_layout_help')]}
          onCancel={() => setDeleting(false)}
          onConfirm={() =>
            router.delete(`/admin/layouts/${layoutKey}`, {
              data: { version: form.data.version },
              onSuccess: () => onClose(),
            })
          }
        />
      )}
    </dialog>
  );
}
