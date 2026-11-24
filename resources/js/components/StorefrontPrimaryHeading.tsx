import { useEffect } from 'react';

/** Merchant HTML can supply the homepage heading; provide a fallback only when it does not. */
export default function StorefrontPrimaryHeading({ title }: { title: string }) {
  useEffect(() => {
    const main = document.querySelector('main');
    if (!main || document.querySelector('h1')) return;
    const heading = document.createElement('h1');
    heading.className = 'sf-visually-hidden';
    heading.textContent = title;
    main.prepend(heading);
    return () => heading.remove();
  }, [title]);
  return null;
}
