import { useEffect, useRef, type RefObject } from 'react';

const PROTOCOL = 'cms-widget/1';
const MAX_URL = 2000;

export type FrameState = 'open' | 'closed';

export type FrameMessage =
  { type: 'resize'; state: FrameState } | { type: 'navigate'; url: string };

/**
 * A frame can only name a state, because the CMS decides every size, and a page of this store, because the sandbox denies
 * it top navigation. Anything else, and every message from another window, is dropped.
 */
export function frameMessage(data: unknown): FrameMessage | null {
  if (typeof data !== 'object' || data === null) {
    return null;
  }
  const { protocol, type, state, url } = data as Record<string, unknown>;
  if (protocol !== PROTOCOL) {
    return null;
  }
  if (type === 'resize' && (state === 'open' || state === 'closed')) {
    return { type, state };
  }
  if (type === 'navigate' && typeof url === 'string' && url.length <= MAX_URL) {
    try {
      const target = new URL(url);
      const inAdmin = /^\/admin(\/|$)/.test(target.pathname);
      if (target.origin === window.location.origin && !inAdmin) {
        return { type, url: target.href };
      }
    } catch {
      return null;
    }
  }
  return null;
}

/** Listens to one frame: the source window and the origin of its address must both match. */
export function useFrameMessages(
  frame: RefObject<HTMLIFrameElement | null>,
  src: string,
  onResize?: (state: FrameState) => void,
) {
  const resize = useRef(onResize);
  useEffect(() => {
    resize.current = onResize;
  });
  useEffect(() => {
    const origin = new URL(src).origin;
    const listen = (event: MessageEvent) => {
      if (
        event.source !== frame.current?.contentWindow ||
        event.origin !== origin
      ) {
        return;
      }
      const message = frameMessage(event.data);
      if (message?.type === 'navigate') {
        window.location.assign(message.url);
      } else if (message?.type === 'resize') {
        resize.current?.(message.state);
      }
    };
    window.addEventListener('message', listen);
    return () => window.removeEventListener('message', listen);
  }, [frame, src]);
}
