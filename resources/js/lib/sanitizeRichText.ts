import DOMPurify from 'dompurify';

export const richTextTags = [
  'p',
  'br',
  'strong',
  'b',
  'em',
  'i',
  'u',
  's',
  'ul',
  'ol',
  'li',
  'blockquote',
  'h2',
  'h3',
  'pre',
  'code',
  'a',
];
export const isRichTextLink = (url: string) =>
  /^(?:https?:\/\/|mailto:)/i.test(url);

// Only this policy may introduce merchant HTML into the browser editor.
// Symfony's matching server policy remains authoritative on every save.
export function sanitizeRichText(html: string): string {
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: richTextTags,
    ALLOWED_ATTR: ['href', 'title'],
    ALLOW_DATA_ATTR: false,
    ALLOW_ARIA_ATTR: false,
    ALLOWED_URI_REGEXP: /^(?:https?:\/\/|mailto:)/i,
  });
}
