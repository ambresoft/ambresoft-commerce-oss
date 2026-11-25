/** Readable plain text from editor HTML for AI context and previews: block ends become line breaks, nothing runs. */
export function htmlToText(html: string): string {
  const marked = html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|h[1-6]|li|blockquote)>/gi, '$&\n\n');
  const text =
    new DOMParser().parseFromString(marked, 'text/html').body.textContent ?? '';
  return text.replace(/\n{3,}/g, '\n\n').trim();
}
