import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import Switch from '../../../components/admin/Switch';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { SocialLoginRow } from '../../../types/socialLogin';

export default function SocialLoginIndex({
  providers,
  ...shell
}: AdminShell & { providers: SocialLoginRow[] }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const canManage = !!capabilities['social-login.manage'];
  const toggle = (provider: SocialLoginRow, active: boolean) =>
    router.patch(
      '/admin/social-login/' + provider.id + '/active',
      { version: provider.version, is_active: active },
      { preserveScroll: true },
    );
  return (
    <AdminLayout title={t('social_login.title')} {...shell}>
      <EditorHeader
        heading={t('social_login.title')}
        iconKey="security"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('social_login.description')}
            </p>
          </>
        }
        actions={
          <>
            {canManage && (
              <Link
                className="button-primary gap-2"
                href="/admin/social-login/create"
              >
                <Icon name="plus" />
                {t('social_login.create')}
              </Link>
            )}
          </>
        }
      />
      <section className="list-card" aria-label={t('social_login.title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.name')}</th>
                <th>{t('social_login.provider')}</th>
                <th>{t('common.status')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {providers.length === 0 && (
                <tr>
                  <td colSpan={4} className="py-10 text-center text-slate-500">
                    {t('social_login.empty')}
                  </td>
                </tr>
              )}
              {providers.map((p) => (
                <tr key={p.id}>
                  <td>
                    <Link
                      className="font-semibold hover:underline"
                      href={'/admin/social-login/' + p.id + '/edit'}
                    >
                      {p.name}
                    </Link>
                  </td>
                  <td>{p.provider_name}</td>
                  <td>
                    <div className="flex flex-wrap items-center gap-3">
                      {canManage ? (
                        <Switch
                          checked={p.active}
                          label={t('social_login.status_label', {
                            name: p.name,
                          })}
                          onChange={(value) => toggle(p, value)}
                        />
                      ) : (
                        <StatusBadge tone={p.active ? 'success' : 'neutral'}>
                          {t(
                            p.active
                              ? 'social_login.active'
                              : 'social_login.disabled',
                          )}
                        </StatusBadge>
                      )}
                      {!p.complete && (
                        <StatusBadge tone="warning">
                          {t('social_login.incomplete')}
                        </StatusBadge>
                      )}
                    </div>
                  </td>
                  <td>
                    <div className="flex justify-end">
                      <IconButton
                        icon="edit"
                        label={t('common.edit') + ': ' + p.name}
                        href={'/admin/social-login/' + p.id + '/edit'}
                      />
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
