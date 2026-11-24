import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import MobileLinksEditor from './MobileLinksEditor';
import Switch from '../../../components/admin/Switch';
import DragGrip from '../../../components/admin/list/DragGrip';
import {
  lazy,
  Suspense,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { router } from '@inertiajs/react';
import IconButton from '../../../components/admin/list/IconButton';
import FormField from '../../../components/auth/FormField';
import Select from '../../../components/admin/Select';
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
import { useTranslation } from '../../../lib/useTranslation';
import TargetPicker from '../Promotions/TargetPicker';
import type {
  BlockDefinition,
  BlockField,
  Grid,
  LayoutOptions,
  Placement,
  BlockConfig,
} from '../../../types/layouts';
const RichText = lazy(() => import('../../../components/admin/RichText'));

export default function BlockDialog({
  placement,
  definition,
  grids,
  options,
  path,
  languages,
  defaultLocale,
  onApply,
  onClose,
  inherited = false,
  pageKind = 'homepage',
}: {
  placement: Placement;
  definition: BlockDefinition;
  grids: Grid[];
  options: LayoutOptions;
  path: string;
  languages: { code: string; name: string }[];
  defaultLocale: string;
  inherited?: boolean;
  /** The page being edited (homepage, products, wishlist, default); some settings belong to one page. */
  pageKind?: string;
  onApply: (value: Placement) => void;
  onClose: () => void;
}) {
  const t = useTranslation();
  // Settings the block was saved without (added to its type later) show their defaults, as the storefront applies them.
  const [draft, setDraft] = useState<Placement>(() => {
    const copy = structuredClone(placement);
    copy.block.settings = { ...definition.defaults, ...copy.block.settings };
    return copy;
  });
  const [tab, setTab] = useState<
    | 'general'
    | 'content'
    | 'image'
    | 'mobile_links'
    | 'product_options'
    | 'identity_options'
    | 'identity_options'
  >('general');
  const productSections =
    pageKind === 'products'
      ? (definition.fields.find((field) => field.key === 'section_order')
          ?.options ?? [])
      : [];
  const imageFields = definition.fields.filter((f) => f.tab === 'image');
  const [locale, setLocale] = useState(defaultLocale);
  const dialog = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    dialog.current?.showModal();
  }, []);
  const block = draft.block;
  const identityIcons: Record<string, string> =
    block.type === 'identity'
      ? {
          language: 'show_language_switcher',
          search: 'show_search',
          account: 'show_account',
          call: 'show_call_request',
          favorites: 'show_favorites',
          cart: 'show_cart',
        }
      : {};
  const fashion = block.settings.header_style === 'fashion';
  const single = block.template.endsWith('_single');
  const update = (change: Partial<typeof block>) =>
    setDraft({ ...draft, block: { ...block, ...change } });
  const translation: BlockConfig['translations'][string] = block.translations[
    locale
  ] ?? { title: '', html: '' };
  return (
    <dialog
      ref={dialog}
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      className="m-auto max-h-[90vh] w-[min(56rem,95vw)] overflow-y-auto rounded-xl border border-slate-200 bg-white p-0 text-slate-900 shadow-xl backdrop:bg-black/50"
      aria-labelledby="block-dialog-title"
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          onApply(draft);
        }}
      >
        <div className="sticky top-0 z-10 bg-white">
          <div className="flex items-center justify-between gap-4 border-b border-slate-200 p-5">
            <div>
              <h2 id="block-dialog-title" className="text-lg font-semibold">
                {t('layouts.configure_block')}
              </h2>
              <p className="text-sm text-slate-500">{t(definition.label)}</p>
            </div>
            <IconButton icon="x" label={t('common.cancel')} onClick={onClose} />
          </div>
          <AdminTabs
            sticky={false}
            className="mb-0! pr-5!"
            label={t('layouts.configure_block')}
            language={
              <ContentLanguageSelector
                languages={languages}
                value={locale}
                onChange={setLocale}
              />
            }
          >
            {(
              [
                'general',
                'content',
                ...(productSections.length > 0
                  ? (['product_options'] as const)
                  : []),
                ...(definition.fields.some(
                  (field) => field.tab === 'identity_options',
                )
                  ? (['identity_options'] as const)
                  : []),
                ...(block.type === 'identity' &&
                block.settings.header_style === 'fashion'
                  ? (['mobile_links'] as const)
                  : []),
                ...(imageFields.length > 0 ? (['image'] as const) : []),
              ] as const
            ).map((key) => (
              <AdminTab
                key={key}
                type="button"
                role="tab"
                aria-selected={tab === key}
                onClick={() => setTab(key)}
                iconKey={key}
              >
                {t(`layouts.${key}`)}
              </AdminTab>
            ))}
          </AdminTabs>
        </div>
        <div className="space-y-5 p-5">
          {tab === 'general' ? (
            <>
              <FormField
                id="block-name"
                label={t('layouts.block_name')}
                required
                value={block.name}
                maxLength={128}
                onChange={(e) => update({ name: e.target.value })}
              />
              <label className="block space-y-2">
                <span className="block text-sm font-medium text-slate-800">
                  {t('layouts.template')}
                </span>
                <Select
                  aria-label={t('layouts.template')}
                  value={block.template}
                  options={definition.templates.map((value) => ({
                    value,
                    label: t(
                      definition.template_labels?.[value] ??
                        `layouts.template_${value}`,
                    ),
                  }))}
                  onChange={(value) => update({ template: value })}
                />
              </label>
              <FormField
                id="block-css"
                label={t('layouts.css_class')}
                value={block.css_class}
                maxLength={128}
                pattern="[a-zA-Z0-9_ -]*"
                onChange={(e) => update({ css_class: e.target.value })}
              />
              {!inherited && definition.policy.move && (
                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="block space-y-2">
                    <span className="block text-sm font-medium text-slate-800">
                      {t('layouts.move_to')}
                    </span>
                    <Select
                      aria-label={t('layouts.move_to')}
                      value={draft.grid_id}
                      options={grids.map((g, n) => ({
                        value: g.id,
                        label: `${t(`layouts.region_${g.region}`)} · ${t('layouts.grid')} ${n + 1} · ${g.width}/16`,
                      }))}
                      onChange={(grid_id) => setDraft({ ...draft, grid_id })}
                    />
                  </label>
                  <FormField
                    id="block-position"
                    label={t('layouts.position')}
                    type="number"
                    min={1}
                    max={1000}
                    value={draft.position}
                    onChange={(e) =>
                      setDraft({ ...draft, position: Number(e.target.value) })
                    }
                  />
                </div>
              )}
              <fieldset className="space-y-3">
                <legend className="mb-2 text-sm font-medium text-slate-800">
                  {t('layouts.visibility')}
                </legend>
                <div className="flex flex-wrap gap-5">
                  {(inherited
                    ? (['enabled'] as const)
                    : (['enabled', 'mobile', 'tablet', 'desktop'] as const)
                  ).map((key) => (
                    <label
                      key={key}
                      className="flex items-center gap-2 text-sm"
                    >
                      <input
                        type="checkbox"
                        checked={draft[key]}
                        disabled={!definition.policy.disable}
                        onChange={(e) =>
                          setDraft({ ...draft, [key]: e.target.checked })
                        }
                      />
                      {t(`layouts.${key}`)}
                    </label>
                  ))}
                </div>
              </fieldset>
              <p className="text-sm text-slate-500">
                {t('layouts.placement_help')}
              </p>
            </>
          ) : tab === 'mobile_links' ? (
            <MobileLinksEditor
              languages={languages}
              locale={locale}
              onLocale={setLocale}
              value={translation.mobile_links ?? { title: '', items: [] }}
              enabled={block.settings.show_mobile_account_links !== false}
              onEnabled={(enabled) =>
                update({
                  settings: {
                    ...block.settings,
                    show_mobile_account_links: enabled,
                  },
                })
              }
              onChange={(mobile_links) =>
                update({
                  translations: {
                    ...block.translations,
                    [locale]: { ...translation, mobile_links },
                  },
                })
              }
            />
          ) : tab === 'image' ? (
            <BlockImage
              imageId={block.image_media_id ?? null}
              mobileId={block.image_mobile_media_id ?? null}
              fields={imageFields}
              settings={block.settings}
              onImage={(column, id) => update({ [column]: id })}
              onSetting={(key, value) =>
                update({ settings: { ...block.settings, [key]: value } })
              }
            />
          ) : (
            <>
              {tab === 'content' && (
                <>
                  <FormField
                    id="block-title"
                    label={t('layouts.display_title')}
                    value={translation.title}
                    maxLength={200}
                    onChange={(e) =>
                      update({
                        translations: {
                          ...block.translations,
                          [locale]: { ...translation, title: e.target.value },
                        },
                      })
                    }
                  />
                </>
              )}
              {definition.fields.map((field) => {
                if ((field.tab ?? 'content') !== tab) return null;
                if (
                  Object.values(identityIcons).includes(field.key) &&
                  (field.key !== 'show_search' || fashion)
                )
                  return null;
                if (
                  productSections.includes(field.key) ||
                  (productSections.length > 0 &&
                    field.key === 'newest_same_category')
                )
                  return null;
                if (single && (field.key === 'limit' || field.carousel_only))
                  return null;
                if (field.pages && !field.pages.includes(pageKind)) return null;
                if (
                  field.when_selection &&
                  !block.selections[field.when_selection]?.length
                )
                  return null;
                if (
                  field.when &&
                  Object.entries(field.when).some(
                    ([key, value]) =>
                      !(Array.isArray(value) ? value : [value]).includes(
                        String(block.settings[key] ?? ''),
                      ),
                  )
                )
                  return null;
                if (field.kind === 'selection') {
                  const hidden =
                    (field.key === 'items' &&
                      block.settings.source &&
                      block.settings.source !== 'manual') ||
                    (field.key === 'filter_categories' &&
                      block.settings.source === 'manual') ||
                    (field.key === 'group' &&
                      block.settings.source !== 'group');
                  if (hidden) return null;
                  return (
                    <div key={field.key} className="space-y-2">
                      <TargetPicker
                        key={`${field.key}:${block.selections.brand?.[0] ?? ''}`}
                        label={t(field.label)}
                        kind={field.source!}
                        values={block.selections[field.key] ?? []}
                        options={(options[field.source!] ?? []).filter(
                          (option) =>
                            block.type !== 'brand_products' ||
                            field.source !== 'products' ||
                            option.brand_id === block.selections.brand?.[0],
                        )}
                        query={
                          block.type === 'brand_products' &&
                          field.source === 'products'
                            ? { brand_id: block.selections.brand?.[0] ?? '' }
                            : {}
                        }
                        path={path}
                        single={
                          field.single || (single && field.key === 'items')
                        }
                        ordered
                        onChange={(ids) => {
                          update({
                            selections: {
                              ...block.selections,
                              ...(block.type === 'brand_products' &&
                              field.key === 'brand'
                                ? { items: [] }
                                : {}),
                              [field.key]: ids,
                            },
                          });
                          if (
                            block.type === 'brand_products' &&
                            field.key === 'brand' &&
                            ids[0]
                          ) {
                            router.get(
                              path,
                              {
                                kind: 'products',
                                brand_id: ids[0],
                                selected: [],
                              },
                              {
                                only: ['options'],
                                preserveState: true,
                                preserveScroll: true,
                              },
                            );
                          }
                        }}
                      />
                      {field.help && (
                        <p className="text-xs text-slate-500">
                          {t(field.help)}
                        </p>
                      )}
                    </div>
                  );
                }
                if (field.kind === 'richtext') {
                  const key =
                    field.key === 'html_right' ? 'html_right' : 'html';
                  return (
                    <Suspense
                      key={field.key}
                      fallback={<p>{t('common.wait')}</p>}
                    >
                      <RichText
                        id={`block-${field.key}`}
                        label={t(field.label)}
                        value={translation[key] ?? ''}
                        onChange={(html) =>
                          update({
                            translations: {
                              ...block.translations,
                              [locale]: { ...translation, [key]: html },
                            },
                          })
                        }
                      />
                    </Suspense>
                  );
                }
                if (field.kind === 'translated_text') {
                  return (
                    <FormField
                      key={field.key}
                      id={`block-translation-${field.key}`}
                      label={t(field.label)}
                      value={translation.link_text ?? ''}
                      maxLength={100}
                      onChange={(e) =>
                        update({
                          translations: {
                            ...block.translations,
                            [locale]: {
                              ...translation,
                              link_text: e.target.value,
                            },
                          },
                        })
                      }
                    />
                  );
                }
                if (field.kind === 'textarea') {
                  return (
                    <div key={field.key} className="space-y-2">
                      <label
                        htmlFor={`block-setting-${field.key}`}
                        className="block text-sm font-medium text-slate-800"
                      >
                        {t(field.label)}
                      </label>
                      <textarea
                        id={`block-setting-${field.key}`}
                        className="min-h-24 w-full rounded-md border border-slate-300 bg-white px-3 py-2 font-mono text-sm"
                        rows={3}
                        maxLength={1000}
                        spellCheck={false}
                        placeholder="/checkout"
                        value={String(block.settings[field.key] ?? '')}
                        onChange={(e) =>
                          update({
                            settings: {
                              ...block.settings,
                              [field.key]: e.target.value,
                            },
                          })
                        }
                      />
                      {field.help && (
                        <p className="text-xs text-slate-500">
                          {t(field.help)}
                        </p>
                      )}
                    </div>
                  );
                }
                if (field.kind === 'order')
                  return (
                    <OrderField
                      key={field.key}
                      label={t(field.label)}
                      help={field.help ? t(field.help) : undefined}
                      value={
                        (block.settings[field.key] as string[] | undefined) ??
                        []
                      }
                      options={(field.options ?? [])
                        .filter(
                          (key) =>
                            field.key !== 'icon_order' ||
                            fashion ||
                            key !== 'search',
                        )
                        .map((value) => ({
                          value,
                          label: t(field.option_labels?.[value] ?? value),
                          details:
                            field.key === 'section_order' &&
                            value === 'show_newest' ? (
                              <label className="mt-2 flex items-center gap-2 text-xs text-slate-500">
                                <input
                                  type="checkbox"
                                  checked={
                                    block.settings.newest_same_category === true
                                  }
                                  onChange={(e) =>
                                    update({
                                      settings: {
                                        ...block.settings,
                                        newest_same_category: e.target.checked,
                                      },
                                    })
                                  }
                                />
                                {t('layouts.newest_same_category')}
                              </label>
                            ) : undefined,
                        }))}
                      onChange={(value) => {
                        // Search keeps its saved slot while only Fashion exposes it as an icon.
                        const ordered = [...value];
                        if (field.key === 'icon_order' && !fashion) {
                          const saved = block.settings.icon_order as
                            string[] | undefined;
                          const searchIndex = saved?.indexOf('search') ?? -1;
                          ordered.splice(
                            searchIndex < 0 ? ordered.length : searchIndex,
                            0,
                            'search',
                          );
                        }
                        update({
                          settings: { ...block.settings, [field.key]: ordered },
                        });
                      }}
                      visibility={
                        field.key === 'section_order'
                          ? Object.fromEntries(
                              productSections.map((key) => [
                                key,
                                block.settings[key] !== false,
                              ]),
                            )
                          : field.key === 'icon_order' &&
                              block.type === 'identity'
                            ? Object.fromEntries(
                                Object.entries(identityIcons).map(
                                  ([key, setting]) => [
                                    key,
                                    setting === 'show_call_request'
                                      ? block.settings[setting] === true
                                      : block.settings[setting] !== false,
                                  ],
                                ),
                              )
                            : undefined
                      }
                      onVisibilityChange={(key, enabled) =>
                        update({
                          settings: {
                            ...block.settings,
                            [identityIcons[key] ?? key]: enabled,
                          },
                        })
                      }
                    />
                  );
                if (field.kind === 'select')
                  return (
                    <label key={field.key} className="block space-y-2">
                      <span className="block text-sm font-medium text-slate-800">
                        {t(field.label)}
                      </span>
                      <Select
                        aria-label={t(field.label)}
                        value={String(block.settings[field.key] ?? '')}
                        options={(field.options ?? []).map((value) => ({
                          value,
                          label: t(
                            field.option_labels?.[value] ??
                              `layouts.source_${value}`,
                          ),
                        }))}
                        onChange={(value) =>
                          update({
                            settings: { ...block.settings, [field.key]: value },
                          })
                        }
                      />
                      {field.help && (
                        <p className="text-xs text-slate-500">
                          {t(field.help)}
                        </p>
                      )}
                    </label>
                  );
                if (field.kind === 'color') {
                  const value = String(block.settings[field.key] ?? '');
                  const set = (next: string) =>
                    update({
                      settings: { ...block.settings, [field.key]: next },
                    });
                  return (
                    <div key={field.key} className="space-y-2">
                      <label
                        htmlFor={`block-setting-${field.key}`}
                        className="block text-sm font-medium text-slate-800"
                      >
                        {t(field.label)}
                      </label>
                      <div className="flex flex-wrap items-center gap-3">
                        <input
                          type="color"
                          aria-label={t(field.label)}
                          className="h-10 w-14 cursor-pointer rounded border border-slate-200 bg-transparent p-1"
                          value={
                            /^#[0-9a-fA-F]{6}$/.test(value) ? value : '#ffffff'
                          }
                          onChange={(e) => set(e.target.value)}
                        />
                        <input
                          id={`block-setting-${field.key}`}
                          className="field w-32 font-mono"
                          value={value}
                          maxLength={7}
                          spellCheck={false}
                          placeholder="#rrggbb"
                          onChange={(e) => set(e.target.value)}
                        />
                        <button
                          type="button"
                          className="text-link"
                          disabled={value === ''}
                          onClick={() => set('')}
                        >
                          {t(field.reset_label ?? 'layouts.background_none')}
                        </button>
                      </div>
                    </div>
                  );
                }
                if (field.kind === 'boolean')
                  return (
                    <label
                      key={field.key}
                      className="flex items-center gap-2 text-sm text-slate-800"
                    >
                      <input
                        type="checkbox"
                        checked={!!block.settings[field.key]}
                        onChange={(e) =>
                          update({
                            settings: {
                              ...block.settings,
                              [field.key]: e.target.checked,
                            },
                          })
                        }
                      />
                      {t(field.label)}
                    </label>
                  );
                return (
                  <FormField
                    key={field.key}
                    id={`block-setting-${field.key}`}
                    label={t(field.label)}
                    type={field.kind === 'number' ? 'number' : 'text'}
                    hint={field.help ? t(field.help) : undefined}
                    min={field.min}
                    max={field.max}
                    value={String(block.settings[field.key] ?? '')}
                    onChange={(e) =>
                      update({
                        settings: {
                          ...block.settings,
                          [field.key]:
                            field.kind === 'number'
                              ? Number(e.target.value)
                              : e.target.value,
                        },
                      })
                    }
                  />
                );
              })}
            </>
          )}
        </div>
        <div className="sticky bottom-0 flex items-center justify-between gap-4 border-t border-slate-200 bg-white p-5">
          <span className="text-xs text-slate-500">
            {t('layouts.draft_help')}
          </span>
          <button className="button-primary">{t('layouts.apply')}</button>
        </div>
      </form>
    </dialog>
  );
}

function xsrf(): string {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : '';
}

/** The Image tab: a picture beside the block's content (left or right; above it on phones) with an optional phone
 * version, and how it fills its space. It is never a link. */
function BlockImage({
  imageId,
  mobileId,
  fields,
  settings,
  onImage,
  onSetting,
}: {
  imageId: string | null;
  mobileId: string | null;
  fields: BlockField[];
  settings: Record<string, string | number | boolean | string[]>;
  onImage: (
    column: 'image_media_id' | 'image_mobile_media_id',
    id: string | null,
  ) => void;
  onSetting: (key: string, value: string) => void;
}) {
  const t = useTranslation();
  return (
    <div className="space-y-5">
      <div className="grid gap-5 lg:grid-cols-2">
        <ImageUpload
          title={t('layouts.side_image')}
          help={t('layouts.side_image_help')}
          imageId={imageId}
          onImage={(id) => onImage('image_media_id', id)}
        />
        <ImageUpload
          title={t('layouts.side_image_mobile')}
          help={t('layouts.side_image_mobile_help')}
          imageId={mobileId}
          onImage={(id) => onImage('image_mobile_media_id', id)}
        />
      </div>
      {fields
        .filter((field) => field.kind === 'url')
        .map((field) => (
          <FormField
            key={field.key}
            id={`block-setting-${field.key}`}
            label={t(field.label)}
            type="url"
            hint={field.help ? t(field.help) : undefined}
            value={String(settings[field.key] ?? '')}
            onChange={(e) => onSetting(field.key, e.target.value)}
          />
        ))}
      <div className="grid gap-5 sm:grid-cols-2">
        {fields
          .filter((field) => field.kind === 'select')
          .map((field) => (
            <label key={field.key} className="block space-y-2">
              <span className="block text-sm font-medium text-slate-800">
                {t(field.label)}
              </span>
              <Select
                aria-label={t(field.label)}
                value={String(settings[field.key] ?? '')}
                options={(field.options ?? []).map((value) => ({
                  value,
                  label: t(field.option_labels?.[value] ?? value),
                }))}
                onChange={(value) => onSetting(field.key, value)}
              />
              {field.help && (
                <p className="text-xs text-slate-500">{t(field.help)}</p>
              )}
            </label>
          ))}
      </div>
    </div>
  );
}

/** One picture, uploaded as soon as it is chosen; the block refers to it when the layout is saved. */
function ImageUpload({
  title,
  help,
  imageId,
  onImage,
}: {
  title: string;
  help: string;
  imageId: string | null;
  onImage: (id: string | null) => void;
}) {
  const t = useTranslation();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  // The picked file shows as a local preview until the stored picture replaces it.
  const [file, setFile] = useState<File | null>(null);
  const upload = async (file: File) => {
    setBusy(true);
    setError('');
    const body = new FormData();
    body.append('image', file);
    try {
      const response = await fetch('/admin/layouts-media', {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-XSRF-TOKEN': xsrf(),
        },
      });
      const data = await response.json();
      if (response.ok) onImage(String(data.id));
      else
        setError(
          Object.values(
            (data.errors ?? {}) as Record<string, string[]>,
          )[0]?.[0] ??
            data.message ??
            t('layouts.side_image_invalid'),
        );
    } catch {
      setError(t('layouts.side_image_invalid'));
    } finally {
      // Stored or refused, the local preview gives way to what the block really shows.
      setFile(null);
      setBusy(false);
    }
  };
  return (
    <SingleImageUploader
      title={title}
      help={help}
      current={imageId ? { url: `/admin/layouts-media/${imageId}` } : null}
      alt={title}
      file={file}
      onFile={(next) => {
        setFile(next);
        if (next) void upload(next);
      }}
      removed={false}
      onRemoved={(removed) => removed && onImage(null)}
      disabled={busy}
      error={
        error ? (
          <p role="alert" className="text-sm text-red-700">
            {error}
          </p>
        ) : undefined
      }
    />
  );
}

/**
 * A list to put in order: drag a row by its grip, or focus the grip and use the up and down arrow keys (the same
 * interaction as the payment method list).
 */
function OrderField({
  label,
  help,
  value,
  options,
  onChange,
  visibility,
  onVisibilityChange,
}: {
  label: string;
  help?: string;
  visibility?: Record<string, boolean>;
  onVisibilityChange?: (key: string, enabled: boolean) => void;
  value: string[];
  options: { value: string; label: string; details?: ReactNode }[];
  onChange: (value: string[]) => void;
}) {
  const t = useTranslation();
  const [dragging, setDragging] = useState<string | null>(null);
  // The saved order first; options it lacks (added later) at the end.
  const keys = [
    ...value.filter((key) => options.some((o) => o.value === key)),
    ...options.map((o) => o.value).filter((key) => !value.includes(key)),
  ];
  const place = (key: string, to: number) => {
    if (to < 0 || to >= keys.length) return;
    const next = keys.filter((k) => k !== key);
    next.splice(to, 0, key);
    onChange(next);
  };
  return (
    <div className="space-y-2">
      <span className="block text-sm font-medium text-slate-800">{label}</span>
      <ol className="divide-y divide-slate-200 rounded-lg border border-slate-200">
        {keys.map((key, index) => {
          const name = options.find((o) => o.value === key)?.label ?? key;
          return (
            <li
              key={key}
              draggable
              onDragStart={(e) => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', key);
                setDragging(key);
              }}
              onDragEnd={() => setDragging(null)}
              onDragOver={(e) => {
                if (dragging === null) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
              }}
              onDrop={(e) => {
                e.preventDefault();
                if (dragging !== null) place(dragging, index);
                setDragging(null);
              }}
              className={`flex items-center gap-3 px-3 py-2 text-sm ${dragging === key ? 'opacity-50' : ''}`}
            >
              <button
                type="button"
                aria-label={t('layouts.icon_order_handle', { name })}
                title={t('layouts.icon_order_handle', { name })}
                className="flex cursor-grab items-center gap-2 rounded text-slate-400 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-emerald-600"
                onKeyDown={(e) => {
                  if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    place(key, index + (e.key === 'ArrowUp' ? -1 : 1));
                  }
                }}
              >
                <DragGrip />
                <span className="tabular-nums text-slate-600">{index + 1}</span>
              </button>
              <div className="flex-1">
                {name}
                {options.find((option) => option.value === key)?.details}
              </div>
              {visibility && (
                <Switch
                  checked={visibility[key] !== false}
                  label={name}
                  onChange={(enabled) => onVisibilityChange?.(key, enabled)}
                />
              )}
            </li>
          );
        })}
      </ol>
      {help && <p className="text-xs text-slate-500">{help}</p>}
    </div>
  );
}
