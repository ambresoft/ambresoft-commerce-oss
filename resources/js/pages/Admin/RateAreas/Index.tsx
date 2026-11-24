import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { RateAreaRow } from '../../../types/taxes';

export default function RateAreasIndex({
  areas,
  ...shell
}: AdminShell & { areas: RateAreaRow[] }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const remove = (area: RateAreaRow) => {
    if (!window.confirm(t('taxes.area_delete_confirm', { name: area.name })))
      return;
    router.delete('/admin/rate-areas/' + area.id, {
      data: { version: area.version },
      preserveScroll: true,
    });
  };
  return (
    <AdminLayout title={t('taxes.areas_title')} {...shell}>
      <EditorHeader
        heading={t('taxes.areas_title')}
        iconKey="core.website"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('taxes.areas_description')}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['rate-areas.manage'] && (
              <Link
                className="button-primary gap-2"
                href="/admin/rate-areas/create"
              >
                <Icon name="plus" />
                {t('taxes.area_create')}
              </Link>
            )}
          </>
        }
      />
      <section className="list-card" aria-label={t('taxes.areas_title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.name')}</th>
                <th>{t('common.status')}</th>
                <th>{t('taxes.area_countries')}</th>
                <th className="text-right">{t('taxes.area_regions')}</th>
                <th className="text-right">{t('taxes.area_taxes')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {areas.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-slate-500">
                    {t('taxes.areas_empty')}
                  </td>
                </tr>
              )}
              {areas.map((area) => (
                <tr key={area.id}>
                  <td>
                    <Link
                      className="font-semibold hover:underline"
                      href={'/admin/rate-areas/' + area.id + '/edit'}
                    >
                      {area.name}
                    </Link>
                    {area.default && (
                      <span className="ml-2">
                        <StatusBadge tone="dark">
                          {t('taxes.area_default')}
                        </StatusBadge>
                      </span>
                    )}
                  </td>
                  <td>
                    <StatusBadge
                      tone={area.status === 'active' ? 'success' : 'neutral'}
                    >
                      {t(
                        area.status === 'active'
                          ? 'taxes.active'
                          : 'taxes.disabled',
                      )}
                    </StatusBadge>
                  </td>
                  <td className="text-sm">
                    {area.default ? (
                      <span className="text-slate-500">
                        {t('taxes.area_everywhere')}
                      </span>
                    ) : (
                      <>
                        {area.countries.join(', ')}
                        {area.country_count > area.countries.length && (
                          <span className="text-slate-500">
                            {' '}
                            +{area.country_count - area.countries.length}
                          </span>
                        )}
                      </>
                    )}
                  </td>
                  <td className="text-right tabular-nums">{area.regions}</td>
                  <td className="text-right tabular-nums">{area.taxes}</td>
                  <td>
                    <div className="flex justify-end gap-1">
                      <IconButton
                        icon="edit"
                        label={t('common.edit') + ': ' + area.name}
                        href={'/admin/rate-areas/' + area.id + '/edit'}
                      />
                      {capabilities['rate-areas.manage'] && (
                        <IconButton
                          icon="trash"
                          danger
                          disabled={area.taxes > 0}
                          label={
                            (area.taxes > 0
                              ? t('taxes.area_in_use_hint')
                              : t('taxes.delete')) +
                            ': ' +
                            area.name
                          }
                          onClick={() => remove(area)}
                        />
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </AdminLayout>
  );
}
