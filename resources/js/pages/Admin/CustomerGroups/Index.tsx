import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { CustomerGroupRow } from '../../../types/customers';
import type { SharedProps } from '../../../types/page';

export default function CustomerGroupsIndex({
  groups,
  ...shell
}: AdminShell & { groups: CustomerGroupRow[] }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  return (
    <AdminLayout title={t('customers.groups_title')} {...shell}>
      <EditorHeader
        heading={t('customers.groups_title')}
        iconKey="roles"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('customers.groups_description')}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['customer-groups.manage'] && (
              <Link
                className="button-primary gap-2"
                href="/admin/customer-groups/create"
              >
                <Icon name="plus" />
                {t('customers.group_create')}
              </Link>
            )}
          </>
        }
      />
      <section className="list-card" aria-label={t('customers.groups_title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.name')}</th>
                <th>{t('common.status')}</th>
                <th className="text-right">{t('customers.members')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {groups.length === 0 && (
                <tr>
                  <td colSpan={4} className="py-10 text-center text-slate-500">
                    {t('customers.groups_empty')}
                  </td>
                </tr>
              )}
              {groups.map((g) => (
                <tr key={g.id}>
                  <td>
                    <Link
                      className="font-semibold hover:underline"
                      href={'/admin/customer-groups/' + g.id + '/edit'}
                    >
                      {g.name}
                    </Link>
                    {g.default && (
                      <span className="ml-2">
                        <StatusBadge tone="dark">
                          {t('customers.group_default')}
                        </StatusBadge>
                      </span>
                    )}
                    {g.description && (
                      <p className="mt-0.5 text-xs text-slate-500">
                        {g.description}
                      </p>
                    )}
                  </td>
                  <td>
                    <StatusBadge
                      tone={g.status === 'active' ? 'success' : 'neutral'}
                    >
                      {t(
                        g.status === 'active'
                          ? 'customers.active'
                          : 'customers.disabled',
                      )}
                    </StatusBadge>
                  </td>
                  <td className="text-right tabular-nums">
                    <Link
                      className="hover:underline"
                      href={'/admin/customers?group=' + g.id}
                    >
                      {g.members}
                    </Link>
                  </td>
                  <td>
                    <div className="flex justify-end">
                      <IconButton
                        icon="edit"
                        label={t('common.edit') + ': ' + g.name}
                        href={'/admin/customer-groups/' + g.id + '/edit'}
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
