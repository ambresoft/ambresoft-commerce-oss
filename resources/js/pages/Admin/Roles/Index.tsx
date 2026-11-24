import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import ConfirmedAction from '../../../components/admin/ConfirmedAction';
import { Link, usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import IconButton from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';

type Role = {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  super: boolean;
  permissionCount: number;
  editUrl: string;
};
export default function RolesIndex({
  roles,
  ...shell
}: AdminShell & { roles: Page<Role> }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  return (
    <AdminLayout title={t('roles.title')} {...shell}>
      <EditorHeader
        heading={t('roles.title')}
        iconKey="security"
        description={
          <>
            <p className="mt-2 text-sm text-slate-500">
              {t('roles.description')}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['roles.create'] &&
              capabilities['roles.assign-permissions'] && (
                <Link className="button-primary" href="/admin/roles/create">
                  <Icon name="plus" className="size-5 shrink-0" />
                  {t('roles.create')}
                </Link>
              )}
          </>
        }
      />
      <section className="list-card" aria-label={t('roles.title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.name')}</th>
                <th>{t('roles.permissions')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {roles.data.map((role) => (
                <tr key={role.id}>
                  <td>
                    {!role.super &&
                    capabilities['roles.update'] &&
                    capabilities['roles.assign-permissions'] ? (
                      <Link
                        href={role.editUrl}
                        className="font-semibold hover:underline"
                      >
                        {role.name}
                      </Link>
                    ) : (
                      <span className="font-semibold">{role.name}</span>
                    )}
                    <p className="mt-0.5 text-xs text-slate-500">
                      {role.description || role.slug}
                    </p>
                  </td>
                  <td>
                    {role.super ? (
                      <StatusBadge tone="dark">{t('roles.super')}</StatusBadge>
                    ) : (
                      <span className="text-slate-500">
                        {t('roles.count', { count: role.permissionCount })}
                      </span>
                    )}
                  </td>
                  <td>
                    <div className="flex justify-end gap-2">
                      {!role.super &&
                        capabilities['roles.update'] &&
                        capabilities['roles.assign-permissions'] && (
                          <IconButton
                            icon="edit"
                            href={role.editUrl}
                            label={t('common.edit') + ': ' + role.name}
                          />
                        )}
                      {!role.super && capabilities['roles.delete'] && (
                        <ConfirmedAction
                          icon="trash"
                          url={'/admin/roles/' + role.id}
                          label={t('common.delete') + ': ' + role.name}
                          confirmation={t('roles.confirm_delete', {
                            name: role.name,
                          })}
                        />
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {!roles.data.length && (
            <p className="p-12 text-center text-sm text-slate-500">
              {t('common.empty')}
            </p>
          )}
        </div>
        <Pagination page={roles} />
      </section>
    </AdminLayout>
  );
}
