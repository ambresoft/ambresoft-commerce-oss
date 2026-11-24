import { Link } from '@inertiajs/react';
import { useTranslation } from '../../lib/useTranslation';
import type { SharedProps } from '../../types/page';

export default function SearchHealthWarning({
  health,
}: {
  health: SharedProps['searchHealth'];
}) {
  const t = useTranslation();
  if (health?.status !== 'attention') return null;
  return (
    <div
      role="alert"
      className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
    >
      <div>
        <p className="font-semibold">{t('search.health_attention')}</p>
        <p className="mt-1">
          {t('search.health_warning', { count: health.hidden })}
        </p>
      </div>
      <Link href="/admin/settings/search" className="button-secondary">
        {t('search.open_recovery')}
      </Link>
    </div>
  );
}
