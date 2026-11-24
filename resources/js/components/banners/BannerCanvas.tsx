import { createElement, type CSSProperties } from 'react';
import { bannerTitleWeight, sanitizeBannerTitle } from '../../lib/banners';
import type {
  Device,
  Layout,
  ProductOption,
  Settings,
} from '../../types/banners';

/** Design canvas per device; the preview scales it to the available width. */
export const canvas: Record<Device, { width: number; height: number }> = {
  pc: { width: 1200, height: 450 },
  tablet: { width: 768, height: 420 },
  phone: { width: 375, height: 520 },
};

export type PreviewContent = {
  title: string;
  description: string;
  button_text: string;
  youtube_video: string;
  main_image: string | null;
  background_image: string | null;
  background_video: string | null;
};

const flex = {
  top: 'flex-start',
  center: 'center',
  bottom: 'flex-end',
  left: 'flex-start',
  right: 'flex-end',
} as const;

function rgba(hex: string, alpha: number) {
  const value = parseInt(hex.slice(1), 16);
  return `rgba(${(value >> 16) & 255}, ${(value >> 8) & 255}, ${value & 255}, ${alpha})`;
}

function maskStyle(layout: Layout, settings: Settings): CSSProperties | null {
  if (layout.mask_type === 'none') return null;
  // Transparency level: 100% is fully see-through.
  const alpha = Math.max(0, Math.min(1, (100 - layout.mask_opacity) / 100));
  const base =
    layout.mask_type === 'color' && layout.mask_color
      ? layout.mask_color
      : settings.color_scheme === 'dark'
        ? '#000000'
        : '#ffffff';
  if (layout.mask_type === 'gradient') {
    const auto =
      layout.halign === 'left'
        ? 'right'
        : layout.halign === 'right'
          ? 'left'
          : layout.valign === 'bottom'
            ? 'top'
            : 'bottom';
    const direction =
      layout.mask_direction === 'auto' ? auto : layout.mask_direction;
    return {
      backgroundImage: `linear-gradient(to ${direction}, ${rgba(base, alpha)} 0%, ${rgba(base, alpha * 0.6)} 45%, ${rgba(base, 0)} 100%)`,
    };
  }
  return {
    backgroundColor: rgba(base, alpha),
    ...(layout.mask_type === 'blur'
      ? { backdropFilter: 'blur(6px)', WebkitBackdropFilter: 'blur(6px)' }
      : {}),
  };
}

function ProductsObject({
  products,
  settings,
}: {
  products: ProductOption[];
  settings: Settings;
}) {
  const visible = products.slice(
    0,
    settings.products_columns *
      (settings.products_template === 'small' ? settings.products_rows : 1),
  );
  if (visible.length === 0) return null;
  const small = settings.products_template === 'small';
  const thumbs = settings.products_template === 'thumbnails';
  return (
    <div
      className="grid w-full gap-3"
      style={{
        gridTemplateColumns: `repeat(${Math.min(settings.products_columns, Math.max(visible.length, 1))}, minmax(0, 1fr))`,
      }}
    >
      {visible.map((product) => (
        <div
          key={product.id}
          className={`overflow-hidden rounded-lg bg-white/90 text-slate-900 shadow-sm ${small ? 'flex items-center gap-2 p-2' : 'p-2'}`}
        >
          <div
            className={`${small ? 'size-10 shrink-0' : 'aspect-square w-full'} overflow-hidden rounded bg-slate-100`}
          >
            {product.image && (
              <img
                src={product.image}
                alt=""
                className="size-full object-cover"
              />
            )}
          </div>
          {!thumbs && (
            <p
              className={`truncate text-xs font-medium ${small ? '' : 'mt-2'}`}
            >
              {product.name}
            </p>
          )}
        </div>
      ))}
    </div>
  );
}

export function BannerCanvas({
  device,
  settings,
  layout,
  content,
  products,
  link,
  priority = 'normal',
  heightScale = 1,
}: {
  device: Device;
  settings: Settings;
  layout: Layout;
  content: PreviewContent;
  products: ProductOption[];
  /** Public iframe banners need a real CTA instead of a link around the whole canvas. */
  link?: { url: string; new_window: boolean };
  /** Public pages: 'high' loads at once, 'low' waits until the banner is near the screen. */
  priority?: 'high' | 'normal' | 'low';
  /** Public pages: a taller canvas (1.5 = half as tall again) gives the design more room at the same width. */
  heightScale?: number;
}) {
  const width = canvas[device].width;
  const height = Math.round(canvas[device].height * heightScale);
  const classes = new Set(
    (layout.css_classes ?? '').split(/\s+/).filter(Boolean),
  );
  const dark = settings.color_scheme === 'dark';
  const ink = dark ? '#ffffff' : '#111827';
  const narrow = device === 'phone';
  const stacked = layout.full_width || layout.halign === 'center' || narrow;
  const imageWidth = classes.has('banner-image-width');
  const objectFirst = stacked
    ? !(narrow && classes.has('banner-vertical-reverse')) && !layout.full_width
    : (layout.halign === 'right') !== imageWidth;
  const mask = maskStyle(layout, settings);
  const shadow = layout.title_shadow ? '0 2px 6px rgba(0,0,0,0.45)' : undefined;
  const buttonBg = layout.button_color ?? ink;
  const buttonInk =
    layout.button_text_color ??
    (layout.button_color ? '#ffffff' : dark ? '#111827' : '#ffffff');
  const hasObject =
    (settings.object_type === 'image' && content.main_image) ||
    (settings.object_type === 'youtube' && content.youtube_video) ||
    (settings.object_type === 'products' && products.length > 0);
  const half = stacked || imageWidth ? undefined : '50%';
  const title = content.title.trim();
  // The button grows with the description text (14px and under keep the classic size), its padding and corners with it.
  const buttonSize = Math.max(14, Math.round(layout.description_size * 0.9));

  const object = hasObject ? (
    <div
      className="flex min-h-0 min-w-0"
      style={{
        flex: stacked ? '1 1 0' : '0 0 auto',
        width: half,
        justifyContent: flex[settings.object_halign],
        alignItems: flex[settings.object_valign],
        margin: classes.has('banner-image-edge')
          ? '-' + layout.paddings.split(' ').join(' -')
          : undefined,
      }}
    >
      {settings.object_type === 'image' && content.main_image && (
        <img
          src={content.main_image}
          alt=""
          loading={priority === 'low' ? 'lazy' : 'eager'}
          fetchPriority={priority === 'high' ? 'high' : undefined}
          className="max-h-full max-w-full object-contain"
          style={{
            transform:
              classes.has('banner-enlarge-image') && device === 'pc'
                ? 'scale(1.15)'
                : undefined,
          }}
        />
      )}
      {settings.object_type === 'youtube' && content.youtube_video && (
        <iframe
          title="YouTube"
          className="aspect-video w-full max-w-full rounded"
          src={`https://www.youtube-nocookie.com/embed/${encodeURIComponent(content.youtube_video)}?mute=1&autoplay=${settings.youtube_autoplay ? 1 : 0}&controls=${settings.youtube_hide_controls ? 0 : 1}`}
          allow="autoplay; encrypted-media; picture-in-picture"
          referrerPolicy="strict-origin-when-cross-origin"
        />
      )}
      {settings.object_type === 'products' && (
        <ProductsObject products={products} settings={settings} />
      )}
    </div>
  ) : null;

  const contentBox = (
    <div
      className="relative flex min-w-0 flex-col"
      style={{
        width: layout.full_width
          ? '100%'
          : stacked
            ? narrow
              ? '100%'
              : '50%'
            : half,
        flex: stacked ? '0 0 auto' : imageWidth ? '1 1 0' : undefined,
        height: stacked ? undefined : '100%',
        justifyContent: flex[layout.valign],
        alignItems: flex[layout.halign],
        textAlign: layout.halign,
        alignSelf: stacked ? flex[layout.halign] : undefined,
      }}
    >
      {mask && layout.mask_position === 'content' && (
        <div
          aria-hidden="true"
          className="absolute rounded-lg"
          style={{
            ...mask,
            inset: classes.has('banner-mask-indent') ? -16 : -8,
          }}
        />
      )}
      <div
        className="relative flex flex-col gap-3"
        style={{
          alignItems: flex[layout.halign],
          height: classes.has('banner-button-bottom') ? '100%' : undefined,
        }}
      >
        {title &&
          createElement(settings.title_tag, {
            className: 'banner-title leading-tight',
            style: {
              fontSize: layout.title_size,
              color: settings.title_color ?? ink,
              fontWeight: bannerTitleWeight[settings.title_weight],
              textShadow: shadow,
            },
            dangerouslySetInnerHTML: { __html: sanitizeBannerTitle(title) },
          })}
        {content.description.trim() && (
          <p
            className="whitespace-pre-line"
            style={{
              fontSize: layout.description_size,
              color:
                settings.description_color ??
                (dark ? 'rgba(255,255,255,0.85)' : '#374151'),
              backgroundColor: settings.description_background ?? undefined,
              padding: settings.description_background ? '4px 8px' : undefined,
            }}
          >
            {content.description}
          </p>
        )}
        {layout.button_enabled &&
          content.button_text.trim() &&
          createElement(
            link ? 'a' : 'span',
            {
              ...(link
                ? {
                    href: link.url,
                    target: link.new_window ? '_blank' : undefined,
                    rel: link.new_window ? 'noopener noreferrer' : undefined,
                  }
                : {}),
              className: `banner-button banner-button-${settings.button_hover ?? 'none'} banner-button-${settings.button_style} inline-block font-semibold`,
              style: {
                // The hover rules (app.css) read these: the fill, the text on it and the change's duration.
                ['--bb-bg' as string]: buttonBg,
                ['--bb-ink' as string]: buttonInk,
                ['--bb-t' as string]: `${settings.button_transition ?? 0.3}s`,
                marginTop: classes.has('banner-button-bottom') ? 'auto' : 4,
                fontSize: buttonSize,
                lineHeight: 1.4,
                padding: '0.7em 1.4em',
                borderRadius: '0.45em',
                ...(settings.button_style === 'standard'
                  ? { backgroundColor: buttonBg, color: buttonInk }
                  : settings.button_style === 'outline'
                    ? {
                        border: `${Math.max(2, Math.round(buttonSize / 8))}px solid ${buttonBg}`,
                        color: layout.button_text_color ?? buttonBg,
                      }
                    : {
                        color: layout.button_text_color ?? buttonBg,
                        textDecoration: 'underline',
                        padding: 0,
                      }),
              },
            },
            content.button_text,
          )}
      </div>
    </div>
  );

  return (
    <div
      className={['banner-preview relative overflow-hidden', ...classes].join(
        ' ',
      )}
      style={{
        width,
        height,
        backgroundColor:
          settings.background_color ?? (dark ? '#1f2937' : '#f3f4f6'),
      }}
    >
      {layout.background_type === 'video' && content.background_video ? (
        <video
          className="absolute inset-0 size-full object-cover"
          src={content.background_video}
          muted
          loop
          autoPlay
          playsInline
        />
      ) : (
        content.background_image &&
        priority !== 'low' && (
          <div
            aria-hidden="true"
            className="absolute inset-0"
            style={{
              backgroundImage: `url("${content.background_image.replace(/"/g, '%22')}")`,
              backgroundSize: layout.background_size,
              backgroundRepeat: 'no-repeat',
              backgroundPosition: `${settings.background_halign} ${layout.background_valign}`,
            }}
          />
        )
      )}
      {classes.has('banner-background-fade') && (
        <div
          aria-hidden="true"
          className="absolute inset-0"
          style={{
            backgroundImage: `radial-gradient(ellipse at ${settings.background_halign} ${layout.background_valign}, transparent 30%, ${dark ? 'rgba(0,0,0,0.55)' : 'rgba(255,255,255,0.6)'} 100%)`,
          }}
        />
      )}
      {mask && layout.mask_position === 'banner' && (
        <div aria-hidden="true" className="absolute inset-0" style={mask} />
      )}
      <div
        className="absolute inset-0 flex gap-6"
        style={{
          padding: layout.paddings,
          flexDirection: stacked ? 'column' : 'row',
          justifyContent: stacked ? flex[layout.valign] : flex[layout.halign],
          alignItems: stacked ? 'stretch' : 'stretch',
        }}
      >
        {objectFirst ? (
          <>
            {object}
            {contentBox}
          </>
        ) : (
          <>
            {contentBox}
            {object}
          </>
        )}
      </div>
    </div>
  );
}
