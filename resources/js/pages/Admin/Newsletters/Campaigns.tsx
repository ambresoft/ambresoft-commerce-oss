import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import FormField from '../../../components/auth/FormField';
import IconButton from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { CampaignRow } from '../../../types/newsletters';
import type { SharedProps } from '../../../types/page';

export default function Campaigns({
  campaigns,
  ...shell
}: AdminShell & { campaigns: { data: CampaignRow[] } }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const canManage = capabilities['newsletters.manage'];
  const [editing, setEditing] = useState<CampaignRow | null>(null);
  const form = useForm({ version: 1, name: '', status: 'active' });
  const errors = form.errors as Record<string, string>;
  const done = () => {
    setEditing(null);
    form.reset();
  };
  const edit = (row: CampaignRow) => {
    setEditing(row);
    form.setData({
      version: row.version,
      name: row.name,
      status: row.active ? 'active' : 'disabled',
    });
  };
  const remove = (row: CampaignRow) => {
    if (
      !window.confirm(
        t('newsletters.delete_campaign_confirm', { name: row.name }),
      )
    )
      return;
    router.delete('/admin/newsletter-campaigns/' + row.id, {
      data: { version: row.version },
      preserveScroll: true,
    });
  };

  return (
    <AdminLayout title={t('newsletters.campaigns_title')} {...shell}>
      <EditorHeader
        heading={t('newsletters.campaigns_title')}
        iconKey="emails"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('newsletters.campaigns_intro')}
            </p>
          </>
        }
      />
      {canManage && (
        <form
          className="mb-5 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-5"
          onSubmit={(event) => {
            event.preventDefault();
            const options = { preserveScroll: true, onSuccess: done };
            if (editing)
              form.put('/admin/newsletter-campaigns/' + editing.id, options);
            else form.post('/admin/newsletter-campaigns', options);
          }}
        >
          <div className="min-w-64 grow">
            <FormField
              id="campaign-name"
              label={
                editing
                  ? t('newsletters.edit_campaign')
                  : t('newsletters.add_campaign')
              }
              maxLength={150}
              value={form.data.name}
              error={errors.name}
              onChange={(e) => form.setData('name', e.target.value)}
            />
          </div>
          <label className="flex items-center gap-2 pb-2 text-sm">
            <input
              type="checkbox"
              checked={form.data.status === 'active'}
              onChange={(e) =>
                form.setData('status', e.target.checked ? 'active' : 'disabled')
              }
            />
            {t('newsletters.active')}
          </label>
          <EditorActionButton disabled={form.processing}>
            {t('common.save')}
          </EditorActionButton>
          {editing && (
            <button type="button" className="button-secondary" onClick={done}>
              {t('common.cancel')}
            </button>
          )}
          <div className="w-full">
            <FormErrors errors={{ version: errors.version }} />
          </div>
        </form>
      )}
      <section
        className="list-card"
        aria-label={t('newsletters.campaigns_title')}
      >
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.name')}</th>
                <th>{t('newsletters.newsletters_title')}</th>
                <th>{t('newsletters.messages_sent')}</th>
                <th>{t('common.status')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {campaigns.data.length === 0 && (
                <tr>
                  <td colSpan={5} className="py-10 text-center text-slate-500">
                    {t('newsletters.campaigns_empty')}
                  </td>
                </tr>
              )}
              {campaigns.data.map((row) => (
                <tr key={row.id}>
                  <td className="text-sm font-medium">{row.name}</td>
                  <td className="text-sm tabular-nums">{row.newsletters}</td>
                  <td className="text-sm tabular-nums">{row.sent}</td>
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
                          onClick={() => edit(row)}
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
