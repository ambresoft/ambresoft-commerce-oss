import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import AdminCard from '../../../components/admin/AdminCard';
import { Icon } from '../../../components/admin/list/IconButton';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';

type Group = 'blocks' | 'products' | 'categories' | 'brands' | 'media';

export default function CacheSettings({
  settings,
  reachable,
  groups,
  ...shell
}: AdminShell & {
  settings: { enabled: boolean; version: number };
  reachable: boolean;
  groups: Group[];
}) {
  const t = useTranslation();
  const form = useForm(settings);
  const clear = useForm<{ group: Group | 'all' }>({ group: 'all' });
  const [notice, setNotice] = useState<{
    group: Group | 'all';
    message: string;
  } | null>(null);
  const busy = form.processing || clear.processing;
  function clearCache(group: Group | 'all') {
    clear.transform(() => ({ group }));
    clear.post('/admin/cache/clear', {
      preserveScroll: true,
      onStart: () => setNotice(null),
      onSuccess: (page) => {
        const flash = page.props.flash as { status: string | null };
        if (flash.status) setNotice({ group, message: flash.status });
      },
    });
  }
  return (
    <AdminLayout title={t('cache.title')} {...shell}>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          form.put('/admin/cache', {
            preserveScroll: true,
            onSuccess: (page) => {
              const saved = page.props.settings as typeof settings;
              form.setData(saved);
              form.setDefaults(saved);
            },
          });
        }}
      >
        <EditorHeader
          heading={t('cache.title')}
          iconKey="redis"
          description={t('cache.intro')}
          status={
            <EditorStatusSelect
              aria-label={t('cache.status')}
              value={form.data.enabled ? 'active' : 'disabled'}
              disabled={busy}
              options={[
                { value: 'active', label: t('cache.enabled') },
                { value: 'disabled', label: t('cache.disabled') },
              ]}
              onChange={(value) => form.setData('enabled', value === 'active')}
            />
          }
          actions={
            <button type="submit" className="button-primary" disabled={busy}>
              <Icon name="check" className="size-4" />
              {t('cache.save')}
            </button>
          }
        />
        {Object.values(form.errors).map((error) => (
          <p key={error} role="alert" className="mb-4 text-sm text-red-600">
            {error}
          </p>
        ))}
      </form>
      <div className="space-y-4">
        <AdminCard
          title={t('cache.settings')}
          icon={null}
          bodyClassName="space-y-3 p-5"
        >
          <p
            className={
              reachable ? 'text-sm text-primary' : 'text-sm text-amber-600'
            }
            role="status"
          >
            {t(reachable ? 'cache.connected' : 'cache.unavailable')}
          </p>
          <p className="text-sm text-slate-600">{t('cache.help')}</p>
          <p className="text-sm text-slate-600">{t('cache.live')}</p>
        </AdminCard>
        <AdminCard
          title={t('cache.groups')}
          icon={null}
          action={
            <button
              type="button"
              className="button-secondary"
              disabled={busy}
              onClick={() => clearCache('all')}
            >
              <Icon name="restore" className="size-4" />
              {t('cache.clear_all')}
            </button>
          }
          bodyClassName="p-5"
        >
          <p className="mb-4 text-sm text-slate-500">{t('cache.clear_help')}</p>
          {clear.errors.group && (
            <p role="alert" className="mb-4 text-sm text-red-600">
              {clear.errors.group}
            </p>
          )}
          <div className="divide-y divide-slate-200">
            {groups.map((group) => (
              <div
                key={group}
                className="flex flex-wrap items-center justify-between gap-4 py-4"
              >
                <div className="min-w-0 flex-1">
                  <h3 className="font-semibold">{t('cache.' + group)}</h3>
                  <p className="mt-1 text-sm text-slate-500">
                    {t('cache.' + group + '_help')}
                  </p>
                </div>
                <div className="flex flex-col items-end gap-2">
                  <button
                    type="button"
                    className="button-secondary shrink-0"
                    aria-label={t('cache.clear_group', {
                      group: t('cache.' + group),
                    })}
                    disabled={busy}
                    onClick={() => clearCache(group)}
                  >
                    {t('cache.clear')}
                  </button>
                  {notice?.group === group && (
                    <p role="status" className="text-sm text-primary">
                      {notice.message}
                    </p>
                  )}
                </div>
              </div>
            ))}
          </div>
        </AdminCard>
      </div>
    </AdminLayout>
  );
}
