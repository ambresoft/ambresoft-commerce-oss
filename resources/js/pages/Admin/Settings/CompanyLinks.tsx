import { useForm } from '@inertiajs/react';
import { useMemo, useRef, useState, type KeyboardEvent } from 'react';
import Card from '../../../components/admin/AdminCard';
import {
  useIntake,
  usePreviews,
} from '../../../components/admin/ImageUploader';
import SortableImageTiles from '../../../components/admin/SortableImageTiles';
import Select from '../../../components/admin/Select';
import { Icon } from '../../../components/admin/list/IconButton';
import FormField from '../../../components/auth/FormField';
import SocialIcon from '../../../components/SocialIcon';
import { useTranslation } from '../../../lib/useTranslation';

export type CompanyLink = {
  id: string;
  kind: 'social' | 'app';
  preset: string | null;
  name: string;
  url: string;
  image: string | null;
};
type Kind = CompanyLink['kind'];

/** Brand names are not translated; they match the server's CompanySettings::PRESETS. */
const presets: Record<Kind, Record<string, string>> = {
  social: {
    facebook: 'Facebook',
    instagram: 'Instagram',
    tiktok: 'TikTok',
    linkedin: 'LinkedIn',
    x: 'X',
    youtube: 'YouTube',
    pinterest: 'Pinterest',
  },
  app: { app_store: 'App Store', google_play: 'Google Play' },
};
const LIMIT = 20;

function host(url: string): string {
  try {
    return new URL(url).host;
  } catch {
    return url;
  }
}

/** The tile/preview mark: an uploaded picture, else the preset's built-in one. */
function Mark({
  kind,
  preset,
  image,
  size = 'size-12',
}: {
  kind: Kind;
  preset: string | null;
  image: string | null;
  size?: string;
}) {
  if (image)
    return (
      <img
        src={image}
        alt=""
        className="max-h-full max-w-full object-contain"
        draggable={false}
      />
    );
  if (kind === 'app')
    return (
      <span className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white">
        {presets.app[preset ?? ''] ?? '?'}
      </span>
    );
  return <SocialIcon network={preset ?? ''} className={size} />;
}

type Draft = {
  id: string | null;
  preset: string;
  name: string;
  url: string;
  image: File | null;
  remove_image: boolean;
};

function LinkList({
  kind,
  links,
  disabled,
  onBusyChange,
}: {
  kind: Kind;
  links: CompanyLink[];
  disabled: boolean;
  onBusyChange: (busy: boolean) => void;
}) {
  const t = useTranslation();
  const input = useRef<HTMLInputElement>(null);
  const [editing, setEditing] = useState(false);
  const [current, setCurrent] = useState<CompanyLink | null>(null);
  const form = useForm<Draft>({
    id: null,
    preset: Object.keys(presets[kind])[0]!,
    name: '',
    url: '',
    image: null,
    remove_image: false,
  });
  const { accept, messages } = useIntake(1);
  const files = useMemo(
    () => (form.data.image ? [form.data.image] : []),
    [form.data.image],
  );
  const [preview] = usePreviews(files);
  const busy = disabled || form.processing;
  const prefix = 'company-link-' + kind;
  const open = (link: CompanyLink | null) => {
    setCurrent(link);
    form.clearErrors();
    form.setData({
      id: link?.id ?? null,
      preset: link ? (link.preset ?? '') : Object.keys(presets[kind])[0]!,
      name: link?.name ?? '',
      url: link?.url ?? '',
      image: null,
      remove_image: false,
    });
    setEditing(true);
  };
  const close = () => {
    form.reset();
    form.clearErrors();
    setEditing(false);
  };
  const save = () => {
    if (busy) return;
    const { id, ...data } = form.data;
    form.transform(() => ({
      ...(id === null ? { kind } : {}),
      ...data,
      preset: data.preset === '' ? null : data.preset,
      remove_image: data.remove_image ? 1 : 0,
    }));
    form.post(
      id === null
        ? '/admin/settings/company/links'
        : '/admin/settings/company/links/' + id,
      {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => onBusyChange(true),
        onFinish: () => onBusyChange(false),
        onSuccess: close,
      },
    );
  };
  // The page's own form would otherwise submit on Enter.
  const enter = (event: KeyboardEvent) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      save();
    }
  };
  const shownImage = form.data.image
    ? preview
    : form.data.remove_image
      ? null
      : (current?.image ?? null);
  const preset = form.data.preset === '' ? null : form.data.preset;
  return (
    <Card
      id={prefix}
      icon={kind === 'app' ? 'box' : 'chat'}
      title={
        <span className="flex items-center gap-2">
          {t(
            kind === 'app'
              ? 'company.social_apps_title'
              : 'company.social_icons_title',
          )}
          <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
            {links.length}
          </span>
        </span>
      }
      bodyClassName="space-y-5 p-5"
    >
      <p className="text-sm text-slate-500">
        {t(
          kind === 'app'
            ? 'company.social_apps_help'
            : 'company.social_icons_help',
        )}
      </p>
      <SortableImageTiles
        items={links}
        label={t(
          kind === 'app'
            ? 'company.social_apps_title'
            : 'company.social_icons_title',
        )}
        name={(link) => link.name}
        orderUrl="/admin/settings/company/links/order"
        orderData={(ids) => ({ kind, ids })}
        removeUrl={(link) => '/admin/settings/company/links/' + link.id}
        removeTitle={t('company.link_remove')}
        removeHint={t('company.link_remove_hint')}
        onEdit={open}
        onAdd={() => open(null)}
        addLabel={t('company.link_add')}
        limit={LIMIT}
        limitText={t('company.links_limit', { count: LIMIT })}
        disabled={busy || editing}
        onBusyChange={onBusyChange}
      >
        {(link) => (
          <>
            <div className="flex h-24 items-center justify-center rounded-xl bg-slate-50 p-4">
              <Mark kind={kind} preset={link.preset} image={link.image} />
            </div>
            <p
              className="mt-2 truncate text-xs text-slate-500"
              title={link.url}
            >
              {host(link.url)}
            </p>
          </>
        )}
      </SortableImageTiles>
      {editing && (
        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-5">
          <div className="mb-5 flex items-center justify-between">
            <h3 className="text-sm font-semibold">
              {form.data.id === null
                ? t('company.link_new')
                : t('common.edit') + ' · ' + (current?.name ?? '')}
            </h3>
            <button
              type="button"
              className="button-secondary gap-1 px-3 py-1.5 text-xs"
              disabled={form.processing}
              onClick={close}
            >
              <Icon name="x" />
              {t('common.close')}
            </button>
          </div>
          <div className="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <div className="space-y-2">
              <span className="block text-sm font-medium text-slate-700">
                {t('company.social_icon_image')}
              </span>
              <div
                className="flex min-h-24 items-center gap-3 rounded-xl border border-slate-200 bg-white p-3"
                onDragOver={(event) => event.preventDefault()}
                onDrop={(event) => {
                  event.preventDefault();
                  const [file] = accept(
                    Array.from(event.dataTransfer.files).slice(0, 1),
                    0,
                  );
                  if (file && !busy) form.setData('image', file);
                }}
              >
                <span className="flex size-16 shrink-0 items-center justify-center rounded-lg border border-slate-100 p-2">
                  {preset === null && !shownImage ? (
                    <Icon name="upload" />
                  ) : (
                    <Mark
                      kind={kind}
                      preset={preset}
                      image={shownImage}
                      size="size-10"
                    />
                  )}
                </span>
                <p className="min-w-0 flex-1 text-sm font-medium break-words">
                  {form.data.image?.name ??
                    (shownImage
                      ? current?.name
                      : t('company.link_builtin_icon'))}
                </p>
                <button
                  type="button"
                  disabled={busy}
                  className="button-secondary shrink-0 px-3 py-2 text-xs"
                  onClick={() => input.current?.click()}
                >
                  {t(
                    shownImage
                      ? 'company.payment_image_change'
                      : 'media.choose_one',
                  )}
                </button>
              </div>
              <input
                ref={input}
                type="file"
                className="sr-only"
                tabIndex={-1}
                aria-label={t('company.social_icon_image')}
                accept="image/png,image/jpeg,image/webp"
                disabled={busy}
                onChange={(event) => {
                  const [file] = accept(
                    Array.from(event.target.files ?? []),
                    0,
                  );
                  if (file)
                    form.setData((data) => ({
                      ...data,
                      image: file,
                      remove_image: false,
                    }));
                  event.target.value = '';
                }}
              />
              <p className="text-xs leading-relaxed text-slate-500">
                {t('company.social_icon_hint')}
              </p>
              {preset !== null && shownImage && (
                <button
                  type="button"
                  className="text-link text-sm"
                  disabled={busy}
                  onClick={() =>
                    form.setData((data) => ({
                      ...data,
                      image: null,
                      remove_image: true,
                    }))
                  }
                >
                  {t('company.social_icon_restore')}
                </button>
              )}
              {messages}
              {form.errors.image && (
                <p role="alert" className="text-sm text-red-700">
                  {form.errors.image}
                </p>
              )}
            </div>
            <div className="space-y-4">
              <div className="space-y-2">
                <label
                  htmlFor={prefix + '-preset'}
                  className="block text-sm font-medium text-slate-800"
                >
                  {t(
                    kind === 'app'
                      ? 'company.link_app_preset'
                      : 'company.link_preset',
                  )}
                </label>
                <Select
                  id={prefix + '-preset'}
                  value={form.data.preset}
                  disabled={busy}
                  invalid={!!form.errors.preset}
                  options={[
                    ...Object.entries(presets[kind]).map(([value, label]) => ({
                      value,
                      label,
                    })),
                    { value: '', label: t('company.link_custom') },
                  ]}
                  onChange={(value) => form.setData('preset', value)}
                />
                {form.errors.preset && (
                  <p role="alert" className="text-sm text-red-700">
                    {form.errors.preset}
                  </p>
                )}
              </div>
              <FormField
                id={prefix + '-name'}
                label={t('company.link_name')}
                hint={t(
                  preset === null
                    ? 'company.link_name_required'
                    : 'company.link_name_hint',
                )}
                value={form.data.name}
                placeholder={preset === null ? '' : presets[kind][preset]}
                maxLength={80}
                error={form.errors.name}
                disabled={busy}
                onKeyDown={enter}
                onChange={(event) => form.setData('name', event.target.value)}
              />
              <FormField
                id={prefix + '-url'}
                label={t('company.link_url')}
                hint={t('company.link_url_hint')}
                type="url"
                value={form.data.url}
                maxLength={2048}
                error={form.errors.url}
                disabled={busy}
                onKeyDown={enter}
                onChange={(event) => form.setData('url', event.target.value)}
              />
              {form.progress && (
                <progress
                  className="h-2 w-full accent-primary"
                  aria-label={t('media.uploading')}
                  max={100}
                  value={form.progress.percentage ?? 0}
                />
              )}
              <div className="flex gap-2">
                <button
                  type="button"
                  className="button-primary px-4 py-2 text-sm"
                  disabled={busy}
                  onClick={save}
                >
                  {t('company.link_save')}
                </button>
                <button
                  type="button"
                  className="button-secondary px-4 py-2 text-sm"
                  disabled={form.processing}
                  onClick={close}
                >
                  {t('common.cancel')}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </Card>
  );
}

export default function CompanyLinks({
  links,
  disabled,
  onBusyChange,
}: {
  links: CompanyLink[];
  disabled: boolean;
  onBusyChange: (busy: boolean) => void;
}) {
  return (
    <div className="space-y-6">
      {(['social', 'app'] as const).map((kind) => (
        <LinkList
          key={kind}
          kind={kind}
          links={links.filter((link) => link.kind === kind)}
          disabled={disabled}
          onBusyChange={onBusyChange}
        />
      ))}
    </div>
  );
}
