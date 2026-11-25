import { usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { useFrameMessages, type FrameState } from './frameProtocol';
import './addonWidgets.css';

type Widget = { addon: string; src: string; title: string };

/**
 * The frame's own origin, scripts and forms, and nothing else: no top navigation, no popups. The server refuses widgets
 * on the store's host, because allow-scripts with allow-same-origin only sandboxes a cross-origin document.
 */
export const SANDBOX = 'allow-scripts allow-same-origin allow-forms';

function AddonWidget({ addon, src, title }: Widget) {
  const frame = useRef<HTMLIFrameElement>(null);
  const [state, setState] = useState<FrameState>('closed');
  useFrameMessages(frame, src, setState);
  return (
    <iframe
      ref={frame}
      className="sf-addon-widget"
      data-state={state}
      data-storefront-widget={addon}
      src={src}
      title={title}
      sandbox={SANDBOX}
      referrerPolicy="strict-origin"
    />
  );
}

/**
 * Enabled add-on widgets on approved pages, each in a cross-origin sandboxed frame. React removes the frame on any
 * other page, which destroys its browsing context: unlike a script, nothing of it keeps running.
 */
export default function AddonWidgets() {
  const { storefrontWidgets = [] } = usePage<{
    storefrontWidgets?: Widget[];
  }>().props;
  return storefrontWidgets.map((widget) => (
    <AddonWidget key={widget.addon} {...widget} />
  ));
}
