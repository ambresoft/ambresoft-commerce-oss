import { Link } from '@inertiajs/react';
import { useTranslation } from '../../lib/useTranslation';

export type VariationSibling = {
  id: string;
  name: string;
  sku: string | null;
  status: string;
  values: Record<string, { id: string; label: string } | null>;
  current: boolean;
};
export type ProductVariation = {
  group: {
    id: string;
    name: string;
    features?: { id: string; name: string }[];
    members?: VariationSibling[];
    readiness?: { total: number; available: number; eligible: boolean };
  } | null;
  pending: { id: string; name: string; problems: string[] } | null;
};
export type VariationNotice = {
  status: 'joined' | 'pending';
  group: string;
  name: string;
  problems?: string[];
} | null;

/** Group membership, or the Create-variation target a standalone product will join once valid. */
export default function VariationMembership({
  variation,
  notice,
}: {
  variation?: ProductVariation;
  notice?: VariationNotice;
}) {
  const t = useTranslation();
  if (!variation) return null;
  return (
    <div className="mt-3 space-y-2 text-sm">
      {notice?.status === 'joined' && (
        <p role="status" className="notice">
          {t('variations.joined', { group: notice.name })}
        </p>
      )}
      {variation.group && (
        <p className="text-slate-600">
          {t('variations.member_of')}{' '}
          <Link
            className="text-link"
            href={'/admin/variation-groups/' + variation.group.id}
          >
            {variation.group.name}
          </Link>
        </p>
      )}
      {variation.pending && (
        <div
          role="status"
          className="rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-900"
        >
          <p>
            {t('variations.pending', { group: variation.pending.name })}{' '}
            <Link
              className="text-link"
              href={'/admin/variation-groups/' + variation.pending.id}
            >
              {t('variations.open_group')}
            </Link>
          </p>
          {variation.pending.problems.length > 0 && (
            <ul className="mt-2 list-inside list-disc">
              {variation.pending.problems.map((problem) => (
                <li key={problem}>{problem}</li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
