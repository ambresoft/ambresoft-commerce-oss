import DOMPurify from 'dompurify';
import type {
  ContentDraft,
  Device,
  Layout,
  LayoutDraft,
  MediaSlot,
  StoredContent,
  StoredMedia,
} from '../types/banners';

export const devices: Device[] = ['pc', 'tablet', 'phone'];
export const mediaSlots: MediaSlot[] = [
  'main_image',
  'background_image',
  'background_video',
];

const TITLE_COLOR =
  /^\s*color\s*:\s*(#[0-9a-f]{3}|#[0-9a-f]{6}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\))\s*;?\s*$/i;

/**
 * Browser mirror of the server title policy (BannerTitleSanitizer): a few
 * inline tags, spans only with a colour or the above-title class. The server
 * result is authoritative on save.
 */
export function sanitizeBannerTitle(html: string): string {
  const clean = DOMPurify.sanitize(html, {
    ALLOWED_TAGS: ['i', 'b', 'strong', 'em', 'mark', 'small', 'br', 'span'],
    ALLOWED_ATTR: ['class', 'style'],
    ALLOW_DATA_ATTR: false,
    ALLOW_ARIA_ATTR: false,
    RETURN_DOM_FRAGMENT: true,
  });
  clean.querySelectorAll('*').forEach((node) => {
    const element = node as HTMLElement;
    const style = element.getAttribute('style');
    const keepClass =
      element.tagName === 'SPAN' &&
      element.getAttribute('class') === 'banner-title-above';
    const color =
      element.tagName === 'SPAN' && style ? TITLE_COLOR.exec(style) : null;
    element.removeAttribute('class');
    element.removeAttribute('style');
    if (keepClass) element.setAttribute('class', 'banner-title-above');
    if (color)
      element.setAttribute(
        'style',
        'color: ' + color[1].replace(/\s+/g, '').toLowerCase(),
      );
  });
  const box = document.createElement('div');
  box.appendChild(clean);
  return box.innerHTML;
}

export const emptyContent = (stored?: StoredContent | null): ContentDraft => ({
  title: stored?.title ?? '',
  description: stored?.description ?? '',
  button_text: stored?.button_text ?? '',
  youtube_video: stored?.youtube_video ?? '',
  main_image: null,
  background_image: null,
  background_video: null,
  remove_main_image: false,
  remove_background_image: false,
  remove_background_video: false,
});

export const emptyOverrides = (stored?: LayoutDraft | null): LayoutDraft => ({
  valign: null,
  halign: null,
  paddings: null,
  full_width: null,
  title_size: null,
  title_shadow: null,
  description_size: null,
  background_type: null,
  background_size: null,
  background_valign: null,
  button_enabled: null,
  button_text_color: null,
  button_color: null,
  mask_type: null,
  mask_position: null,
  mask_opacity: null,
  mask_direction: null,
  mask_color: null,
  css_classes: null,
  ...(stored ?? {}),
});

/** PC layout with the device's non-null overrides applied. */
export function resolveLayout(
  pc: Layout,
  overrides: LayoutDraft | null,
): Layout {
  if (!overrides) return pc;
  const result = { ...pc } as Record<string, unknown>;
  for (const [key, value] of Object.entries(overrides))
    if (value !== null && value !== '') result[key] = value;
  return result as Layout;
}

export type ResolvedMedia = { url: string; video?: boolean } | null;

/** The media that will show for a slot: a newly chosen file, the stored one, or nothing. */
export function mediaFor(
  draft: ContentDraft | undefined,
  stored: StoredContent | undefined,
  slot: MediaSlot,
  objectUrl: (file: File) => string,
): ResolvedMedia | undefined {
  const file = draft?.[slot];
  if (file) return { url: objectUrl(file), video: slot === 'background_video' };
  if (draft?.[`remove_${slot}`]) return null;
  const current = stored?.[slot] as StoredMedia | null | undefined;
  if (current) return { url: current.url, video: slot === 'background_video' };
  return undefined;
}

export const bannerTitleWeight = {
  thin: 300,
  normal: 400,
  bold: 700,
  heavy: 900,
} as const;
