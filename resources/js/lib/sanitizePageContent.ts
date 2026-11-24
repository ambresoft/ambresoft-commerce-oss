import DOMPurify from 'dompurify';

/** Tags allowed in page content; mirrors the server PageContentSanitizer. */
const TAGS = [
  'p',
  'br',
  'hr',
  'h1',
  'h2',
  'h3',
  'h4',
  'h5',
  'h6',
  'strong',
  'b',
  'em',
  'i',
  'u',
  's',
  'sub',
  'sup',
  'mark',
  'small',
  'code',
  'pre',
  'blockquote',
  'ul',
  'ol',
  'li',
  'span',
  'div',
  'figure',
  'figcaption',
  'a',
  'img',
  'iframe',
  'table',
  'thead',
  'tbody',
  'tfoot',
  'caption',
  'tr',
  'th',
  'td',
  'colgroup',
  'col',
];
const ATTRS = [
  'href',
  'title',
  'target',
  'src',
  'alt',
  'width',
  'height',
  'style',
  'colspan',
  'rowspan',
  'colwidth',
  'span',
  'allowfullscreen',
  'data-youtube-video',
];
const COLOR =
  /^(#[0-9a-f]{3}|#[0-9a-f]{6}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\))$/i;
const STYLES: Record<string, RegExp> = {
  'text-align': /^(left|right|center|justify)$/,
  color: COLOR,
  'background-color': COLOR,
  'min-width': /^\d{1,4}px$/,
  width: /^\d{1,4}(px|%)$/,
};
const YOUTUBE =
  /^https:\/\/www\.youtube(?:-nocookie)?\.com\/embed\/[A-Za-z0-9_-]{11}(?:\?[A-Za-z0-9_=&;%-]*)?$/;

export const isPageLink = (url: string) =>
  /^(?:https?:\/\/|mailto:|tel:|\/(?!\/)|#.)/i.test(url);
export const isPageMedia = (url: string) =>
  /^(?:https?:\/\/|\/(?!\/))/i.test(url);

/** Browser mirror of the server page-content policy; the server result is authoritative on save. */
export function sanitizePageContent(html: string): string {
  const fragment = DOMPurify.sanitize(html, {
    ALLOWED_TAGS: TAGS,
    ALLOWED_ATTR: ATTRS,
    ADD_TAGS: ['iframe'],
    // With a URI pattern DOMPurify checks every non-URL attribute against it too; these are plain values.
    ADD_URI_SAFE_ATTR: [
      'target',
      'alt',
      'title',
      'style',
      'width',
      'height',
      'colspan',
      'rowspan',
      'colwidth',
      'span',
      'allowfullscreen',
      'data-youtube-video',
    ],
    ALLOW_DATA_ATTR: false,
    ALLOW_ARIA_ATTR: false,
    ALLOWED_URI_REGEXP: /^(?:https?:\/\/|mailto:|tel:|\/(?!\/)|#)/i,
    RETURN_DOM_FRAGMENT: true,
  });
  fragment.querySelectorAll('*').forEach((node) => {
    const element = node as HTMLElement;
    const style = element.getAttribute('style');
    if (style !== null) {
      const kept = style
        .split(';')
        .map((part) => part.split(':'))
        .filter(([property, ...value]) => {
          const rule = STYLES[property?.trim().toLowerCase() ?? ''];
          return rule?.test(value.join(':').trim());
        })
        .map(
          ([property, ...value]) =>
            `${property.trim().toLowerCase()}: ${value.join(':').trim().toLowerCase()}`,
        );
      if (kept.length) element.setAttribute('style', kept.join('; '));
      else element.removeAttribute('style');
    }
    const tag = element.tagName;
    if (tag === 'IFRAME' && !YOUTUBE.test(element.getAttribute('src') ?? ''))
      element.remove();
    if (tag === 'IMG' && !isPageMedia(element.getAttribute('src') ?? ''))
      element.remove();
    if (tag === 'A') {
      if (element.getAttribute('target') === '_blank')
        element.setAttribute('rel', 'noopener noreferrer');
      else element.removeAttribute('target');
    }
  });
  const box = document.createElement('div');
  box.appendChild(fragment);
  return box.innerHTML;
}
