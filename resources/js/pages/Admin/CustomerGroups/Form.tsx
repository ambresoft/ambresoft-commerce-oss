import UnifiedCard from '../../../components/admin/AdminCard';
import AdminCard from '../../../components/admin/AdminCard';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import HelpPopover from '../../../components/admin/HelpPopover';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import FormField from '../../../components/auth/FormField';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type {
  CustomerGroupEditor,
  CustomerGroupOption,
} from '../../../types/customers';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & {
  group: CustomerGroupEditor | null;
  others: CustomerGroupOption[];
  defaultLocale: string;
};

export default function CustomerGroupForm({
  group,
  others,
  defaultLocale,
  ...shell
}: Props) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const page = usePage<SharedProps & { errors: Record<string, string> }>();
  const canManage = !!capabilities['customer-groups.manage'];
  const [target, setTarget] = useState('');
  const [contentLocale, setContentLocale] = useState(
    i18n.locales.some((l) => l.code === i18n.locale)
      ? i18n.locale
      : defaultLocale,
  );
  const form = useForm({
    version: group?.version,
    names: Object.fromEntries(
      i18n.locales.map((l) => [l.code, group?.names[l.code] ?? '']),
    ),
    descriptions: Object.fromEntries(
      i18n.locales.map((l) => [l.code, group?.descriptions[l.code] ?? '']),
    ),
    status: group?.status ?? 'active',
    is_default: group?.is_default ?? false,
  });
  useDirtyForm(form);
  const errors = form.errors as Record<string, string>;
  const title =
    group === null ? t('customers.group_create') : t('customers.group_edit');
  const remove = () => {
    if (!group) return;
    const message =
      group.members > 0
        ? t('customers.group_delete_move_confirm', {
            count: group.members,
            target: others.find((o) => o.id === target)?.name ?? '',
          })
        : t('customers.group_delete_confirm');
    if (!window.confirm(message)) return;
    router.delete('/admin/customer-groups/' + group.id, {
      data: {
        version: group.version,
        reassign_to: group.members > 0 ? target : undefined,
      },
      preserveScroll: true,
    });
  };
  const deleteErrors = Object.fromEntries(
    Object.entries(page.props.errors ?? {}).filter(([key]) =>
      ['group', 'reassign_to'].includes(key),
    ),
  );
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        status={
          <>
            <div className="flex items-center gap-2">
              <EditorStatusSelect
                disabled={!canManage || form.processing}
                aria-label={t('common.status')}
                id="group-status"
                value={form.data.status}
                invalid={!!errors.status}
                onChange={(value) =>
                  form.setData('status', value as 'active' | 'disabled')
                }
                options={[
                  { value: 'active', label: t('customers.active') },
                  { value: 'disabled', label: t('customers.disabled') },
                ]}
              />
              <HelpPopover label={t('common.status')}>
                {t('customers.group_status_hint')}
              </HelpPopover>
              {errors.status && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.status}
                </p>
              )}
            </div>
          </>
        }
        heading={title}
        iconKey="roles"
        backHref="/admin/customer-groups"
        backLabel={t('customers.groups_title')}
        actions={
          canManage && (
            <>
              <div className="flex gap-3">
                <EditorActionButton
                  disabled={form.processing}
                  form="admin-customergroups-form-0"
                >
                  {t(form.processing ? 'common.wait' : 'common.save')}
                </EditorActionButton>
                <Link
                  className="button-secondary"
                  href="/admin/customer-groups"
                >
                  {t('common.cancel')}
                </Link>
              </div>
            </>
          )
        }
        menu={
          group &&
          canManage &&
          !group.is_default && (
            <button
              type="button"
              className="button-secondary text-red-700 hover:bg-red-50"
              disabled={group.members > 0 && target === ''}
              onClick={remove}
            >
              <Icon name="trash" />
              {t('customers.group_delete')}
            </button>
          )
        }
      />
      <form
        className="w-full space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          if (group === null) form.post('/admin/customer-groups');
          else form.put('/admin/customer-groups/' + group.id);
        }}
        id="admin-customergroups-form-0"
      >
        <FormErrors
          errors={Object.fromEntries(
            Object.entries(errors).filter(([key]) =>
              ['version', 'names'].includes(key),
            ),
          )}
        />
        <section>
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
            bodyClassName="p-5 space-y-5"
          >
            <div className="col-span-full flex justify-end">
              <ContentLanguageSelector
                languages={i18n.locales}
                value={contentLocale}
                onChange={setContentLocale}
                errors={form.errors as Record<string, string>}
              />
            </div>
            {i18n.locales
              .filter((l) => l.code === contentLocale)
              .map((l) => (
                <div key={l.code} className="grid gap-4 sm:grid-cols-2">
                  <FormField
                    id={'group-name-' + l.code}
                    label={t('common.name') + ' · ' + l.code}
                    requiredIndicator={l.code === defaultLocale}
                    maxLength={120}
                    value={form.data.names[l.code] ?? ''}
                    error={errors['names.' + l.code]}
                    onChange={(e) =>
                      form.setData('names', {
                        ...form.data.names,
                        [l.code]: e.target.value,
                      })
                    }
                  />
                  <FormField
                    id={'group-description-' + l.code}
                    label={t('customers.group_description') + ' · ' + l.code}
                    maxLength={1000}
                    value={form.data.descriptions[l.code] ?? ''}
                    error={errors['descriptions.' + l.code]}
                    onChange={(e) =>
                      form.setData('descriptions', {
                        ...form.data.descriptions,
                        [l.code]: e.target.value,
                      })
                    }
                  />
                </div>
              ))}

            <label className="flex items-start gap-3 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 size-4"
                checked={form.data.is_default}
                onChange={(e) => form.setData('is_default', e.target.checked)}
              />
              <span>
                <span className="font-medium">
                  {t('customers.group_default')}
                </span>
                <span className="mt-0.5 block text-xs leading-5 text-slate-500">
                  {t('customers.group_default_hint')}
                </span>
              </span>
            </label>
          </UnifiedCard>
        </section>
      </form>
      {group && canManage && (
        <section className="mt-8">
          <AdminCard
            id="group-delete"
            title={t('customers.group_delete')}
            iconKey="group_delete"
            bodyClassName="p-5 space-y-3"
          >
            <FormErrors errors={deleteErrors} />
            {group.is_default ? (
              <p className="text-sm text-slate-600">
                {t('customers.group_default_delete')}
              </p>
            ) : (
              <>
                {group.members > 0 && (
                  <div className="space-y-2 sm:max-w-sm">
                    <label
                      htmlFor="reassign-to"
                      className="block text-sm font-medium"
                    >
                      {t('customers.group_reassign', { count: group.members })}
                    </label>
                    <Select
                      id="reassign-to"
                      value={target}
                      onChange={setTarget}
                      options={[
                        {
                          value: '',
                          label: t('customers.group_reassign_choose'),
                          disabled: true,
                        },
                        ...others.map((o) => ({ value: o.id, label: o.name })),
                      ]}
                    />
                  </div>
                )}
              </>
            )}
          </AdminCard>
        </section>
      )}
    </AdminLayout>
  );
}
