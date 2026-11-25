import { usePage } from '@inertiajs/react';
import { markTagsLoaded } from '../tags';
import { useEffect } from 'react';
import {
  DENIED,
  googleConsent,
  useConsent,
  type ConsentState,
} from '../consent';
import {
  STOREFRONT_EVENT,
  ga4Ecommerce,
  type StorefrontEvent,
} from '../events';

type Config = { measurementId: string; ecommerce: boolean };
type Gtag = (...args: unknown[]) => void;
type GaWindow = Window & { dataLayer?: unknown[]; gtag?: Gtag };

/** GA4 recommended events this storefront can report on public pages; purchases are sent by the server. */
const EVENTS: StorefrontEvent['type'][] = [
  'add_to_cart',
  'remove_from_cart',
  'add_to_wishlist',
];

/** Google's gtag snippet, once per document; consent first when it is managed. */
function load(measurementId: string, consent: ConsentState | null): Gtag {
  const w = window as GaWindow;
  w.dataLayer = w.dataLayer ?? [];
  if (!w.gtag) {
    const layer = w.dataLayer;
    w.gtag = function gtag() {
      // gtag queues the arguments object itself, as Google's snippet does.
      // eslint-disable-next-line prefer-rest-params
      layer.push(arguments);
    };
    if (consent) w.gtag('consent', 'default', googleConsent(DENIED));
    w.gtag('js', new Date());
  }
  if (consent) w.gtag('consent', 'update', googleConsent(consent));
  if (!document.querySelector(`script[data-ga="${measurementId}"]`)) {
    w.gtag('config', measurementId);
    const script = document.createElement('script');
    script.async = true;
    script.dataset.ga = measurementId;
    script.src =
      'https://www.googletagmanager.com/gtag/js?id=' +
      encodeURIComponent(measurementId);
    document.head.appendChild(script);
    markTagsLoaded();
  }
  return w.gtag;
}

/** Keeps gtag's own client and session IDs where checkout can send a purchase for the same visit. */
function rememberVisitor(gtag: Gtag, measurementId: string) {
  gtag('get', measurementId, 'client_id', (clientId: unknown) => {
    if (typeof clientId !== 'string' || !/^\d{1,20}\.\d{1,20}$/.test(clientId))
      return;
    gtag('get', measurementId, 'session_id', (sessionId: unknown) => {
      const session = /^\d{1,20}$/.test(String(sessionId ?? ''))
        ? ':' + String(sessionId)
        : '';
      document.cookie =
        'cms_ga=' +
        clientId +
        session +
        '; path=/; max-age=7200; samesite=lax' +
        (location.protocol === 'https:' ? '; secure' : '');
    });
  });
}

/** The GA4 property the server allows on this page (public pages only) and, with ecommerce on, the shopper events. */
export default function GoogleAnalytics() {
  const { storefrontAnalytics: config = null } = usePage<{
    storefrontAnalytics?: Config | null;
  }>().props;
  const consent = useConsent();
  // With a consent manager, Analytics waits for statistics consent (basic Consent Mode).
  const allowed = !consent.active || !!consent.state?.statistics;
  useEffect(() => {
    if (!config || !allowed) return;
    const gtag = load(
      config.measurementId,
      consent.active ? consent.state : null,
    );
    if (!config.ecommerce) return;
    rememberVisitor(gtag, config.measurementId);
    const send = (e: Event) => {
      const event = (e as CustomEvent<StorefrontEvent>).detail;
      const data = ga4Ecommerce(event);
      if (EVENTS.includes(event.type) && data) gtag('event', event.type, data);
    };
    window.addEventListener(STOREFRONT_EVENT, send);
    return () => window.removeEventListener(STOREFRONT_EVENT, send);
  }, [config, allowed, consent.active, consent.state]);
  return null;
}
