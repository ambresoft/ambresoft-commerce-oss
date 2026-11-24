import { usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import {
  CONSENT_COOKIE,
  readConsent,
  writeConsent,
  type ConsentChoice,
  type ConsentState,
} from '../../../../../resources/js/storefront/consent';
import { markTagsLoaded } from '../../../../../resources/js/storefront/tags';

type Cookie = {
  name: string;
  provider: string;
  purpose: string;
  expiry: string;
};
type Category = {
  key: 'necessary' | keyof ConsentState;
  name: string;
  description: string;
  cookies: Cookie[];
};
export type ConsentConfig = {
  version: number;
  months: number;
  initialCategories?: ConsentState;
  privacyUrl: string | null;
  reopen: boolean;
  recordUrl: string;
  texts: Record<string, string>;
  categories: Category[];
  scripts: { id: number; category: keyof ConsentState; code: string }[];
};
type Tab = 'consent' | 'details' | 'about';
type Action = 'accept_all' | 'reject_all' | 'custom';

const ALL: ConsentState = {
  preferences: true,
  statistics: true,
  marketing: true,
};
const NONE: ConsentState = {
  preferences: false,
  statistics: false,
  marketing: false,
};
/** Opens the settings from anywhere, e.g. a footer link: window.dispatchEvent(new Event('cms:consent-open')). */
export const OPEN_EVENT = 'cms:consent-open';

const csrf = () =>
  decodeURIComponent(
    document.cookie
      .split('; ')
      .find((row) => row.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length) ?? '',
  );

/** Runs a vendor snippet once per document; scripts inserted as HTML would never execute. */
function runScript(id: number, code: string) {
  const ran = ((
    window as unknown as { __cmsConsentScripts?: Set<number> }
  ).__cmsConsentScripts ??= new Set<number>());
  if (ran.has(id)) return;
  ran.add(id);
  markTagsLoaded();
  const template = document.createElement('template');
  template.innerHTML = code;
  for (const node of Array.from(template.content.childNodes)) {
    if (node instanceof HTMLScriptElement) {
      const script = document.createElement('script');
      for (const attr of Array.from(node.attributes))
        script.setAttribute(attr.name, attr.value);
      script.text = node.text;
      script.dataset.consentScript = String(id);
      document.head.appendChild(script);
    } else if (node.nodeType === Node.ELEMENT_NODE) {
      document.body.appendChild(node);
    }
  }
}

/** Deletes the declared cookies of withdrawn categories, on this host and its parent domains. */
function deleteCookies(names: string[]) {
  const present = document.cookie
    .split('; ')
    .map((c) => c.split('=')[0])
    .filter(Boolean);
  const parts = location.hostname.split('.');
  const domains = [
    '',
    ...parts
      .slice(0, -1)
      .map((_, i) => '; domain=.' + parts.slice(i).join('.')),
  ];
  for (const declared of names) {
    const matches = declared.endsWith('*')
      ? present.filter((n) => n.startsWith(declared.slice(0, -1)))
      : present.filter((n) => n === declared);
    for (const name of matches) {
      if (name === CONSENT_COOKIE) continue;
      for (const domain of domains)
        document.cookie = `${name}=; path=/; max-age=0${domain}`;
    }
  }
}

function Switch({
  checked,
  disabled,
  label,
  onChange,
}: {
  checked: boolean;
  disabled?: boolean;
  label: string;
  onChange?: (value: boolean) => void;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      className={'gdpr-switch' + (checked ? ' is-on' : '')}
      onClick={() => onChange?.(!checked)}
    >
      <span aria-hidden="true" />
    </button>
  );
}

function CookieIcon() {
  return (
    <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true">
      <path
        fill="currentColor"
        d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5Zm-3.5 7a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm6 6a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm-7 1a1 1 0 1 1 0 2 1 1 0 0 1 0-2Z"
      />
    </svg>
  );
}

const paragraphs = (text: string) =>
  text
    .split(/\n{2,}/)
    .filter((p) => p.trim() !== '')
    .map((p, i) => <p key={i}>{p}</p>);

/**
 * The GDPR cookie banner (A149). Accept and reject are equally easy and look alike; nothing optional runs before a
 * choice; the cookie button reopens the settings so consent is withdrawn as easily as it was given.
 */
export default function Banner() {
  const { storefrontConsent: config = null } = usePage<{
    storefrontConsent?: ConsentConfig | null;
  }>().props;
  const [choice, setChoice] = useState<ConsentChoice | null>(() =>
    config ? readConsent(config) : null,
  );
  const [open, setOpen] = useState<null | Tab>(null);
  const [draft, setDraft] = useState<ConsentState>(
    choice?.state ?? config?.initialCategories ?? NONE,
  );
  const [expanded, setExpanded] = useState<string | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);
  const titleId = useId();

  // Consented scripts of this page; private pages receive none from the server.
  useEffect(() => {
    if (!config || !choice) return;
    for (const s of config.scripts)
      if (choice.state[s.category]) runScript(s.id, s.code);
  }, [config, choice]);

  useEffect(() => {
    const show = () => {
      setDraft(choice?.state ?? config?.initialCategories ?? NONE);
      setOpen('details');
    };
    window.addEventListener(OPEN_EVENT, show);
    return () => window.removeEventListener(OPEN_EVENT, show);
  }, [choice, config]);

  useEffect(() => {
    const el = dialog.current;
    if (open && el && !el.open) el.showModal();
    if (!open && el?.open) el.close();
  }, [open]);

  if (!config) return null;
  const t = (key: string) => config.texts[key] ?? key;

  const decide = (state: ConsentState, action: Action) => {
    const previous = choice?.state;
    const next = writeConsent(config, state, choice?.id);
    void fetch(config.recordUrl, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': csrf(),
      },
      body: JSON.stringify({
        consent_id: next.id,
        version: config.version,
        ...state,
        action,
      }),
    }).catch(() => undefined);
    const withdrawn = config.categories.filter(
      (c) => c.key !== 'necessary' && previous?.[c.key] && !state[c.key],
    );
    if (withdrawn.length > 0) {
      deleteCookies(withdrawn.flatMap((c) => c.cookies.map((k) => k.name)));
      // Scripts already running cannot be unloaded: a fresh page starts without them.
      window.location.reload();
      return;
    }
    setChoice(next);
    setOpen(null);
  };

  const actions = (
    <div className="gdpr-actions">
      <button
        type="button"
        className="gdpr-button gdpr-choice"
        onClick={() => decide(NONE, 'reject_all')}
      >
        {t('reject_all')}
      </button>
      {open === 'details' ? (
        <button
          type="button"
          className="gdpr-button gdpr-secondary"
          onClick={() => decide(draft, 'custom')}
        >
          {t('save')}
        </button>
      ) : (
        <button
          type="button"
          className="gdpr-button gdpr-secondary"
          onClick={() => {
            setDraft(choice?.state ?? config?.initialCategories ?? NONE);
            setOpen('details');
          }}
        >
          {t('settings')} <span aria-hidden="true">›</span>
        </button>
      )}
      <button
        type="button"
        className="gdpr-button gdpr-choice"
        onClick={() => decide(ALL, 'accept_all')}
      >
        {t('accept_all')}
      </button>
    </div>
  );

  const privacy = config.privacyUrl && (
    <a className="gdpr-link" href={config.privacyUrl}>
      {t('privacy_link')}
    </a>
  );

  return (
    <>
      {!choice && !open && (
        <section
          className="gdpr-banner"
          role="region"
          aria-labelledby={titleId}
        >
          <span className="gdpr-icon">
            <CookieIcon />
          </span>
          <div className="gdpr-banner-text">
            <h2 id={titleId}>{t('title')}</h2>
            {paragraphs(t('body'))}
            {privacy}
          </div>
          <div className="gdpr-banner-actions">
            <button
              type="button"
              className="gdpr-button gdpr-choice"
              onClick={() => decide(ALL, 'accept_all')}
            >
              {t('accept_all')}
            </button>
            <button
              type="button"
              className="gdpr-button gdpr-secondary"
              onClick={() => {
                setDraft(config.initialCategories ?? NONE);
                setOpen('details');
              }}
            >
              {t('settings')} <span aria-hidden="true">›</span>
            </button>
            <button
              type="button"
              className="gdpr-button gdpr-choice"
              onClick={() => decide(NONE, 'reject_all')}
            >
              {t('reject_all')}
            </button>
          </div>
        </section>
      )}

      {choice && config.reopen && !open && (
        <button
          type="button"
          className="gdpr-reopen"
          aria-label={t('reopen')}
          title={t('reopen')}
          onClick={() => {
            setDraft(choice.state);
            setOpen('details');
          }}
        >
          <CookieIcon />
        </button>
      )}

      <dialog
        ref={dialog}
        className="gdpr-dialog"
        aria-label={t('dialog')}
        onCancel={(e) => {
          e.preventDefault();
          setOpen(null);
        }}
      >
        {open && (
          <>
            <header className="gdpr-dialog-head">
              <span className="gdpr-icon">
                <CookieIcon />
              </span>
              <button
                type="button"
                className="gdpr-close"
                aria-label={t('close')}
                onClick={() => setOpen(null)}
              >
                ×
              </button>
            </header>
            <div className="gdpr-tabs" role="tablist">
              {(['consent', 'details', 'about'] as Tab[]).map((tab) => (
                <button
                  key={tab}
                  type="button"
                  role="tab"
                  aria-selected={open === tab}
                  className={'gdpr-tab' + (open === tab ? ' is-active' : '')}
                  onClick={() => setOpen(tab)}
                >
                  {t(tab + '_tab')}
                </button>
              ))}
            </div>
            <div className="gdpr-panel" role="tabpanel">
              {open === 'consent' && (
                <>
                  <h2>{t('title')}</h2>
                  {paragraphs(t('body'))}
                  {privacy}
                </>
              )}
              {open === 'details' &&
                config.categories.map((c) => {
                  const necessary = c.key === 'necessary';
                  const on = necessary || draft[c.key as keyof ConsentState];
                  const isOpen = expanded === c.key;
                  return (
                    <div key={c.key} className="gdpr-category">
                      <div className="gdpr-category-head">
                        <button
                          type="button"
                          className="gdpr-expand"
                          aria-expanded={isOpen}
                          onClick={() => setExpanded(isOpen ? null : c.key)}
                        >
                          <span
                            className={
                              'gdpr-chevron' + (isOpen ? ' is-open' : '')
                            }
                            aria-hidden="true"
                          />
                          <strong>{c.name}</strong>
                          <span className="gdpr-count">{c.cookies.length}</span>
                        </button>
                        <Switch
                          checked={on}
                          disabled={necessary}
                          label={
                            necessary
                              ? `${c.name}: ${t('always_active')}`
                              : c.name
                          }
                          onChange={(value) =>
                            setDraft((d) => ({ ...d, [c.key]: value }))
                          }
                        />
                      </div>
                      <p className="gdpr-category-text">{c.description}</p>
                      {isOpen &&
                        (c.cookies.length === 0 ? (
                          <p className="gdpr-empty">{t('no_cookies')}</p>
                        ) : (
                          <div className="gdpr-table-wrap">
                            <table className="gdpr-table">
                              <thead>
                                <tr>
                                  <th>{t('cookie_name')}</th>
                                  <th>{t('cookie_provider')}</th>
                                  <th>{t('cookie_purpose')}</th>
                                  <th>{t('cookie_expiry')}</th>
                                </tr>
                              </thead>
                              <tbody>
                                {c.cookies.map((k) => (
                                  <tr key={k.name}>
                                    <td>{k.name}</td>
                                    <td>{k.provider}</td>
                                    <td>{k.purpose}</td>
                                    <td>{k.expiry}</td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        ))}
                    </div>
                  );
                })}
              {open === 'about' && (
                <>
                  {paragraphs(t('about'))}
                  {privacy}
                  {choice && (
                    <dl className="gdpr-proof">
                      <dt>{t('consent_id')}</dt>
                      <dd>{choice.id}</dd>
                      <dt>{t('consent_date')}</dt>
                      <dd>{new Date(choice.time * 1000).toLocaleString()}</dd>
                    </dl>
                  )}
                </>
              )}
            </div>
            {actions}
          </>
        )}
      </dialog>
    </>
  );
}
