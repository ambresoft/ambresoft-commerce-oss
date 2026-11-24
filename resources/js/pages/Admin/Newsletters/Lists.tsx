import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { ListRow } from '../../../types/newsletters';
import type { SharedProps } from '../../../types/page';

export default function Lists({
  lists,
  ...shell
}: AdminShell & { lists: { data: ListRow[] } }) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const canManage = capabilities['newsletters.manage'];
  const remove = (row: ListRow) => {
    if (
      !window.confirm(t('newsletters.delete_list_confirm', { name: row.name }))
    )
      return;
    router.delete('/admin/newsletter-lists/' + row.id, {
      data: { version: row.version },
      preserveScroll: true,
    });
  };

  return (
    <AdminLayout title={t('newsletters.lists_title')} {...shell}>
      <EditorHeader
        heading={t('newsletters.lists_title')}
        iconKey="emails"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('newsletters.lists_intro')}
            </p>
          </>
        }
        actions={
          <>
            {canManage && (
              <Link
                className="button-primary gap-2"
                href="/admin/newsletter-lists/create"
              >
                <Icon name="plus" />
                {t('newsletters.add')}
              </Link>
            )}
          </>
        }
      />
      <div className="mb-4">
        <FormErrors errors={errors ?? {}} />
      </div>
      <section className="list-card" aria-label={t('newsletters.lists_title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.name')}</th>
                <th>{t('newsletters.subscribers_title')}</th>
                <th>{t('newsletters.storefront')}</th>
                <th>{t('common.status')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {lists.data.length === 0 && (
                <tr>
                  <td colSpan={5} className="py-10 text-center text-slate-500">
                    {t('newsletters.lists_empty')}
                  </td>
                </tr>
              )}
              {lists.data.map((row) => (
                <tr key={row.id}>
                  <td className="text-sm font-medium">
                    {canManage ? (
                      <Link
                        className="hover:underline"
                        href={'/admin/newsletter-lists/' + row.id + '/edit'}
                      >
                        {row.name}
                      </Link>
                    ) : (
                      row.name
                    )}
                  </td>
                  <td className="text-sm tabular-nums">
                    <Link
                      className="hover:underline"
                      href={'/admin/newsletter-subscribers?list=' + row.id}
                    >
                      {t('newsletters.members_line', {
                        total: row.subscribers,
                        confirmed: row.confirmed,
                      })}
                    </Link>
                  </td>
                  <td className="text-xs text-slate-500">
                    {row.show_on_registration && (
                      <span className="block">
                        {t('newsletters.show_on_registration')}
                      </span>
                    )}
                    {row.show_at_checkout && (
                      <span className="block">
                        {t('newsletters.show_at_checkout')}
                      </span>
                    )}
                  </td>
                  <td>
                    <StatusBadge tone={row.active ? 'success' : 'neutral'}>
                      {t(
                        row.active
                          ? 'newsletters.active'
                          : 'newsletters.inactive',
                      )}
                    </StatusBadge>
                  </td>
                  <td>
                    {canManage && (
                      <span className="flex justify-end gap-1">
                        <IconButton
                          icon="edit"
                          label={t('newsletters.edit_of', { name: row.name })}
                          href={'/admin/newsletter-lists/' + row.id + '/edit'}
                        />
                        <IconButton
                          icon="trash"
                          danger
                          label={t('newsletters.delete_of', { name: row.name })}
                          onClick={() => remove(row)}
                        />
                      </span>
                    )}
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
