import { useTranslation } from '../../../lib/useTranslation';
import type { Tone } from '../../../components/admin/list/StatusBadge';
import type { ReviewStatus } from '../../../types/reviews';

export const statusTones: Record<ReviewStatus, Tone> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'neutral',
  spam: 'danger',
};

/** Read-only star rating with its value as the accessible name. */
export default function Stars({ rating }: { rating: number }) {
  const t = useTranslation();
  return (
    <span
      role="img"
      aria-label={t('reviews.stars_label', { count: rating })}
      title={t('reviews.stars_label', { count: rating })}
      className="inline-flex gap-0.5 whitespace-nowrap text-amber-500"
    >
      {[1, 2, 3, 4, 5].map((star) => (
        <span
          key={star}
          aria-hidden="true"
          className={star <= rating ? '' : 'text-slate-300'}
        >
          ★
        </span>
      ))}
    </span>
  );
}
