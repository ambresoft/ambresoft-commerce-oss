import { useEffect, useState } from 'react';
import { useTranslation } from '../../../lib/useTranslation';

export default function BackToTop() {
  const t = useTranslation();
  const [visible, setVisible] = useState(false);
  useEffect(() => {
    const update = () => setVisible(window.scrollY > 300);
    update();
    window.addEventListener('scroll', update, { passive: true });
    return () => window.removeEventListener('scroll', update);
  }, []);
  if (!visible) return null;
  return (
    <button
      type="button"
      className="sf-back-to-top"
      aria-label={t('storefront.back_to_top')}
      title={t('storefront.back_to_top')}
      onClick={() =>
        window.scrollTo({
          top: 0,
          behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
            .matches
            ? 'instant'
            : 'smooth',
        })
      }
    >
      <svg
        aria-hidden="true"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="m5 12 7-7 7 7M12 5v14" />
      </svg>
    </button>
  );
}
