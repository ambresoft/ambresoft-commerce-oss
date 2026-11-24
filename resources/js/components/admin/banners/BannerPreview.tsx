import { useEffect, useRef, useState } from 'react';
import { BannerCanvas, canvas } from '../../banners/BannerCanvas';
export { BannerCanvas, canvas } from '../../banners/BannerCanvas';
export type { PreviewContent } from '../../banners/BannerCanvas';

/** Scales the fixed design canvas to the width it is given. */
export default function BannerPreview(
  props: Parameters<typeof BannerCanvas>[0],
) {
  const box = useRef<HTMLDivElement>(null);
  const [scale, setScale] = useState(1);
  // A block may show the banner taller than designed (its "banner height" setting); the preview can too.
  const { width } = canvas[props.device];
  const height = Math.round(
    canvas[props.device].height * (props.heightScale ?? 1),
  );
  useEffect(() => {
    const element = box.current;
    if (!element || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(([entry]) =>
      setScale(Math.min(1, entry.contentRect.width / width)),
    );
    observer.observe(element);
    return () => observer.disconnect();
  }, [width]);
  return (
    <div ref={box} className="w-full">
      <div
        className="mx-auto overflow-hidden rounded-lg border border-slate-200 shadow-sm"
        style={{ width: width * scale, height: height * scale }}
      >
        <div
          style={{
            transform: `scale(${scale})`,
            transformOrigin: 'top left',
            width,
            height,
          }}
        >
          <BannerCanvas {...props} />
        </div>
      </div>
    </div>
  );
}
