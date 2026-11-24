import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { BannerCanvas, canvas } from '../../components/banners/BannerCanvas';
import { useTranslation } from '../../lib/useTranslation';
import type { Device } from '../../types/banners';
import type { PublicBanner } from '../types';

const subscribeDevice = (onChange: () => void) => {
  window.addEventListener('resize', onChange);
  return () => window.removeEventListener('resize', onChange);
};
const currentDevice = (): Device =>
  window.innerWidth < 768
    ? 'phone'
    : window.innerWidth < 1024
      ? 'tablet'
      : 'pc';
const serverDevice = (): Device => 'pc';

/** One shared design canvas, scaled to its grid; device overrides follow theme breakpoints. */
export default function StorefrontBanner({
  banner,
  eager = false,
  heights,
}: {
  banner?: PublicBanner;
  /** A banner the block preloads (its first, or every one): its images load at once and with priority. */
  eager?: boolean;
  /** The block's banner height as a percentage of the design's own, on desktop and tablet (phones keep theirs). */
  heights?: { pc?: number; tablet?: number };
}) {
  const t = useTranslation();
  const device = useSyncExternalStore(
    subscribeDevice,
    currentDevice,
    serverDevice,
  );
  const box = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(0);
  // Other banners load their pictures only when they come near the screen.
  const [near, setNear] = useState(
    eager || typeof IntersectionObserver === 'undefined',
  );
  useEffect(() => {
    const element = box.current;
    if (!element || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(([entry]) =>
      setWidth(entry.contentRect.width),
    );
    observer.observe(element);
    return () => observer.disconnect();
  }, [banner]);
  useEffect(() => {
    const element = box.current;
    if (near || !element) return;
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setNear(true);
          observer.disconnect();
        }
      },
      { rootMargin: '300px 100%' },
    );
    observer.observe(element);
    return () => observer.disconnect();
  }, [near, banner]);
  if (!banner) return null;
  const { settings, devices, products } = banner;
  const resolved = devices[device];
  // Same width, more height: the design has more room and nothing is stretched or scaled differently.
  const heightScale = Math.min(
    2.5,
    Math.max(1, (device === 'phone' ? 100 : (heights?.[device] ?? 100)) / 100),
  );
  const size = {
    width: canvas[device].width,
    height: Math.round(canvas[device].height * heightScale),
  };
  const hasFrame =
    settings.object_type === 'youtube' && !!resolved.content.youtube_video;
  const link = settings.url
    ? { url: settings.url, new_window: settings.new_window }
    : undefined;
  const contents = (
    <div
      ref={box}
      className="relative w-full overflow-hidden"
      style={{ aspectRatio: `${size.width} / ${size.height}` }}
    >
      <div
        style={{
          width: size.width,
          height: size.height,
          transform: `scale(${width > 0 ? width / size.width : 1})`,
          transformOrigin: 'top left',
        }}
      >
        <BannerCanvas
          device={device}
          settings={settings}
          layout={resolved.layout}
          content={resolved.content}
          products={products}
          link={hasFrame ? link : undefined}
          priority={eager ? 'high' : near ? 'normal' : 'low'}
          heightScale={heightScale}
        />
      </div>
    </div>
  );
  return (
    <article className="sf-banner">
      {link && !hasFrame ? (
        <a
          href={link.url}
          target={link.new_window ? '_blank' : undefined}
          rel={link.new_window ? 'noopener noreferrer' : undefined}
          aria-label={
            !resolved.content.title && !resolved.content.description
              ? resolved.content.button_text || t('storefront.promotion')
              : undefined
          }
        >
          {contents}
        </a>
      ) : (
        contents
      )}
    </article>
  );
}
