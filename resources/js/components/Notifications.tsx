import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { usePreferences } from '../lib/usePreferences';
import { useTranslation } from '../lib/useTranslation';
import type { SharedProps } from '../types/page';

export default function Notifications() {
  const { flash } = usePage<SharedProps>().props;
  const { notification_duration } = usePreferences();
  const t = useTranslation();
  const [dismissed, setDismissed] = useState<typeof flash | null>(null);
  useEffect(() => {
    if (!flash.status || notification_duration === 0) return;
    const timer = window.setTimeout(
      () => setDismissed(flash),
      notification_duration * 1000,
    );
    return () => window.clearTimeout(timer);
  }, [flash, notification_duration]);

  return flash.status && dismissed !== flash ? (
    <div
      role="status"
      className="notice mb-6 flex items-start justify-between gap-4"
    >
      <span>{flash.status}</span>
      <button
        type="button"
        aria-label={t('common.close')}
        onClick={() => setDismissed(flash)}
      >
        ✕
      </button>
    </div>
  ) : null;
}
