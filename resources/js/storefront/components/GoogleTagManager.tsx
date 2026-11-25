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

type Config = {
  container: string;
  dataLayer: string;
  events: Partial<Record<StorefrontEvent['type'], string>>;
};
type Layer = Record<string, unknown>[];

/** A Consent Mode command as gtag queues it: the arguments object itself. */
function consentCommand(
  layer: Layer,
  kind: 'default' | 'update',
  state: ConsentState,
) {
  const queue = function () {
    // eslint-disable-next-line prefer-rest-params
    layer.push(arguments as unknown as Record<string, unknown>);
  } as (...args: unknown[]) => void;
  queue('consent', kind, googleConsent(state));
}

/** Starts Google's container once per document, as its official snippet does; consent first when it is managed. */
function load({ container, dataLayer }: Config, consent: ConsentState | null) {
  const w = window as unknown as Record<string, Layer | undefined>;
  const layer = (w[dataLayer] = w[dataLayer] ?? []);
  if (document.querySelector(`script[data-gtm="${container}"]`)) {
    if (consent) consentCommand(layer, 'update', consent);
    return;
  }
  if (consent) {
    consentCommand(layer, 'default', DENIED);
    consentCommand(layer, 'update', consent);
  }
  layer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
  markTagsLoaded();
  const script = document.createElement('script');
  script.async = true;
  script.dataset.gtm = container;
  script.src =
    'https://www.googletagmanager.com/gtm.js?id=' +
    encodeURIComponent(container) +
    (dataLayer === 'dataLayer' ? '' : '&l=' + encodeURIComponent(dataLayer));
  document.head.appendChild(script);
}

/**
 * The container the server allows on this page (public pages only) and the shopper events it asked for, pushed to its
 * data layer under the merchant's event names.
 */
export default function GoogleTagManager() {
  const { storefrontTagManager: config = null } = usePage<{
    storefrontTagManager?: Config | null;
  }>().props;
  const consent = useConsent();
  // With a consent manager the container waits for statistics or marketing consent (basic Consent Mode).
  const allowed =
    !consent.active ||
    !!(consent.state && (consent.state.statistics || consent.state.marketing));
  useEffect(() => {
    if (!config || !allowed) return;
    load(config, consent.active ? consent.state : null);
    const push = (e: Event) => {
      const event = (e as CustomEvent<StorefrontEvent>).detail;
      const name = config.events[event.type];
      if (!name) return;
      const layer = (window as unknown as Record<string, Layer>)[
        config.dataLayer
      ];
      const data = ga4Ecommerce(event);
      // Google's guidance: clear the previous ecommerce object so values never leak between events.
      if (data) layer.push({ ecommerce: null });
      layer.push(data ? { event: name, ecommerce: data } : { event: name });
    };
    window.addEventListener(STOREFRONT_EVENT, push);
    return () => window.removeEventListener(STOREFRONT_EVENT, push);
  }, [config, allowed, consent.active, consent.state]);
  return null;
}
