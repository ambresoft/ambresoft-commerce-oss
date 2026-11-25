import Card from '../../../components/admin/AdminCard';
import AccountSecuritySummary from '../../../components/admin/AccountSecuritySummary';
import type { SecuritySummary } from '../../../types/accountSecurity';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';

import { useUrlTab } from '../../../lib/useUrlTab';
import ConfirmedAction from '../../../components/admin/ConfirmedAction';
import { useForm, usePage, router } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormField from '../../../components/auth/FormField';
import FormErrors from '../../../components/admin/FormErrors';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, RoleOption } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';

type Staff = {
  id: string;
  name: string;
  email: string;
  active: boolean;
  verified?: boolean;
  roleIds: string[];
};
const tabs = ['general', 'security', 'roles'] as const;
type Tab = (typeof tabs)[number];

export default function UserForm({
  security,
  staff,
  roles,
  ...shell
}: AdminShell & {
  security?: SecuritySummary | null;
  staff: Staff | null;
  roles: RoleOption[];
}) {
  const t = useTranslation();
  const { capabilities, auth } = usePage<SharedProps>().props;
  const visibleTabs = tabs.filter(
    (item) => item !== 'roles' || capabilities['users.assign-roles'],
  );
  const [tab, setTab] = useUrlTab(visibleTabs);
  const form = useForm({
    name: staff?.name ?? '',
    email: staff?.email ?? '',
    password: '',
    password_confirmation: '',
    invite:
      !staff &&
      (!capabilities['users.set-password'] ||
        Boolean(capabilities['users.assign-roles'])),
    is_active: staff?.active ?? true,
    ...(capabilities['users.assign-roles']
      ? { role_ids: staff?.roleIds ?? [] }
      : {}),
  });
  useDirtyForm(form);
  const title = t(staff ? 'users.edit' : 'users.create');
  const tabError = (candidate: Tab) =>
    Object.keys(form.errors).some(
      (key) =>
        (key.startsWith('role_ids')
          ? 'roles'
          : key.startsWith('password')
            ? 'security'
            : 'general') === candidate,
    );
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="core.access"
        backHref="/admin/users"
        backLabel={t('common.cancel')}
        actions={
          <>
            <EditorActionButton
              disabled={form.processing}
              form="admin-users-form-0"
            >
              {t(form.processing ? 'common.wait' : 'common.save')}
            </EditorActionButton>
          </>
        }
      />
      <form
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          const options = {
            onFinish: () => form.reset('password', 'password_confirmation'),
          };
          if (staff) form.put('/admin/users/' + staff.id, options);
          else form.post('/admin/users', options);
        }}
        id="admin-users-form-0"
      >
        <AdminTabs label={title} className="mb-5">
          {visibleTabs.map((item) => (
            <AdminTab
              key={item}
              id={'user-tab-' + item}
              type="button"
              role="tab"
              aria-selected={tab === item}
              aria-controls={'user-panel-' + item}
              tabIndex={tab === item ? 0 : -1}
              onClick={() => setTab(item)}
              iconKey={item}
            >
              {t('users.' + item)}
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
        <section
          id="user-panel-general"
          role="tabpanel"
          aria-labelledby="user-tab-general"
          hidden={tab !== 'general'}
          className="space-y-6"
        >
          <Card
            id="user-panel-general-heading"
            icon="user"
            title={t('users.general')}
          >
            <div className="space-y-5">
              {!staff && (
                <p className="text-sm text-slate-500">
                  {t('users.new_description')}
                </p>
              )}
              <div className="grid gap-5 sm:grid-cols-2">
                <FormField
                  id="name"
                  label={t('common.name')}
                  autoComplete="name"
                  required
                  value={form.data.name}
                  error={form.errors.name}
                  onChange={(e) => form.setData('name', e.target.value)}
                />
                <FormField
                  id="email"
                  label={t('common.email')}
                  type="email"
                  autoComplete="off"
                  readOnly={
                    Boolean(staff) && !capabilities['users.set-password']
                  }
                  required
                  value={form.data.email}
                  error={form.errors.email}
                  onChange={(e) => form.setData('email', e.target.value)}
                />
              </div>
              <label className="flex items-center gap-3 text-sm">
                <input
                  type="checkbox"
                  className="size-4"
                  checked={form.data.is_active}
                  disabled={
                    staff?.id === auth.user?.id ||
                    !capabilities['users.deactivate']
                  }
                  onChange={(e) => form.setData('is_active', e.target.checked)}
                />
                {t('users.active')}
              </label>
            </div>
          </Card>
        </section>
        <section
          id="user-panel-security"
          role="tabpanel"
          aria-labelledby="user-tab-security"
          hidden={tab !== 'security'}
          className="space-y-6"
        >
          <Card
            id="user-panel-security-heading"
            icon="shield"
            title={t('users.security')}
          >
            <div className="space-y-5">
              {!staff && capabilities['users.set-password'] && (
                <label className="flex items-center gap-3 text-sm">
                  <input
                    type="checkbox"
                    checked={form.data.invite}
                    onChange={(e) => {
                      form.setData('invite', e.target.checked);
                      form.reset('password', 'password_confirmation');
                    }}
                  />
                  {t('auth.send_staff_invitation')}
                </label>
              )}
              {staff && !staff.verified && (
                <button
                  type="button"
                  className="button-secondary"
                  onClick={() =>
                    router.post(`/admin/users/${staff.id}/invitation`)
                  }
                >
                  {t('auth.send_staff_invitation')}
                </button>
              )}
              {!capabilities['users.set-password'] && (
                <p className="text-sm text-slate-500">
                  {t('users.password_permission_required')}
                </p>
              )}
              {!form.data.invite && capabilities['users.set-password'] && (
                <div className="grid gap-5 sm:grid-cols-2">
                  <FormField
                    id="password"
                    label={t('common.password')}
                    hint={t(
                      staff ? 'users.password_hint' : 'auth.password_hint',
                    )}
                    type="password"
                    autoComplete="new-password"
                    required={!staff}
                    value={form.data.password}
                    error={form.errors.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                  />
                  <FormField
                    id="password_confirmation"
                    label={t('common.confirm_password')}
                    type="password"
                    autoComplete="new-password"
                    required={!staff || !!form.data.password}
                    value={form.data.password_confirmation}
                    onChange={(e) =>
                      form.setData('password_confirmation', e.target.value)
                    }
                  />
                </div>
              )}
            </div>
          </Card>
          {security &&
            capabilities['account-security.view'] &&
            tab === 'security' && (
              <AccountSecuritySummary security={security} />
            )}
        </section>
        {capabilities['users.assign-roles'] && (
          <section
            id="user-panel-roles"
            role="tabpanel"
            aria-labelledby="user-tab-roles"
            hidden={tab !== 'roles'}
            className="space-y-6"
          >
            <Card
              id="user-panel-roles-heading"
              iconKey="roles"
              title={t('users.roles')}
            >
              <div className="space-y-5">
                <p className="mb-4 text-sm text-slate-500">
                  {t('users.role_hint')}
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                  {roles.map((role) => (
                    <label
                      key={role.id}
                      className="flex items-center gap-3 rounded-md border border-slate-200 p-3 text-sm"
                    >
                      <input
                        type="checkbox"
                        className="size-4"
                        checked={form.data.role_ids?.includes(role.id) ?? false}
                        onChange={(e) =>
                          form.setData(
                            'role_ids',
                            e.target.checked
                              ? [...(form.data.role_ids ?? []), role.id]
                              : form.data.role_ids?.filter(
                                  (id) => id !== role.id,
                                ),
                          )
                        }
                      />
                      {role.name}
                    </label>
                  ))}
                </div>
              </div>
            </Card>
          </section>
        )}
      </form>
      {tab === 'general' &&
        staff &&
        staff.active &&
        staff.id !== auth.user?.id &&
        capabilities['users.deactivate'] && (
          <div className="mt-6">
            <ConfirmedAction
              url={'/admin/users/' + staff.id}
              label={t('users.deactivate')}
              confirmation={t('users.confirm_deactivate', { name: staff.name })}
            />
          </div>
        )}
    </AdminLayout>
  );
}
