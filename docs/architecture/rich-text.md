# Shared admin rich-text editor

The authorized 2026-10-10 follow-up replaces the native `execCommand` product editor with free MIT-licensed Tiptap/ProseMirror. `resources/js/components/admin/RichText.tsx` is the shared editor for Product and Category descriptions, Feature definitions, Feature Groups and option landing descriptions. Reuse it for future rich-body fields. Names, SEO metadata, age warnings and product Text feature values remain plain text; do not render them as HTML.

Visual mode offers bold, italic, underline, strike, heading levels 2/3, unordered/ordered lists, quotes, code blocks, links, undo and redo. HTML mode is an escaped textarea for supported markup, not an executable page builder. It allows direct HTML authoring and preserves raw source while editing, while sending sanitized HTML into form state immediately. Switching to Visual parses and normalizes supported HTML; unsupported tags/attributes are removed. Empty visual content remains empty. Locale keys, parent form state, record permissions and transaction/version handling remain authoritative. External value changes synchronize safely without generating an edit on mount. Read-only and pending states also disable the contenteditable body, not just native form controls.

## Security boundary

Every browser HTML ingestion path uses `resources/js/lib/sanitizeRichText.ts` (DOMPurify) before editor parsing: initial/external values, HTML mode and pasted HTML. Tiptap supplies a restrictive document schema and validates link URIs. Link navigation is disabled in the editor; creating links accepts only http, https and mailto. No scripts, event handlers, SVG/MathML, style attributes, images, remote resources, embeds, forms, IDs, names, data attributes or custom tags are supported. There are no remote editing/cloud/AI services or editor upload endpoints.

Every save uses `App\Shared\Infrastructure\Content\RichTextSanitizer`, wrapping the installed Symfony HTML Sanitizer. It is called by Product translations, Category normalization (including CSV import) and Feature translation persistence (definitions, groups and variants). Browser sanitization is defense in depth and cannot replace this server control. Product descriptions remain bounded at 100,000 characters, Categories at 20,000, Features/Groups/variants at 10,000; the shared parser caps input at 400,000 bytes to accommodate four-byte Unicode within the largest validated field. Overlong category input is left for the existing length validator to reject before parsing.

The shared HTML allowlist is p, br, strong/b, em/i, u, s, ul, ol, li, blockquote, h2, h3, pre, code and a. Only a[href,title] is permitted, with http/https/mailto schemes and no relative URLs. Browser and server policies must evolve together. Sanitizing already saved legacy content on edit does not justify directly rendering unsanitized database HTML elsewhere: future rendering must use the same sanitizer. Preserve ordinary escaping for all other strings. Do not mutate sanitized HTML with untrusted markup afterward.

DOMPurify is maintained by Cure53 security specialists and recommended by OWASP for HTML sanitization. This establishes the library selection, not an independent cybersecurity certification or audit of this CMS. The implementation is covered by executable security tests and advisory scans. Keep dependencies patched; a clean advisory scan is point-in-time evidence, not a guarantee against all future vulnerabilities.

## Dependencies and references

Installed Tiptap React/PM/StarterKit 3.31.4 and DOMPurify 3.4.16 are locked in pnpm-lock.yaml. Tiptap core is MIT licensed; DOMPurify offers Apache-2.0 or MPL-2.0. Symfony HTML Sanitizer remains the existing MIT dependency. The CMS retains its proprietary license; preserve the dependencies' own license notices when distributing builds.

- [Tiptap open-source licensing](https://tiptap.dev/open-source-to-platform)
- [Official React integration](https://tiptap.dev/docs/editor/getting-started/install/react)
- [DOMPurify project and threat model](https://github.com/cure53/DOMPurify)
- [OWASP XSS prevention and HTML sanitization guidance](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html)
- [Symfony HTML Sanitizer](https://symfony.com/doc/current/html_sanitizer.html)

The new editor UI dictionary is English/Greek; other enabled UI languages explicitly fall back to English. Merchant content still follows active CMS locales without translation copying. Existing product General now orders Name first, SKU/EAN in one row, Type, Description, physical measurements and Returnable last.

## Verification on 2026-10-10

`make check` passed: 247 backend tests (20,505 assertions), 68 frontend tests, Pint, PHPStan, TypeScript, ESLint, Prettier and the Vite production build. Security tests cover malicious source, unsafe links, event handlers, SVG/MathML, embeds/forms, CSS and DOM-clobbering attributes, plus preservation of safe formatting and localized content. Product, Category and Feature save paths and category CSV imports are covered by integration tests.

`pnpm audit --prod` reported no known vulnerabilities; `composer audit` reported no security advisories. A read-only Chrome review confirmed the product General field order, Visual/HTML switching without generating an untouched edit or undo entry, and no captured console warnings/errors. No existing product was saved during that review. This change requires no new migration.
