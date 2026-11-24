import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import ConfirmedAction from '../../../components/admin/ConfirmedAction';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import { useTranslation } from '../../../lib/useTranslation';
import type {
  AdminShell,
  Pagination as Page,
  RoleOption,
} from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import FormErrors from '../../../components/admin/FormErrors';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import IconButton from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useListQuery } from '../../../lib/useListQuery';

type Staff = {
  id: string;
  name: string;
  email: string;
  active: boolean;
  verified: boolean;
  roles: RoleOption[];
  editUrl: string | null;
  canDeactivate: boolean;
};
type Props = AdminShell & {
  users: Page<Staff>;
  roles: RoleOption[];
  filters: { search?: string; status?: string; role?: string };
};

export default function UsersIndex({ users, roles, filters, ...shell }: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const errors =
    usePage<SharedProps & { errors?: Record<string, string> }>().props.errors ??
    {};
  const apply = useListQuery('/admin/users', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  return (
    <AdminLayout title={t('users.title')} {...shell}>
      <EditorHeader
        heading={t('users.title')}
        iconKey="core.access"
        description={
          <>
            <p className="mt-1.5 max-w-2xl text-sm text-slate-400">
              {t('users.description')}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['users.create'] && (
              <Link className="button-primary" href="/admin/users/create">
                <Icon name="plus" className="size-5 shrink-0" />
                {t('users.create')}
              </Link>
            )}
          </>
        }
      />
      <section className="list-card" aria-label={t('users.title')}>
        <FilterBar
          resetHref="/admin/users"
          active={!!(filters.search || filters.status || filters.role)}
          search={
            <SearchField
              id="search"
              value={search}
              placeholder={t('common.search_placeholder')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('common.status')}
                value={filters.status ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'active', label: t('common.active') },
                  { value: 'inactive', label: t('common.inactive') },
                ]}
                onChange={(value) => apply({ status: value })}
              />
              <FilterChip
                label={t('users.roles')}
                value={filters.role ? String(filters.role) : ''}
                options={[
                  { value: '', label: t('users.all_roles') },
                  ...roles.map((role) => ({
                    value: role.id,
                    label: role.name,
                  })),
                ]}
                onChange={(value) => apply({ role: value })}
              />
            </>
          }
        />
        {Object.keys(errors).length > 0 && (
          <div className="px-4 pb-4 sm:px-5">
            <FormErrors errors={errors} />
          </div>
        )}
        <div className="overflow-x-auto border-t border-slate-200">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.name')}</th>
                <th>{t('common.status')}</th>
                <th>{t('users.roles')}</th>
                <th>{t('users.verification')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {users.data.map((user) => (
                <tr key={user.id}>
                  <td>
                    <div className="flex items-center gap-3">
                      <span
                        aria-hidden="true"
                        className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-tint text-xs font-semibold text-emerald-700"
                      >
                        {user.name.slice(0, 2).toUpperCase()}
                      </span>
                      <div className="min-w-0">
                        <p className="font-semibold">
                          {user.editUrl ? (
                            <Link
                              href={user.editUrl}
                              className="hover:underline"
                            >
                              {user.name}
                            </Link>
                          ) : (
                            user.name
                          )}
                        </p>
                        <p className="mt-0.5 text-xs text-slate-500">
                          {user.email}
                        </p>
                      </div>
                    </div>
                  </td>
                  <td>
                    <StatusBadge tone={user.active ? 'success' : 'neutral'}>
                      {t(user.active ? 'common.active' : 'common.inactive')}
                    </StatusBadge>
                  </td>
                  <td className="text-slate-500">
                    {user.roles.map((role) => role.name).join(', ') || '—'}
                  </td>
                  <td>
                    <StatusBadge tone={user.verified ? 'success' : 'warning'}>
                      {t(user.verified ? 'users.verified' : 'users.unverified')}
                    </StatusBadge>
                  </td>
                  <td>
                    <div className="flex justify-end gap-2">
                      {user.editUrl && (
                        <IconButton
                          icon="edit"
                          href={user.editUrl}
                          label={t('common.edit') + ': ' + user.name}
                        />
                      )}
                      {user.canDeactivate && (
                        <ConfirmedAction
                          icon="ban"
                          url={'/admin/users/' + user.id}
                          label={t('users.deactivate') + ': ' + user.name}
                          confirmation={t('users.confirm_deactivate', {
                            name: user.name,
                          })}
                        />
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {!users.data.length && (
            <p className="p-12 text-center text-sm text-slate-500">
              {t(
                filters.search || filters.status || filters.role
                  ? 'common.no_results'
                  : 'common.empty',
              )}
            </p>
          )}
        </div>
        <Pagination page={users} />
      </section>
    </AdminLayout>
  );
}
