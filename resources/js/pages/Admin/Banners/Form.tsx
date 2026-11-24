import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { usePreferences } from '../../../lib/usePreferences';
import { toLocalInput, toInstant } from '../../../lib/zonedDateTime';
import DateInput from '../../../components/admin/DateInput';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import BannerPreview, {
  type PreviewContent,
} from '../../../components/admin/banners/BannerPreview';
import AiChat from '../../../components/admin/AiChat';
import {
  Check,
  Choice,
  ColorField,
  Override,
  Row,
  Section,
  VideoField,
} from '../../../components/admin/form/fields';
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import {
  emptyContent,
  emptyOverrides,
  mediaFor,
  resolveLayout,
} from '../../../lib/banners';
import { bannerLabels } from '../../../lib/bannerLabels';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type {
  BannerAiDraft,
  BannerEditor,
  BannerGroup,
  ContentDraft,
  Device,
  Layout,
  LayoutDraft,
  MediaSlot,
  ProductOption,
  Settings,
} from '../../../types/banners';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & {
  banner: BannerEditor | null;
  groups: BannerGroup[];
  customerGroups: { id: string; name: string }[];
  candidates: ProductOption[];
  defaultLocale: string;
  layoutDefaults: Layout;
  cssPresets: string[];
  videoMaxBytes: number;
  aiReady?: boolean;
};

/** Settings and PC layout fields the AI assistant reads and writes (mirrors the server's BannerAiGenerator). */
const AI_SETTINGS = [
  'color_scheme',
  'background_color',
  'title_color',
  'title_weight',
  'title_tag',
  'description_color',
  'description_background',
  'button_style',
  'button_hover',
  'url',
] as const;
const AI_LAYOUT = [
  'valign',
  'halign',
  'paddings',
  'full_width',
  'title_size',
  'title_shadow',
  'description_size',
  'button_enabled',
  'button_text_color',
  'button_color',
  'mask_type',
  'mask_position',
  'mask_opacity',
  'mask_direction',
  'mask_color',
] as const;

type FormData = Settings & {
  version: number | null;
  names: Record<string, string>;
  group_id: string;
  position: string;
  status: 'active' | 'disabled';
  created_on: string;
  available: boolean;
  available_from: string;
  available_until: string;
  layouts: { pc: Layout; tablet: LayoutDraft; phone: LayoutDraft };
  contents: Record<Device, Record<string, ContentDraft>>;
  products: string[];
  customer_groups: string[];
};

const stateTone = {
  live: 'success',
  scheduled: 'warning',
  expired: 'neutral',
  disabled: 'neutral',
} as const;

/** Lets one object URL per chosen file live as long as the file is selected. */
function useObjectUrls() {
  const urls = useRef(new Map<File, string>());
  useEffect(() => {
    const map = urls.current;
    return () => map.forEach((url) => URL.revokeObjectURL?.(url));
  }, []);
  return (file: File) => {
    let url = urls.current.get(file);
    if (!url) {
      url =
        typeof URL.createObjectURL === 'function'
          ? URL.createObjectURL(file)
          : '';
      urls.current.set(file, url);
    }
    return url;
  };
}

/** Preview heights, in percent of the design (the store block allows 100–250). */
const PREVIEW_HEIGHTS = [100, 125, 150, 175, 200, 250];

export default function BannerForm(props: Props) {
  // A fresh editor after every save, so file inputs and removal flags reset.
  return (
    <BannerEditorForm
      key={(props.banner?.id ?? 'new') + ':' + (props.banner?.version ?? 0)}
      {...props}
    />
  );
}

function BannerEditorForm({
  banner,
  groups,
  customerGroups,
  candidates,
  defaultLocale,
  layoutDefaults,
  cssPresets,
  videoMaxBytes,
  aiReady = false,
  ...shell
}: Props) {
  const t = useTranslation();
  const { timezone: timeZone } = usePreferences();
  const { capabilities, i18n } = usePage<SharedProps>().props;
  const allowed = !!capabilities['banners.manage'];
  const locales = i18n.locales;
  const [locale, setLocale] = useState(
    locales.some((l) => l.code === i18n.locale) ? i18n.locale : defaultLocale,
  );
  const [tab, setTab] = useState<Device | 'ai'>('pc');
  const [previewDevice, setPreviewDevice] = useState<Device>('pc');
  // How tall the preview is, as a percentage of the design: what the store block's "banner height" will show.
  const [previewHeight, setPreviewHeight] = useState(() => {
    try {
      const saved = Number(localStorage.getItem('banner-preview-height'));
      return PREVIEW_HEIGHTS.includes(saved) ? saved : 100;
    } catch {
      return 100;
    }
  });
  const [productSearch, setProductSearch] = useState('');
  const [chosen, setChosen] = useState<ProductOption[]>(banner?.products ?? []);
  const titleField = useRef<HTMLTextAreaElement>(null);
  const objectUrl = useObjectUrls();

  const contentsFor = (device: Device) =>
    Object.fromEntries(
      locales.map((l) => [
        l.code,
        emptyContent(banner?.contents[device]?.[l.code]),
      ]),
    );

  const form = useForm<FormData>({
    version: banner?.version ?? null,
    names: Object.fromEntries(
      locales.map((l) => [l.code, banner?.names[l.code] ?? '']),
    ),
    group_id: banner?.group_id ?? '',
    position: String(banner?.position ?? 0),
    status: banner?.status ?? 'active',
    created_on: banner?.created_on ?? new Date().toISOString().slice(0, 10),
    available: !!(banner?.available_from || banner?.available_until),
    available_from: toLocalInput(banner?.available_from, timeZone),
    available_until: toLocalInput(banner?.available_until, timeZone),
    color_scheme: banner?.color_scheme ?? 'light',
    object_type: banner?.object_type ?? 'image',
    youtube_autoplay: banner?.youtube_autoplay ?? false,
    youtube_hide_controls: banner?.youtube_hide_controls ?? false,
    products_template: banner?.products_template ?? 'grid',
    products_columns: banner?.products_columns ?? 4,
    products_rows: banner?.products_rows ?? 1,
    object_valign: banner?.object_valign ?? 'center',
    object_halign: banner?.object_halign ?? 'center',
    background_color: banner?.background_color ?? null,
    background_halign: banner?.background_halign ?? 'center',
    title_color: banner?.title_color ?? null,
    title_weight: banner?.title_weight ?? 'normal',
    title_tag: banner?.title_tag ?? 'div',
    description_color: banner?.description_color ?? null,
    description_background: banner?.description_background ?? null,
    button_style: banner?.button_style ?? 'standard',
    button_hover: banner?.button_hover ?? 'none',
    button_transition: banner?.button_transition ?? 0.3,
    url: banner?.url ?? '',
    new_window: banner?.new_window ?? false,
    tablet_custom: banner?.tablet_custom ?? false,
    phone_custom: banner?.phone_custom ?? false,
    layouts: {
      pc: { ...layoutDefaults, ...(banner?.layouts.pc ?? {}) } as Layout,
      tablet: emptyOverrides(banner?.layouts.tablet),
      phone: emptyOverrides(banner?.layouts.phone),
    },
    contents: {
      pc: contentsFor('pc'),
      tablet: contentsFor('tablet'),
      phone: contentsFor('phone'),
    },
    products: (banner?.products ?? []).map((p) => p.id),
    customer_groups: banner?.customer_groups ?? [],
  });
  useDirtyForm(form);
  const data = form.data;
  const errors = form.errors as Record<string, string>;
  const pc = data.layouts.pc;

  // Device tabs exist only while their own settings are enabled.
  const deviceTabs: Device[] = [
    'pc',
    ...(data.tablet_custom ? (['tablet'] as const) : []),
    ...(data.phone_custom ? (['phone'] as const) : []),
  ];
  const showAi = allowed && !!capabilities['banners.generate'];
  const activeTab: Device | 'ai' =
    tab === 'ai'
      ? showAi
        ? 'ai'
        : 'pc'
      : deviceTabs.includes(tab)
        ? tab
        : 'pc';

  // The assistant sees and changes only names, colours, PC layout and PC texts; media and other settings stay.
  const aiDraft = (): BannerAiDraft => ({
    names: { ...data.names },
    settings: Object.fromEntries(AI_SETTINGS.map((key) => [key, data[key]])),
    layout: Object.fromEntries(AI_LAYOUT.map((key) => [key, pc[key]])),
    contents: Object.fromEntries(
      locales.map((l) => [
        l.code,
        {
          title: data.contents.pc[l.code]?.title ?? '',
          description: data.contents.pc[l.code]?.description ?? '',
          button_text: data.contents.pc[l.code]?.button_text ?? '',
        },
      ]),
    ),
  });
  const applyAi = (draft: BannerAiDraft) =>
    form.setData((current) => ({
      ...current,
      ...draft.settings,
      names: { ...current.names, ...draft.names },
      layouts: {
        ...current.layouts,
        pc: { ...current.layouts.pc, ...draft.layout },
      },
      contents: {
        ...current.contents,
        pc: Object.fromEntries(
          Object.entries(current.contents.pc).map(([code, value]) => [
            code,
            { ...value, ...(draft.contents[code] ?? {}) },
          ]),
        ),
      },
    }));

  const setLayout = <K extends keyof Layout>(
    device: Device,
    key: K,
    value: Layout[K] | null,
  ) =>
    form.setData('layouts', {
      ...data.layouts,
      [device]: { ...data.layouts[device], [key]: value },
    });
  const content = (device: Device, code = locale) =>
    data.contents[device][code];
  const setContent = (patch: Partial<ContentDraft>, device: Device) =>
    form.setData('contents', {
      ...data.contents,
      [device]: {
        ...data.contents[device],
        [locale]: { ...data.contents[device][locale], ...patch },
      },
    });

  // Preview: resolve device overrides, then this language's texts and media with PC fallback.
  const preview = useMemo(() => {
    const device = previewDevice;
    const custom =
      device !== 'pc' &&
      (device === 'tablet' ? data.tablet_custom : data.phone_custom);
    const layout = custom
      ? resolveLayout(data.layouts.pc, data.layouts[device])
      : data.layouts.pc;
    const own = custom ? data.contents[device][locale] : undefined;
    const base = data.contents.pc[locale];
    const fallback = data.contents.pc[defaultLocale];
    const stored = (d: Device, code: string) => banner?.contents[d]?.[code];
    const text = (key: 'title' | 'description' | 'button_text') =>
      (own?.[key] || base?.[key] || '').toString();
    const media = (slot: MediaSlot) =>
      (custom
        ? mediaFor(own, stored(device, locale), slot, objectUrl)?.url
        : undefined) ??
      mediaFor(base, stored('pc', locale), slot, objectUrl)?.url ??
      (locale !== defaultLocale
        ? mediaFor(fallback, stored('pc', defaultLocale), slot, objectUrl)?.url
        : undefined) ??
      null;
    const value: PreviewContent = {
      title: text('title'),
      description: text('description'),
      button_text: text('button_text'),
      youtube_video: youtubeId(base?.youtube_video ?? ''),
      main_image: media('main_image'),
      background_image: media('background_image'),
      background_video: media('background_video'),
    };
    return { layout, value };
  }, [previewDevice, data, locale, defaultLocale, banner, objectUrl]);

  const submit = () => {
    form.transform((values) => ({
      ...values,
      // The availability window is only sent while it is switched on.
      // Sent as instants with this browser's offset, so the window means the administrator's local time.
      available_from: values.available
        ? toInstant(values.available_from, timeZone)
        : '',
      available_until: values.available
        ? toInstant(values.available_until, timeZone)
        : '',
    }));
    form.post(banner ? '/admin/banners/' + banner.id : '/admin/banners', {
      forceFormData: true,
      preserveScroll: true,
    });
  };

  const insertTitle = (before: string, after = '') => {
    const field = titleField.current;
    // Title tools live in the device tabs only.
    const device: Device = activeTab === 'ai' ? 'pc' : activeTab;
    const value = content(device).title;
    const start = field?.selectionStart ?? value.length;
    const end = field?.selectionEnd ?? value.length;
    const selected = value.slice(start, end) || (after ? 'Text' : '');
    setContent(
      {
        title:
          value.slice(0, start) + before + selected + after + value.slice(end),
      },
      device,
    );
    requestAnimationFrame(() => field?.focus());
  };

  const searchProducts = (value: string) =>
    router.reload({
      only: ['candidates'],
      data: { product_search: value },
    });

  const align = {
    v: [
      { value: 'top' as const, label: t('banners.top') },
      { value: 'center' as const, label: t('banners.center') },
      { value: 'bottom' as const, label: t('banners.bottom') },
    ],
    h: [
      { value: 'left' as const, label: t('banners.left') },
      { value: 'center' as const, label: t('banners.center') },
      { value: 'right' as const, label: t('banners.right') },
    ],
  };

  /** A device-dependent layout control: direct on PC, behind "Override" on tablet/phone. */
  const layoutControl = <K extends keyof Layout>(
    device: Device,
    key: K,
    label: string,
    render: (
      value: Layout[K],
      onChange: (value: Layout[K]) => void,
      disabled: boolean,
    ) => React.ReactNode,
    help?: string,
  ) => {
    const id = `${device}-${key}`;
    const error = errors[`layouts.${device}.${key}`];
    if (device === 'pc')
      return (
        <Row id={id} label={label} help={help} error={error}>
          {render(pc[key], (v) => setLayout('pc', key, v), !allowed)}
        </Row>
      );
    const override = data.layouts[device][key] as Layout[K] | null;
    const active = override !== null;
    return (
      <Row id={id} label={label} help={help} error={error}>
        <Override
          label={label}
          active={active}
          disabled={!allowed}
          onToggle={(on) => setLayout(device, key, on ? pc[key] : null)}
        >
          {render(
            active ? override : pc[key],
            (v) => setLayout(device, key, v),
            !allowed || !active,
          )}
        </Override>
      </Row>
    );
  };

  const textControl = (
    device: Device,
    key: 'title' | 'description' | 'button_text',
    label: string,
    multiline: boolean,
    help?: string,
  ) => {
    const id = `${device}-${key}-${locale}`;
    const value = content(device)[key];
    const inherited =
      device === 'pc' ? undefined : content('pc')[key] || undefined;
    const common = {
      id,
      className: multiline ? 'field min-h-28' : 'field',
      value,
      disabled: !allowed,
      placeholder: inherited,
      maxLength: key === 'title' ? 500 : key === 'description' ? 1000 : 120,
      'aria-invalid': errors[`contents.${device}.${locale}.${key}`]
        ? true
        : undefined,
    };
    return (
      <Row
        id={id}
        label={label}
        help={help}
        error={errors[`contents.${device}.${locale}.${key}`]}
      >
        {key === 'title' && allowed && (
          <div
            className="flex flex-wrap gap-1.5"
            role="group"
            aria-label={t('banners.presets')}
          >
            {(
              [
                [
                  'banners.preset_above',
                  '<span class="banner-title-above">',
                  '</span>',
                ],
                [
                  'banners.preset_color',
                  '<span style="color: #e11d48">',
                  '</span>',
                ],
                ['banners.preset_thin', '<i>', '</i>'],
                ['banners.preset_mark', '<mark>', '</mark>'],
                ['banners.preset_row', '<br>', ''],
              ] as const
            ).map(([labelKey, before, after]) => (
              <button
                key={labelKey}
                type="button"
                className="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100"
                onClick={() => insertTitle(before, after)}
              >
                {t(labelKey)}
              </button>
            ))}
          </div>
        )}
        {multiline ? (
          <textarea
            {...common}
            ref={key === 'title' ? titleField : undefined}
            onChange={(e) => setContent({ [key]: e.target.value }, device)}
          />
        ) : (
          <input
            {...common}
            onChange={(e) => setContent({ [key]: e.target.value }, device)}
          />
        )}
      </Row>
    );
  };

  const imageControl = (
    device: Device,
    slot: 'main_image' | 'background_image',
    label: string,
  ) => {
    const stored = banner?.contents[device]?.[locale]?.[slot] ?? null;
    const draft = content(device);
    return (
      <Row id={`${device}-${slot}-${locale}`} label={label}>
        <SingleImageUploader
          title={label}
          help={device === 'pc' ? undefined : t('banners.inherited')}
          current={stored ? { url: stored.url, meta: stored.meta } : null}
          alt=""
          file={draft[slot]}
          onFile={(file) => setContent({ [slot]: file }, device)}
          removed={draft[`remove_${slot}`]}
          onRemoved={(value) =>
            setContent({ [`remove_${slot}`]: value }, device)
          }
          disabled={!allowed}
          error={errors[`contents.${device}.${locale}.${slot}`]}
        />
      </Row>
    );
  };

  const videoControl = (device: Device) => {
    const draft = content(device);
    return (
      <Row
        id={`${device}-background_video-${locale}`}
        label={t('banners.background_video')}
      >
        <VideoField
          hasCurrent={!!banner?.contents[device]?.[locale]?.background_video}
          file={draft.background_video}
          removed={draft.remove_background_video}
          onFile={(file) => setContent({ background_video: file }, device)}
          onRemoved={(value) =>
            setContent({ remove_background_video: value }, device)
          }
          maxBytes={videoMaxBytes}
          disabled={!allowed}
          error={errors[`contents.${device}.${locale}.background_video`]}
        />
      </Row>
    );
  };

  const numberInput = (
    value: number,
    onChange: (value: number) => void,
    disabled: boolean,
    min: number,
    max: number,
    step = 1,
  ) => (
    <input
      type="number"
      className="field max-w-32"
      min={min}
      max={max}
      step={step}
      value={value}
      disabled={disabled}
      onChange={(e) => onChange(Number(e.target.value))}
    />
  );

  const deviceSections = (device: Device) => {
    const backgroundType =
      device === 'pc'
        ? pc.background_type
        : (data.layouts[device].background_type ?? pc.background_type);
    return (
      <>
        <Section title={t('banners.section_block')} iconKey="layouts">
          {device === 'pc' && (
            <Row
              id="color_scheme"
              label={t('banners.color_scheme')}
              help={t('banners.color_scheme_help')}
              error={errors.color_scheme}
            >
              <Choice
                id="color_scheme"
                value={data.color_scheme}
                disabled={!allowed}
                options={[
                  { value: 'light', label: t('banners.scheme_light') },
                  { value: 'dark', label: t('banners.scheme_dark') },
                ]}
                onChange={(v) => form.setData('color_scheme', v)}
              />
            </Row>
          )}
          {layoutControl(
            device,
            'valign',
            t('banners.valign'),
            (value, onChange, disabled) => (
              <Choice
                id={`${device}-valign`}
                value={value}
                options={align.v}
                onChange={onChange}
                disabled={disabled}
              />
            ),
            t('banners.valign_help'),
          )}
          {layoutControl(
            device,
            'halign',
            t('banners.halign'),
            (value, onChange, disabled) => (
              <Choice
                id={`${device}-halign`}
                value={value}
                options={align.h}
                onChange={onChange}
                disabled={disabled}
              />
            ),
            t('banners.halign_help'),
          )}
          {layoutControl(
            device,
            'paddings',
            t('banners.paddings'),
            (value, onChange, disabled) => (
              <input
                id={`${device}-paddings`}
                className="field max-w-md"
                maxLength={60}
                value={value}
                disabled={disabled}
                onChange={(e) => onChange(e.target.value)}
              />
            ),
            t('banners.paddings_help'),
          )}
          {layoutControl(
            device,
            'full_width',
            t('banners.full_width'),
            (value, onChange, disabled) => (
              <Check
                id={`${device}-full_width`}
                checked={value}
                onChange={onChange}
                disabled={disabled}
              />
            ),
            t('banners.full_width_help'),
          )}
        </Section>

        <Section title={t('banners.section_title')} iconKey="content">
          {textControl(
            device,
            'title',
            t('banners.title_text'),
            true,
            t('banners.title_help'),
          )}
          {layoutControl(
            device,
            'title_size',
            t('banners.font_size'),
            (value, onChange, disabled) =>
              numberInput(value, onChange, disabled, 6, 200),
          )}
          {device === 'pc' && (
            <>
              <Row
                id="title_color"
                label={t('banners.title_color')}
                help={t('banners.color_auto_help')}
                error={errors.title_color}
              >
                <ColorField
                  id="title_color"
                  value={data.title_color}
                  disabled={!allowed}
                  onChange={(v) => form.setData('title_color', v)}
                />
              </Row>
              <Row id="title_weight" label={t('banners.title_weight')}>
                <Choice
                  id="title_weight"
                  value={data.title_weight}
                  disabled={!allowed}
                  options={(['thin', 'normal', 'bold', 'heavy'] as const).map(
                    (w) => ({ value: w, label: t(bannerLabels.weight[w]) }),
                  )}
                  onChange={(v) => form.setData('title_weight', v)}
                />
              </Row>
              <Row
                id="title_tag"
                label={t('banners.title_tag')}
                help={t('banners.title_tag_help')}
              >
                <Choice
                  id="title_tag"
                  value={data.title_tag}
                  disabled={!allowed}
                  options={(
                    ['div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as const
                  ).map((tag) => ({ value: tag, label: tag }))}
                  onChange={(v) => form.setData('title_tag', v)}
                />
              </Row>
            </>
          )}
          {layoutControl(
            device,
            'title_shadow',
            t('banners.title_shadow'),
            (value, onChange, disabled) => (
              <Check
                id={`${device}-title_shadow`}
                checked={value}
                onChange={onChange}
                disabled={disabled}
              />
            ),
          )}
        </Section>

        <Section title={t('banners.section_description')} iconKey="content">
          {textControl(
            device,
            'description',
            t('banners.description_text'),
            true,
          )}
          {layoutControl(
            device,
            'description_size',
            t('banners.font_size'),
            (value, onChange, disabled) =>
              numberInput(value, onChange, disabled, 6, 200),
          )}
          {device === 'pc' && (
            <>
              <Row
                id="description_color"
                label={t('banners.description_color')}
                help={t('banners.color_auto_help')}
              >
                <ColorField
                  id="description_color"
                  value={data.description_color}
                  disabled={!allowed}
                  onChange={(v) => form.setData('description_color', v)}
                />
              </Row>
              <Row
                id="description_background"
                label={t('banners.description_background')}
              >
                <ColorField
                  id="description_background"
                  value={data.description_background}
                  fallback="#ffffff"
                  disabled={!allowed}
                  onChange={(v) => form.setData('description_background', v)}
                />
              </Row>
            </>
          )}
        </Section>

        <Section title={t('banners.section_object')} iconKey="products">
          {device === 'pc' ? (
            <>
              <Row id="object_type" label={t('banners.object_type')}>
                <Choice
                  id="object_type"
                  value={data.object_type}
                  disabled={!allowed}
                  options={[
                    { value: 'image', label: t('banners.object_image') },
                    { value: 'youtube', label: t('banners.object_youtube') },
                    { value: 'products', label: t('banners.object_products') },
                  ]}
                  onChange={(v) => form.setData('object_type', v)}
                />
              </Row>
              {data.object_type === 'image' &&
                imageControl('pc', 'main_image', t('banners.main_image'))}
              {data.object_type === 'youtube' && (
                <>
                  <Row
                    id={`pc-youtube-${locale}`}
                    label={t('banners.youtube_video')}
                    help={t('banners.youtube_help')}
                    error={errors[`contents.pc.${locale}.youtube_video`]}
                  >
                    <input
                      id={`pc-youtube-${locale}`}
                      className="field max-w-xl"
                      maxLength={200}
                      disabled={!allowed}
                      value={content('pc').youtube_video}
                      onChange={(e) =>
                        setContent({ youtube_video: e.target.value }, 'pc')
                      }
                    />
                  </Row>
                  <Row
                    id="youtube_autoplay"
                    label={t('banners.youtube_autoplay')}
                    help={t('banners.youtube_autoplay_help')}
                  >
                    <Check
                      id="youtube_autoplay"
                      checked={data.youtube_autoplay}
                      disabled={!allowed}
                      onChange={(v) => form.setData('youtube_autoplay', v)}
                    />
                  </Row>
                  <Row
                    id="youtube_hide_controls"
                    label={t('banners.youtube_hide_controls')}
                  >
                    <Check
                      id="youtube_hide_controls"
                      checked={data.youtube_hide_controls}
                      disabled={!allowed}
                      onChange={(v) => form.setData('youtube_hide_controls', v)}
                    />
                  </Row>
                </>
              )}
              {data.object_type === 'products' && (
                <>
                  <Row
                    id="products_template"
                    label={t('banners.products_template')}
                  >
                    <Choice
                      id="products_template"
                      value={data.products_template}
                      disabled={!allowed}
                      options={[
                        { value: 'grid', label: t('banners.template_grid') },
                        { value: 'small', label: t('banners.template_small') },
                        {
                          value: 'thumbnails',
                          label: t('banners.template_thumbnails'),
                        },
                      ]}
                      onChange={(v) => form.setData('products_template', v)}
                    />
                  </Row>
                  <Row
                    id="products_columns"
                    label={t('banners.products_columns')}
                    error={errors.products_columns}
                  >
                    {numberInput(
                      data.products_columns,
                      (v) => form.setData('products_columns', v),
                      !allowed,
                      1,
                      6,
                    )}
                  </Row>
                  {data.products_template === 'small' && (
                    <Row
                      id="products_rows"
                      label={t('banners.products_rows')}
                      error={errors.products_rows}
                    >
                      {numberInput(
                        data.products_rows,
                        (v) => form.setData('products_rows', v),
                        !allowed,
                        1,
                        4,
                      )}
                    </Row>
                  )}
                  <Row
                    id="product-search"
                    label={t('banners.products_select')}
                    error={errors.products}
                  >
                    <ul className="space-y-1.5">
                      {chosen.length === 0 && (
                        <li className="text-sm text-slate-500">
                          {t('banners.products_none')}
                        </li>
                      )}
                      {chosen.map((product) => (
                        <li
                          key={product.id}
                          className="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm"
                        >
                          <span className="min-w-0 flex-1 truncate font-medium">
                            {product.name}
                          </span>
                          {product.sku && (
                            <span className="text-xs text-slate-500">
                              {product.sku}
                            </span>
                          )}
                          {allowed && (
                            <button
                              type="button"
                              className="text-xs text-red-700 underline underline-offset-4"
                              aria-label={t('banners.product_remove', {
                                name: product.name,
                              })}
                              onClick={() => {
                                const next = chosen.filter(
                                  (p) => p.id !== product.id,
                                );
                                setChosen(next);
                                form.setData(
                                  'products',
                                  next.map((p) => p.id),
                                );
                              }}
                            >
                              {t('banners.delete')}
                            </button>
                          )}
                        </li>
                      ))}
                    </ul>
                    {allowed && (
                      <div className="space-y-2 pt-2">
                        <input
                          id="product-search"
                          type="search"
                          className="field max-w-md"
                          placeholder={t('banners.products_search')}
                          value={productSearch}
                          onChange={(e) => setProductSearch(e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                              e.preventDefault();
                              searchProducts(productSearch);
                            }
                          }}
                        />
                        {productSearch && candidates.length === 0 && (
                          <p className="text-xs text-slate-500">
                            {t('banners.products_no_results')}
                          </p>
                        )}
                        <ul className="space-y-1">
                          {candidates
                            .filter((c) => !chosen.some((p) => p.id === c.id))
                            .map((candidate) => (
                              <li key={candidate.id}>
                                <button
                                  type="button"
                                  className="flex w-full max-w-md items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-slate-50"
                                  aria-label={t('banners.product_add', {
                                    name: candidate.name,
                                  })}
                                  onClick={() => {
                                    const next = [...chosen, candidate];
                                    setChosen(next);
                                    form.setData(
                                      'products',
                                      next.map((p) => p.id),
                                    );
                                  }}
                                >
                                  <span aria-hidden="true">+</span>
                                  <span className="truncate">
                                    {candidate.name}
                                  </span>
                                </button>
                              </li>
                            ))}
                        </ul>
                      </div>
                    )}
                  </Row>
                </>
              )}
              <Row id="object_valign" label={t('banners.object_position')}>
                <div className="flex flex-wrap gap-3">
                  <Choice
                    id="object_valign"
                    className="w-48"
                    value={data.object_valign}
                    options={align.v}
                    disabled={!allowed}
                    onChange={(v) => form.setData('object_valign', v)}
                  />
                  <Choice
                    id="object_halign"
                    className="w-48"
                    value={data.object_halign}
                    options={align.h}
                    disabled={!allowed}
                    onChange={(v) => form.setData('object_halign', v)}
                  />
                </div>
              </Row>
            </>
          ) : (
            imageControl(device, 'main_image', t('banners.main_image'))
          )}
        </Section>

        <Section title={t('banners.section_background')} iconKey="media">
          {layoutControl(
            device,
            'background_type',
            t('banners.background_type'),
            (value, onChange, disabled) => (
              <Choice
                id={`${device}-background_type`}
                value={value}
                disabled={disabled}
                options={[
                  { value: 'image', label: t('banners.background_image') },
                  { value: 'video', label: t('banners.background_video') },
                ]}
                onChange={onChange}
              />
            ),
          )}
          {backgroundType === 'image'
            ? imageControl(
                device,
                'background_image',
                t('banners.background_image'),
              )
            : videoControl(device)}
          {layoutControl(
            device,
            'background_size',
            t('banners.background_size'),
            (value, onChange, disabled) => (
              <Choice
                id={`${device}-background_size`}
                value={value}
                disabled={disabled}
                options={[
                  { value: 'cover', label: t('banners.size_cover') },
                  { value: 'contain', label: t('banners.size_contain') },
                ]}
                onChange={onChange}
              />
            ),
          )}
          {device === 'pc' && (
            <Row id="background_color" label={t('banners.background_color')}>
              <ColorField
                id="background_color"
                value={data.background_color}
                fallback="#ffffff"
                disabled={!allowed}
                onChange={(v) => form.setData('background_color', v)}
              />
            </Row>
          )}
          {layoutControl(
            device,
            'background_valign',
            device === 'pc'
              ? t('banners.background_position')
              : t('banners.images_valign'),
            (value, onChange, disabled) => (
              <div className="flex flex-wrap gap-3">
                <Choice
                  id={`${device}-background_valign`}
                  className="w-48"
                  value={value}
                  options={align.v}
                  disabled={disabled}
                  onChange={onChange}
                />
                {device === 'pc' && (
                  <Choice
                    id="background_halign"
                    className="w-48"
                    value={data.background_halign}
                    options={align.h}
                    disabled={!allowed}
                    onChange={(v) => form.setData('background_halign', v)}
                  />
                )}
              </div>
            ),
          )}
        </Section>

        <Section title={t('banners.section_button')} iconKey="external">
          {layoutControl(
            device,
            'button_enabled',
            t('banners.button_enabled'),
            (value, onChange, disabled) => (
              <Check
                id={`${device}-button_enabled`}
                checked={value}
                onChange={onChange}
                disabled={disabled}
              />
            ),
          )}
          {textControl(device, 'button_text', t('banners.button_text'), false)}
          {device === 'pc' && (
            <Row id="button_style" label={t('banners.button_style')}>
              <Choice
                id="button_style"
                value={data.button_style}
                disabled={!allowed}
                options={(['standard', 'outline', 'text'] as const).map(
                  (s) => ({
                    value: s,
                    label: t(bannerLabels.style[s]),
                  }),
                )}
                onChange={(v) => form.setData('button_style', v)}
              />
            </Row>
          )}
          {device === 'pc' && (
            <Row
              id="button_hover"
              label={t('banners.button_hover')}
              help={t('banners.button_hover_help')}
            >
              <Choice
                id="button_hover"
                value={data.button_hover}
                disabled={!allowed}
                options={(
                  [
                    'none',
                    'darken',
                    'lighten',
                    'lift',
                    'grow',
                    'glow',
                    'invert',
                  ] as const
                ).map((h) => ({ value: h, label: t(bannerLabels.hover[h]) }))}
                onChange={(v) => form.setData('button_hover', v)}
              />
            </Row>
          )}
          {device === 'pc' && data.button_hover !== 'none' && (
            <Row
              id="button_transition"
              label={t('banners.button_transition')}
              help={t('banners.button_transition_help')}
              error={errors.button_transition}
            >
              {numberInput(
                data.button_transition,
                (v) => form.setData('button_transition', v),
                !allowed,
                0,
                2,
                0.1,
              )}
            </Row>
          )}
          {layoutControl(
            device,
            'button_text_color',
            t('banners.button_text_color'),
            (value, onChange, disabled) => (
              <ColorField
                id={`${device}-button_text_color`}
                value={value}
                fallback="#ffffff"
                disabled={disabled}
                onChange={onChange}
              />
            ),
          )}
          {layoutControl(
            device,
            'button_color',
            t('banners.button_color'),
            (value, onChange, disabled) => (
              <ColorField
                id={`${device}-button_color`}
                value={value}
                disabled={disabled}
                onChange={onChange}
              />
            ),
          )}
        </Section>

        <Section title={t('banners.section_additional')} iconKey="settings">
          {layoutControl(
            device,
            'mask_type',
            t('banners.mask_type'),
            (value, onChange, disabled) => (
              <Choice
                id={`${device}-mask_type`}
                value={value}
                disabled={disabled}
                options={[
                  { value: 'none', label: t('banners.mask_none') },
                  { value: 'semi', label: t('banners.mask_semi') },
                  { value: 'blur', label: t('banners.mask_blur') },
                  { value: 'gradient', label: t('banners.mask_gradient') },
                  { value: 'color', label: t('banners.mask_color_type') },
                ]}
                onChange={onChange}
              />
            ),
            t('banners.mask_help'),
          )}
          {resolveLayout(pc, device === 'pc' ? null : data.layouts[device])
            .mask_type !== 'none' && (
            <>
              {layoutControl(
                device,
                'mask_position',
                t('banners.mask_position'),
                (value, onChange, disabled) => (
                  <Choice
                    id={`${device}-mask_position`}
                    value={value}
                    disabled={disabled}
                    options={[
                      {
                        value: 'banner',
                        label: t('banners.mask_position_banner'),
                      },
                      {
                        value: 'content',
                        label: t('banners.mask_position_content'),
                      },
                    ]}
                    onChange={onChange}
                  />
                ),
              )}
              {layoutControl(
                device,
                'mask_opacity',
                t('banners.mask_opacity'),
                (value, onChange, disabled) =>
                  numberInput(value, onChange, disabled, 0, 100),
              )}
              {layoutControl(
                device,
                'mask_direction',
                t('banners.mask_direction'),
                (value, onChange, disabled) => (
                  <Choice
                    id={`${device}-mask_direction`}
                    value={value}
                    disabled={disabled}
                    options={(
                      ['auto', 'top', 'bottom', 'left', 'right'] as const
                    ).map((d) => ({
                      value: d,
                      label: t(bannerLabels.direction[d]),
                    }))}
                    onChange={onChange}
                  />
                ),
                t('banners.mask_direction_help'),
              )}
              {layoutControl(
                device,
                'mask_color',
                t('banners.mask_color'),
                (value, onChange, disabled) => (
                  <ColorField
                    id={`${device}-mask_color`}
                    value={value}
                    disabled={disabled}
                    onChange={onChange}
                  />
                ),
              )}
            </>
          )}
          {layoutControl(
            device,
            'css_classes',
            t('banners.css_classes'),
            (value, onChange, disabled) => {
              const list = (value ?? '').split(/\s+/).filter(Boolean);
              return (
                <div className="space-y-2">
                  <input
                    id={`${device}-css_classes`}
                    className="field max-w-xl font-mono text-sm"
                    maxLength={300}
                    value={value ?? ''}
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.value || null)}
                  />
                  <div className="flex flex-wrap gap-1.5">
                    {cssPresets.map((preset) => {
                      const on = list.includes(preset);
                      return (
                        <button
                          key={preset}
                          type="button"
                          aria-pressed={on}
                          disabled={disabled}
                          className={`rounded-full border px-2.5 py-1 text-xs font-medium ${on ? 'border-primary bg-primary-tint text-emerald-900' : 'border-slate-200 text-slate-700 hover:bg-slate-50'} disabled:opacity-50`}
                          onClick={() =>
                            onChange(
                              (on
                                ? list.filter((c) => c !== preset)
                                : [...list, preset]
                              ).join(' ') || null,
                            )
                          }
                        >
                          {t(bannerLabels.css[preset] ?? preset)}
                        </button>
                      );
                    })}
                  </div>
                </div>
              );
            },
            t('banners.css_help'),
          )}
          {device === 'pc' && (
            <>
              <Row
                id="url"
                label={t('banners.url')}
                help={t('banners.url_help')}
                error={errors.url}
              >
                <input
                  id="url"
                  className="field max-w-xl"
                  maxLength={2048}
                  value={data.url ?? ''}
                  disabled={!allowed}
                  onChange={(e) => form.setData('url', e.target.value)}
                />
              </Row>
              <Row id="new_window" label={t('banners.open_mode')}>
                <Choice
                  id="new_window"
                  value={data.new_window ? 'new' : 'same'}
                  disabled={!allowed}
                  options={[
                    { value: 'same', label: t('banners.open_same') },
                    { value: 'new', label: t('banners.open_new') },
                  ]}
                  onChange={(v) => form.setData('new_window', v === 'new')}
                />
              </Row>
              <Row
                id="available"
                label={t('banners.available')}
                help={t('banners.available_help')}
              >
                <Check
                  id="available"
                  checked={data.available}
                  disabled={!allowed}
                  onChange={(v) => form.setData('available', v)}
                />
              </Row>
              {data.available && (
                <>
                  <Row
                    id="available_from"
                    label={t('banners.available_from')}
                    error={errors.available_from}
                  >
                    <input
                      id="available_from"
                      type="datetime-local"
                      className="field max-w-xs"
                      value={data.available_from}
                      disabled={!allowed}
                      onChange={(e) =>
                        form.setData('available_from', e.target.value)
                      }
                    />
                  </Row>
                  <Row
                    id="available_until"
                    label={t('banners.available_until')}
                    error={errors.available_until}
                  >
                    <input
                      id="available_until"
                      type="datetime-local"
                      className="field max-w-xs"
                      value={data.available_until}
                      disabled={!allowed}
                      onChange={(e) =>
                        form.setData('available_until', e.target.value)
                      }
                    />
                    <p className="text-xs text-slate-500">
                      {t('banners.timezone_note', { zone: timeZone })}
                    </p>
                  </Row>
                </>
              )}
              <Row
                id="tablet_custom"
                label={t('banners.tablet_custom')}
                help={t('banners.custom_help')}
              >
                <Check
                  id="tablet_custom"
                  checked={data.tablet_custom}
                  disabled={!allowed}
                  onChange={(v) => form.setData('tablet_custom', v)}
                />
              </Row>
              <Row id="phone_custom" label={t('banners.phone_custom')}>
                <Check
                  id="phone_custom"
                  checked={data.phone_custom}
                  disabled={!allowed}
                  onChange={(v) => form.setData('phone_custom', v)}
                />
              </Row>
              <Row
                id="customer_groups"
                label={t('banners.customer_groups')}
                help={t('banners.customer_groups_help')}
                error={errors.customer_groups}
              >
                <div className="flex flex-wrap gap-x-5 gap-y-1">
                  {customerGroups.map((group) => (
                    <Check
                      key={group.id}
                      id={'customer-group-' + group.id}
                      label={group.name}
                      disabled={!allowed}
                      checked={data.customer_groups.includes(group.id)}
                      onChange={(on) =>
                        form.setData(
                          'customer_groups',
                          on
                            ? [...data.customer_groups, group.id]
                            : data.customer_groups.filter(
                                (g) => g !== group.id,
                              ),
                        )
                      }
                    />
                  ))}
                </div>
              </Row>
            </>
          )}
        </Section>
      </>
    );
  };

  const deleteBanner = () => {
    if (
      banner &&
      window.confirm(
        t('banners.delete_confirm', {
          name: banner.names[defaultLocale] ?? '',
        }),
      )
    )
      router.delete('/admin/banners/' + banner.id, {
        data: { version: banner.version },
      });
  };

  const previewElement = (
    <BannerPreview
      heightScale={previewDevice === 'phone' ? 1 : previewHeight / 100}
      device={previewDevice}
      settings={data}
      layout={preview.layout}
      content={preview.value}
      products={chosen}
    />
  );

  return (
    <AdminLayout
      title={t(banner ? 'banners.edit' : 'banners.create')}
      {...shell}
    >
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          if (allowed) submit();
        }}
        id="admin-banners-form-0"
      >
        <EditorHeader
          image={
            banner?.contents.pc?.[defaultLocale]?.main_image?.url ??
            banner?.contents.pc?.[defaultLocale]?.background_image?.url
          }
          heading={
            banner
              ? data.names[defaultLocale] || t('banners.edit')
              : t('banners.create')
          }
          iconKey="banners"
          backHref="/admin/banners"
          backLabel={t('banners.cancel')}
          status={
            <>
              <div>
                <EditorStatusSelect
                  aria-label={t('banners.status')}
                  value={data.status}
                  disabled={!allowed || form.processing}
                  invalid={!!errors.status}
                  onChange={(value) =>
                    form.setData('status', value as typeof data.status)
                  }
                  options={[
                    { value: 'active', label: t('banners.active') },
                    { value: 'disabled', label: t('banners.disabled') },
                  ]}
                />
                {errors.status && (
                  <p role="alert" className="mt-1 text-xs text-red-700">
                    {errors.status}
                  </p>
                )}
              </div>
            </>
          }
          description={
            <>
              {!allowed && (
                <p className="mt-2 text-sm text-slate-500">
                  {t('banners.read_only')}
                </p>
              )}
            </>
          }
          metadata={
            <>
              {banner && (
                <div className="mt-2 flex items-center gap-2 text-sm text-slate-500">
                  <span>{t('banners.state_banner')}:</span>
                  <StatusBadge tone={stateTone[banner.state]}>
                    {t(bannerLabels.state[banner.state])}
                  </StatusBadge>
                </div>
              )}
            </>
          }
          actions={
            <>
              <div className="flex flex-wrap gap-2">
                {banner && allowed && (
                  <>
                    <button
                      type="button"
                      className="button-secondary"
                      onClick={() =>
                        router.post('/admin/banners/' + banner.id + '/clone')
                      }
                    >
                      {t('banners.clone')}
                    </button>
                  </>
                )}
                {allowed && (
                  <EditorActionButton
                    disabled={form.processing}
                    form="admin-banners-form-0"
                  >
                    {t('banners.save')}
                  </EditorActionButton>
                )}
              </div>
            </>
          }
          menu={[
            banner && allowed && (
              <button
                type="button"
                className="button-secondary text-red-700"
                onClick={deleteBanner}
              >
                {t('banners.delete')}
              </button>
            ),
          ]}
        />

        {(errors.version || errors.contents || errors.names) && (
          <p
            role="alert"
            className="rounded-lg bg-red-50 p-3 text-sm text-red-700"
          >
            {errors.version ?? errors.contents ?? errors.names}
          </p>
        )}

        <section aria-label={t('banners.preview')}>
          <UnifiedCard
            title={t('banners.preview')}
            iconKey="preview"
            action={
              <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1 basis-80"></div>
                <div className="ml-auto flex flex-wrap items-center justify-end gap-3">
                  <div
                    role="group"
                    aria-label={t('banners.preview')}
                    className="inline-flex rounded-lg border border-slate-200 p-0.5"
                  >
                    {(['pc', 'tablet', 'phone'] as const).map((device) => (
                      <button
                        key={device}
                        type="button"
                        aria-pressed={previewDevice === device}
                        className={`rounded-md px-2.5 py-1 text-xs font-semibold ${previewDevice === device ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                        onClick={() => setPreviewDevice(device)}
                      >
                        {t(bannerLabels.device[device])}
                      </button>
                    ))}
                  </div>
                  <label className="flex items-center gap-2 text-xs text-slate-600">
                    {t('banners.preview_height')}
                    <select
                      className="field h-8 w-auto py-0 text-xs"
                      value={previewHeight}
                      disabled={previewDevice === 'phone'}
                      onChange={(e) => {
                        const value = Number(e.target.value);
                        setPreviewHeight(value);
                        try {
                          localStorage.setItem(
                            'banner-preview-height',
                            String(value),
                          );
                        } catch {
                          // The choice is only a convenience.
                        }
                      }}
                    >
                      {PREVIEW_HEIGHTS.map((percent) => (
                        <option key={percent} value={percent}>
                          {percent}%
                        </option>
                      ))}
                    </select>
                  </label>
                </div>
              </div>
            }
            bodyClassName="p-5 "
          >
            <p className="text-xs text-slate-500">
              {t('banners.preview_help')}
            </p>

            {previewElement}
          </UnifiedCard>
        </section>

        <Section title={t('banners.section_general')} iconKey="banners">
          {locales
            .filter((l) => l.code === locale)
            .map((l) => (
              <Row
                key={l.code}
                id={'name-' + l.code}
                label={
                  t('banners.name') + (locales.length > 1 ? ' · ' + l.code : '')
                }
                required={l.code === defaultLocale}
                error={errors['names.' + l.code]}
              >
                <input
                  id={'name-' + l.code}
                  className="field"
                  maxLength={200}
                  disabled={!allowed}
                  value={data.names[l.code] ?? ''}
                  onChange={(e) =>
                    form.setData('names', {
                      ...data.names,
                      [l.code]: e.target.value,
                    })
                  }
                />
              </Row>
            ))}
          <Row id="group_id" label={t('banners.group')} error={errors.group_id}>
            <Choice
              id="group_id"
              value={data.group_id}
              disabled={!allowed}
              options={[
                { value: '', label: t('banners.group_none') },
                ...groups.map((g) => ({ value: g.id, label: g.name })),
              ]}
              onChange={(v) => form.setData('group_id', v)}
            />
          </Row>
          <Row
            id="position"
            label={t('banners.position')}
            error={errors.position}
          >
            <input
              id="position"
              type="number"
              className="field max-w-32"
              value={data.position}
              disabled={!allowed}
              onChange={(e) => form.setData('position', e.target.value)}
            />
          </Row>

          <Row
            id="created_on"
            label={t('banners.created_on')}
            error={errors.created_on}
          >
            <DateInput
              id="created_on"
              className="field max-w-xs"
              value={data.created_on}
              disabled={!allowed}
              onChange={(value) => form.setData('created_on', value)}
            />
          </Row>
        </Section>

        <div>
          <AdminTabs
            label={t('banners.title')}
            className="mb-4"
            language={
              <ContentLanguageSelector
                languages={locales}
                value={locale}
                onChange={setLocale}
                errors={errors}
              />
            }
          >
            {deviceTabs.map((device) => (
              <AdminTab
                key={device}
                type="button"
                role="tab"
                aria-selected={activeTab === device}
                onClick={() => {
                  setTab(device);
                  setPreviewDevice(device);
                }}
                iconKey={device}
              >
                {t(
                  device === 'pc'
                    ? 'banners.tab_general'
                    : bannerLabels.device[device],
                )}
              </AdminTab>
            ))}
            {showAi && (
              <AdminTab
                type="button"
                role="tab"
                aria-selected={activeTab === 'ai'}
                onClick={() => {
                  setTab('ai');
                  setPreviewDevice('pc');
                }}
                iconKey="ai"
              >
                {t('banners.ai_tab')}
              </AdminTab>
            )}
          </AdminTabs>
          {activeTab !== 'pc' && activeTab !== 'ai' && (
            <p className="mb-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
              {t('banners.device_help')}
            </p>
          )}
          {activeTab !== 'ai' && (
            <div role="tabpanel" className="space-y-6">
              {deviceSections(activeTab)}
            </div>
          )}
          {showAi && (
            // Hidden rather than unmounted, so the conversation survives switching tabs.
            <div role="tabpanel" hidden={activeTab !== 'ai'}>
              <AiChat
                endpoint="/admin/banners/generate"
                payload={{ banner_id: banner?.id ?? null }}
                ready={aiReady}
                current={aiDraft}
                onApply={applyAi}
                preview={previewElement}
                label={t('banners.ai_tab')}
                intro={t('banners.ai_intro')}
                placeholder={t('banners.ai_placeholder')}
                consent={t('banners.ai_consent')}
                notConfigured={t('banners.ai_not_configured')}
                examples={[
                  t('banners.ai_example_1'),
                  t('banners.ai_example_2'),
                  t('banners.ai_example_3'),
                ]}
              />
            </div>
          )}
        </div>

        {allowed && (
          <div className="flex justify-end">
            <EditorActionButton disabled={form.processing}>
              {t('banners.save')}
            </EditorActionButton>
          </div>
        )}
      </form>
    </AdminLayout>
  );
}

/** Mirrors the server's YouTube ID extraction for the preview. */
function youtubeId(value: string): string {
  const v = value.trim();
  if (/^[A-Za-z0-9_-]{11}$/.test(v)) return v;
  const match =
    /[?&]v=([A-Za-z0-9_-]{11})/.exec(v) ??
    /youtu\.be\/([A-Za-z0-9_-]{11})/.exec(v) ??
    /youtube\.com\/(?:embed|shorts|live)\/([A-Za-z0-9_-]{11})/.exec(v);
  return match ? match[1] : '';
}
