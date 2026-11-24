import { useTranslation } from '../../lib/useTranslation';
import { Link } from '@inertiajs/react';
import type { Breadcrumb } from '../../types/page';

export default function Breadcrumbs({ items }: { items: Breadcrumb[] }) {
  const t = useTranslation();
  // A lone crumb only repeats the page heading.
  if (items.length < 2) return null;
  return (
    <nav
      aria-label={t('navigation.breadcrumb')}
      tabIndex={0}
      className="mb-4 min-w-0 max-w-full overflow-x-auto whitespace-nowrap text-xs [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    >
      <ol className="flex flex-nowrap items-center gap-1.5 text-slate-400">
        {items.map((item, index) => (
          <li
            className="flex shrink-0 items-center gap-1.5"
            key={`${index}-${item.label}`}
          >
            {index > 0 && (
              <svg
                aria-hidden="true"
                viewBox="0 0 20 20"
                fill="none"
                className="size-3 text-slate-400/60"
              >
                <path
                  d="m8 5 5 5-5 5"
                  stroke="currentColor"
                  strokeWidth="1.5"
                />
              </svg>
            )}
            {item.href ? (
              <Link
                className="transition-colors hover:text-slate-700"
                href={item.href}
              >
                {item.label}
              </Link>
            ) : (
              <span aria-current="page" className="text-slate-600">
                {item.label}
              </span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}
