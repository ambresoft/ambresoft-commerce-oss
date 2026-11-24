import { usePage } from '@inertiajs/react';
import { useMemo, useSyncExternalStore } from 'react';

/** The optional categories a visitor decides on; necessary cookies need no consent (A149). */
export type ConsentState = {
  preferences: boolean;
  statistics: boolean;
  marketing: boolean;
};
export type ConsentChoice = { id: string; time: number; state: ConsentState };
type ConsentConfig = { version: number; months: number };

export const CONSENT_COOKIE = 'cms_consent';
export const CONSENT_EVENT = 'cms:consent';
const KEYS = ['preferences', 'statistics', 'marketing'] as const;

/** The visitor's current choice, or null when there is none for this version or it has expired. */
export function readConsent(config: ConsentConfig): ConsentChoice | null {
  const raw = document.cookie
    .split('; ')
    .find((c) => c.startsWith(CONSENT_COOKIE + '='))
    ?.slice(CONSENT_COOKIE.length + 1);
  const m = /^([0-9a-f-]{36})\.(\d{1,9})\.([01]{3})\.(\d{10})$/.exec(raw ?? '');
  if (!m || Number(m[2]) !== config.version) return null;
  const time = Number(m[4]);
  if (time < Date.now() / 1000 - config.months * 30 * 86400) return null;
  return {
    id: m[1],
    time,
    state: Object.fromEntries(
      KEYS.map((k, i) => [k, m[3][i] === '1']),
    ) as ConsentState,
  };
}

/** A random v4 UUID; crypto.randomUUID exists only on HTTPS and localhost, getRandomValues everywhere. */
function uuid(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/** Stores a choice for the configured period and tells the page. */
export function writeConsent(
  config: ConsentConfig,
  state: ConsentState,
  id: string = uuid(),
): ConsentChoice {
  const time = Math.floor(Date.now() / 1000);
  const flags = KEYS.map((k) => (state[k] ? '1' : '0')).join('');
  document.cookie =
    `${CONSENT_COOKIE}=${id}.${config.version}.${flags}.${time}; path=/; max-age=${config.months * 30 * 86400}; samesite=lax` +
    (location.protocol === 'https:' ? '; secure' : '');
  const choice = { id, time, state };
  window.dispatchEvent(new CustomEvent(CONSENT_EVENT, { detail: choice }));
  return choice;
}

const subscribe = (notify: () => void) => {
  window.addEventListener(CONSENT_EVENT, notify);
  return () => window.removeEventListener(CONSENT_EVENT, notify);
};
const cookieSnapshot = () => document.cookie;

/**
 * Whether a consent manager is active on this page and, if so, the visitor's current choice (null until they choose).
 * Without a consent manager, tags behave as before and consent is the merchant's responsibility.
 */
export function useConsent(): { active: boolean; state: ConsentState | null } {
  const { storefrontConsent: config = null } = usePage<{
    storefrontConsent?: ConsentConfig | null;
  }>().props;
  const cookies = useSyncExternalStore(subscribe, cookieSnapshot, () => '');
  const version = config?.version;
  const months = config?.months;
  const state = useMemo(
    () =>
      version === undefined || months === undefined || cookies === ''
        ? null
        : (readConsent({ version, months })?.state ?? null),
    [version, months, cookies],
  );
  return { active: config !== null, state };
}

/** Google Consent Mode v2 types for a choice (basic mode: Google tags load only after consent). */
export function googleConsent(state: ConsentState) {
  const v = (on: boolean) => (on ? 'granted' : 'denied');
  return {
    ad_storage: v(state.marketing),
    ad_user_data: v(state.marketing),
    ad_personalization: v(state.marketing),
    analytics_storage: v(state.statistics),
    functionality_storage: v(state.preferences),
    personalization_storage: v(state.preferences),
    security_storage: 'granted',
  };
}

export const DENIED: ConsentState = {
  preferences: false,
  statistics: false,
  marketing: false,
};
