import AdminCard from './AdminCard';
import { Link } from '@inertiajs/react';
import { useTranslation } from '../../lib/useTranslation';
import StatusBadge, { type Tone } from './list/StatusBadge';
import type { ProductVariation } from './VariationMembership';

const statusTone: Record<string, Tone> = {
  active: 'success',
  disabled: 'neutral',
  hidden: 'warning',
};

/** Product editor tab: the other products of this product's variation group. */
export default function ProductVariations({
  variation,
}: {
  variation?: ProductVariation;
}) {
  const t = useTranslation();
  const group = variation?.group;
  if (!group?.members) {
    return (
      <AdminCard title={t('products.variations')} iconKey="variations">
        <div className="text-sm text-slate-600">
          <p>
            {t(
              variation?.pending
                ? 'variations.tab_pending'
                : 'variations.tab_standalone',
              { group: variation?.pending?.name ?? '' },
            )}
          </p>
          <Link
            className="text-link mt-3 inline-block"
            href={
              variation?.pending
                ? '/admin/variation-groups/' + variation.pending.id
                : '/admin/variation-groups'
            }
          >
            {t(
              variation?.pending ? 'variations.open_group' : 'variations.title',
            )}
          </Link>
        </div>
      </AdminCard>
    );
  }
  const features = group.features ?? [];
  return (
    <AdminCard
      id="product-variations-heading"
      title={group.name}
      iconKey="variations"
      action={
        <Link
          className="button-secondary"
          href={'/admin/variation-groups/' + group.id}
        >
          {t('variations.manage_group')}
        </Link>
      }
      bodyClassName="p-0"
    >
      <p className="px-5 py-3 text-sm text-slate-500">
        {t('variations.tab_help', {
          features: features.map((f) => f.name).join(', '),
        })}
      </p>
      <div className="overflow-x-auto">
        <table className="data-table">
          <thead>
            <tr>
              <th>{t('common.name')}</th>
              {features.map((f) => (
                <th key={f.id}>{f.name}</th>
              ))}
              <th>{t('products.sku')}</th>
              <th>{t('common.status')}</th>
            </tr>
          </thead>
          <tbody>
            {group.members.map((m) => (
              <tr key={m.id} data-selected={m.current || undefined}>
                <td className="min-w-48">
                  {m.current ? (
                    <span className="font-semibold" aria-current="page">
                      {m.name}
                      <span className="ml-2 rounded-full bg-white px-2 py-0.5 text-xs font-medium text-emerald-900">
                        {t('variations.this_product')}
                      </span>
                    </span>
                  ) : (
                    <Link
                      className="font-semibold text-slate-900 hover:underline"
                      href={'/admin/products/' + m.id + '/edit?tab=variations'}
                    >
                      {m.name}
                    </Link>
                  )}
                </td>
                {features.map((f) => (
                  <td key={f.id} className="whitespace-nowrap">
                    {m.values[f.id]?.label ?? '—'}
                  </td>
                ))}
                <td className="text-slate-500">{m.sku ?? '—'}</td>
                <td>
                  <StatusBadge tone={statusTone[m.status] ?? 'neutral'}>
                    {t('products.' + m.status)}
                  </StatusBadge>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {group.readiness && (
        <p className="border-t border-slate-200 px-5 py-3 text-sm text-slate-500">
          {t(
            group.readiness.eligible
              ? 'variations.switcher_ready'
              : 'variations.switcher_not_ready',
          )}
        </p>
      )}
    </AdminCard>
  );
}
