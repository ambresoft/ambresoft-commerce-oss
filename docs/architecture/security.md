# Security foundation

Phase 1 supplies browser authentication; Phase 2 replaces its interim allowlist with persisted RBAC and adds trusted locale selection. See the [authentication security review](authentication.md), [authorization safeguards](authorization.md) and [localization boundaries](localization.md). Apply these rules to current and later authorized work.

| Area            | Rule                                                                                                                                                                                 |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| CSRF            | Retain Laravel web request-forgery protection and Inertia cookie/token behavior. Never broadly exempt admin mutations; signed provider callbacks need a separate reviewed contract   |
| Authorization   | Deny by default; policies/gates on pages and mutations, each bulk target and module action. Customer identity does not imply admin access                                            |
| Validation      | Form Requests validate types, lengths, enums, relationship ownership and allowlisted filters; database constraints remain final integrity guard                                      |
| Mass assignment | Explicit allowed persisted fields from validated input; no request all(), unbounded fill or frontend-provided owner/price/permission authority                                       |
| Session         | Redis, HttpOnly, SameSite=Lax, secure cookies under production HTTPS; rotate on login/privilege change and invalidate on logout/revocation when Phase 1 exists                       |
| Passwords       | Laravel hashing/recovery primitives only, never plaintext/reversible storage/logging; rate-limit login/recovery when added                                                           |
| Uploads         | Validate actual MIME/size/dimensions; generated storage keys, private disk first; reject executable content, review SVG and remote fetch SSRF; authorized delivery/derivatives later |
| XSS             | React escaping/Blade escaping by default; sanitize rich text with an allowlist before any deliberate HTML rendering                                                                  |
| SQL             | Parameter binding; never interpolate user sort/column identifiers without an allowlist                                                                                               |
| Limits          | Define endpoint/actor appropriate limits for public reads, uploads, expensive bulk tasks and future auth, no generic unexplained global throttle                                     |
| Secrets         | Ignore .env/keys; .env.example has disposable development defaults only. Never include secrets/payment payloads in props/logs; use production secret management and encryption       |
| Modules         | Treat installed PHP/JS as trusted executable code; no browser code upload/marketplace install in Phase 0                                                                             |
| Deployment      | APP_DEBUG=false, HTTPS, secure cookies, unique APP_KEY/credentials, restricted database/Redis networks and non-development runtime before production                                 |

Docker Compose is a local developer setup bound to loopback, not production hardening. PostgreSQL/Redis ports are not published. Do not reuse development database passwords in production. Never log full requests, cookies or credentials. Audit dependency advisories and preserve third-party license notices. See [observability](observability.md) for redaction/history.

Inertia's HTTP prop-recording DevTools are explicitly disabled, including locally. SSR is also disabled for the neutral foundation; future storefront SSR remains a separate decision.

Rich-text controls: [shared editor and sanitization policy](rich-text.md). Tiptap/ProseMirror supplies editing, DOMPurify sanitizes browser ingress, and Symfony HTML Sanitizer enforces the matching server allowlist for each supported description save/import. HTML source mode never executes source. Library provenance and test results do not constitute external cybersecurity certification of this CMS.
