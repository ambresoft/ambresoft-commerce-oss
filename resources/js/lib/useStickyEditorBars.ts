import { useEffect, useRef } from 'react';

/** Expand editor navigation only while it is pinned to the workspace's top edge. */
export function useStickyEditorBars(page: string) {
  const content = useRef<HTMLElement>(null);

  useEffect(() => {
    const bars =
      content.current?.querySelectorAll<HTMLElement>('.editor-tab-bar');
    if (!bars?.length) return;
    const region = content.current?.closest<HTMLElement>('[scroll-region]');
    const scrollTarget = region ?? window;

    const update = () => {
      const top = region
        ? region.getBoundingClientRect().top + region.clientTop
        : 0;
      for (const bar of bars) {
        bar.toggleAttribute(
          'data-stuck',
          bar.getBoundingClientRect().top <= top,
        );
      }
    };
    update();
    scrollTarget.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    const observer =
      typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(update);
    for (const bar of bars) observer?.observe(bar);

    return () => {
      scrollTarget.removeEventListener('scroll', update);
      window.removeEventListener('resize', update);
      observer?.disconnect();
    };
  }, [page]);

  return content;
}
