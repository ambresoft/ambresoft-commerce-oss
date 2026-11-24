import { useEffect, useRef, useState } from 'react';
import { SANDBOX } from './AddonWidgets';
import { useFrameMessages } from './frameProtocol';

/** An add-on's full page: the frame fills what the store's header leaves of the window, so the page never scrolls. */
export default function AddonFrame({
  src,
  title,
  addon,
}: {
  src: string;
  title: string;
  addon: string;
}) {
  const frame = useRef<HTMLIFrameElement>(null);
  const [top, setTop] = useState(0);
  useFrameMessages(frame, src);

  useEffect(() => {
    const measure = () =>
      setTop(
        (frame.current?.getBoundingClientRect().top ?? 0) + window.scrollY,
      );
    const observer = new ResizeObserver(measure);
    observer.observe(document.body);
    return () => observer.disconnect();
  }, []);

  return (
    <iframe
      ref={frame}
      data-storefront-frame={addon}
      src={src}
      title={title}
      sandbox={SANDBOX}
      referrerPolicy="strict-origin"
      style={{
        display: 'block',
        width: '100%',
        height: `calc(100dvh - ${top}px)`,
        minHeight: '24rem',
        border: 0,
        colorScheme: 'light',
      }}
    />
  );
}
