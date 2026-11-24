import UnifiedCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import RolePermissions from '../../../components/admin/RolePermissions';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';

import ConfirmedAction from '../../../components/admin/ConfirmedAction';
import { useForm, usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormField from '../../../components/auth/FormField';
import FormErrors from '../../../components/admin/FormErrors';
import { useTranslation } from '../../../lib/useTranslation';
import { useUrlTab } from '../../../lib/useUrlTab';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';

type Role = {
  id: string;
  name: string;
  slug: string;
  description: string;
  super: boolean;
  permissions: string[];
};
const tabs = ['general', 'permissions'] as const;
type Tab = (typeof tabs)[number];
export default function RoleForm({
  role,
  permissions,
  ...shell
}: AdminShell & {
  role: Role | null;
  permissions: { name: string; label: string }[];
}) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const [tab, setTab] = useUrlTab(tabs);
  const form = useForm({
    name: role?.name ?? '',
    slug: role?.slug ?? '',
    description: role?.description ?? '',
    permissions: role?.permissions ?? [],
  });
  useDirtyForm(form);
  const title = t(role ? 'roles.edit' : 'roles.create');
  const tabError = (candidate: Tab) =>
    Object.keys(form.errors).some(
      (key) =>
        (key.startsWith('permissions') ? 'permissions' : 'general') ===
        candidate,
    );
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="security"
        backHref="/admin/roles"
        backLabel={t('common.cancel')}
        actions={
          !role?.super && (
            <>
              <EditorActionButton
                disabled={form.processing}
                form="admin-roles-form-0"
              >
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            </>
          )
        }
      />
      {role?.super ? (
        <p className="notice">{t('roles.super_hint')}</p>
      ) : (
        <form
          onSubmit={(event) => {
            event.preventDefault();
            if (role) form.put('/admin/roles/' + role.id);
            else form.post('/admin/roles');
          }}
          id="admin-roles-form-0"
        >
          <AdminTabs label={title}>
            {tabs.map((item) => (
              <AdminTab
                key={item}
                id={'role-tab-' + item}
                role="tab"
                type="button"
                aria-selected={tab === item}
                aria-controls={'role-panel-' + item}
                onClick={() => setTab(item)}
                iconKey={item}
              >
                {t('roles.' + item)}
                {tabError(item) && (
                  <span
                    className="ml-1 text-red-400"
                    aria-label={t('common.errors')}
                  >
                    ●
                  </span>
                )}
              </AdminTab>
            ))}
          </AdminTabs>

          <FormErrors errors={form.errors} />
          {tab === 'general' && (
            <section
              id="role-panel-general"
              role="tabpanel"
              aria-labelledby="role-tab-general"
            >
              <UnifiedCard
                title={t('roles.general')}
                iconKey="details"
                bodyClassName="p-5 space-y-5"
              >
                <div className="grid gap-5 sm:grid-cols-2">
                  <FormField
                    id="name"
                    label={t('common.name')}
                    required
                    value={form.data.name}
                    error={form.errors.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                  />
                  <FormField
                    id="slug"
                    label={t('roles.slug')}
                    required
                    value={form.data.slug}
                    error={form.errors.slug}
                    onChange={(e) => form.setData('slug', e.target.value)}
                  />
                </div>
                <div>
                  <label
                    htmlFor="description"
                    className="mb-1 block text-sm font-medium"
                  >
                    {t('roles.description_label')}
                  </label>
                  <textarea
                    id="description"
                    className="field min-h-24"
                    value={form.data.description}
                    onChange={(e) =>
                      form.setData('description', e.target.value)
                    }
                  />
                </div>
              </UnifiedCard>
            </section>
          )}
          {tab === 'permissions' && (
            <section
              id="role-panel-permissions"
              role="tabpanel"
              aria-labelledby="role-tab-permissions"
            >
              <UnifiedCard
                title={t('roles.permissions')}
                iconKey="details"
                bodyClassName="p-5 "
              >
                <p className="mb-4 text-sm text-slate-500">
                  {t('roles.permission_hint')}
                </p>
                <RolePermissions
                  permissions={permissions}
                  selected={form.data.permissions}
                  onChange={(values) => form.setData('permissions', values)}
                  disabled={form.processing}
                />
              </UnifiedCard>
            </section>
          )}
        </form>
      )}
      {role && !role.super && capabilities['roles.delete'] && (
        <div className="mt-6">
          <ConfirmedAction
            url={'/admin/roles/' + role.id}
            label={t('common.delete')}
            confirmation={t('roles.confirm_delete', { name: role.name })}
          />
        </div>
      )}
    </AdminLayout>
  );
}
