import EditorHeader from '../../../components/admin/EditorHeader';
import { router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import Switch from '../../../components/admin/Switch';
import StatusBadge, {
  type Tone,
} from '../../../components/admin/list/StatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { AddonRow } from '../../../types/payments';

const statusTone: Record<AddonRow['status'], Tone> = {
  enabled: 'success',
  disabled: 'neutral',
  not_installed: 'neutral',
};

// Literal keys (translation keys are never built at runtime).
const statusLabel: Record<AddonRow['status'], string> = {
  enabled: 'addons.status_enabled',
  disabled: 'addons.status_disabled',
  not_installed: 'addons.status_not_installed',
};

type Action = keyof AddonRow['actions'];
const actionLabel: Record<Action, string> = {
  install: 'addons.install',
  enable: 'addons.enable',
  disable: 'addons.disable',
  upgrade: 'addons.upgrade',
  uninstall: 'addons.uninstall',
};

/** The add-on's own icon on a light tile (so dark logos stay visible in the dark theme), or a puzzle piece. */
function AddonIcon({ addon }: { addon: AddonRow }) {
  return (
    <span
      aria-hidden="true"
      className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white p-1.5 text-slate-500"
    >
      {addon.icon_url ? (
        <img
          src={addon.icon_url}
          alt=""
          className="size-full object-contain"
          loading="lazy"
        />
      ) : (
        <span className="size-6">
          <Icon name="puzzle" />
        </span>
      )}
    </span>
  );
}

export default function AddonsIndex({
  addons,
  cmsVersion,
  uploads,
  ...shell
}: AdminShell & {
  addons: AddonRow[];
  cmsVersion: string;
  uploads: { enabled: boolean; allowed: boolean; maxMb: number };
}) {
  const t = useTranslation();
  const { errors } = usePage<SharedProps & { errors: Record<string, string> }>()
    .props;
  const dialog = useRef<HTMLDialogElement>(null);
  const picker = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);
  const [fileError, setFileError] = useState<string | null>(null);
  const form = useForm<{ package: File | null; upgrade: boolean }>({
    package: null,
    upgrade: false,
  });
  const file = form.data.package;
  // The server validates the archive; this only spares an obviously wrong or oversized pick a round trip.
  const choose = (picked: File | null) => {
    setFileError(null);
    if (picked && !/\.zip$/i.test(picked.name)) {
      setFileError(t('addons.upload_not_zip'));
      return;
    }
    if (picked && picked.size > uploads.maxMb * 1048576) {
      setFileError(
        t('addons.upload_too_large', { max: String(uploads.maxMb) }),
      );
      return;
    }
    form.setData('package', picked);
    if (picker.current && picked === null) picker.current.value = '';
  };
  const run = (addon: AddonRow, action: Action) => {
    const url = '/admin/addons/' + addon.name;
    if (action === 'uninstall') {
      if (window.confirm(t('addons.uninstall_confirm', { name: addon.title })))
        router.delete(url, { preserveScroll: true });
      return;
    }
    router.post(url + '/' + action, {}, { preserveScroll: true });
  };

  return (
    <AdminLayout title={t('addons.title')} {...shell}>
      <EditorHeader
        heading={t('addons.title')}
        iconKey="core.addons"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">{t('addons.intro')}</p>
            <p className="mt-1 text-xs text-slate-400">
              {t('addons.cms_version', { version: cmsVersion })}
              {uploads.allowed &&
                !uploads.enabled &&
                ' · ' + t('addons.uploads_disabled')}
            </p>
          </>
        }
        actions={
          <>
            {uploads.allowed && uploads.enabled && (
              <button
                type="button"
                className="button-primary gap-2"
                onClick={() => dialog.current?.showModal()}
              >
                <Icon name="plus" />
                {t('addons.upload_title')}
              </button>
            )}
          </>
        }
      />
      <div className="mb-4">
        <FormErrors errors={errors ?? {}} />
      </div>
      <section className="list-card mb-8" aria-label={t('addons.title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('addons.name')}</th>
                <th>{t('addons.status')}</th>
                <th className="text-right">{t('addons.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {addons.map((addon) => {
                const actions = (Object.keys(actionLabel) as Action[]).filter(
                  (action) => addon.actions[action],
                );
                return (
                  <tr key={addon.name}>
                    <td className="text-sm">
                      <span className="flex items-start gap-3">
                        <AddonIcon addon={addon} />
                        <span className="min-w-0">
                          <span className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{addon.title}</span>
                            <span
                              className="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600"
                              title={
                                addon.source === 'bundled'
                                  ? t('addons.bundled_hint')
                                  : undefined
                              }
                            >
                              {t(
                                addon.source === 'bundled'
                                  ? 'addons.bundled'
                                  : 'addons.uploaded',
                              )}
                            </span>
                          </span>
                          <span className="mt-0.5 block font-mono text-xs text-slate-500">
                            {addon.name}
                            {addon.version &&
                              ' · ' +
                                t('addons.version', { version: addon.version })}
                            {addon.author &&
                              ' · ' +
                                t('addons.author', { author: addon.author })}
                          </span>
                          {addon.description && (
                            <span className="mt-1 block max-w-xl text-xs text-slate-600">
                              {addon.description}
                            </span>
                          )}
                          {(addon.contributes['payment-methods'] ?? []).length >
                            0 && (
                            <span className="mt-1 block text-xs text-slate-500">
                              {t('addons.provides_payment', {
                                list: addon.contributes[
                                  'payment-methods'
                                ]!.join(', '),
                              })}
                            </span>
                          )}
                          {addon.requires.length > 0 && (
                            <span className="mt-1 block text-xs text-slate-500">
                              {t('addons.requires', {
                                list: addon.requires.join(', '),
                              })}
                            </span>
                          )}
                          {!addon.compatible && addon.cms && (
                            <span className="mt-1 block text-xs text-amber-700">
                              {t('addons.incompatible', {
                                constraint: addon.cms,
                                version: cmsVersion,
                              })}
                            </span>
                          )}
                          {addon.error && (
                            <span className="mt-1 block text-xs text-red-700">
                              {addon.error}
                            </span>
                          )}
                        </span>
                      </span>
                    </td>
                    <td className="text-sm">
                      {addon.status === 'not_installed' ? (
                        <StatusBadge tone={statusTone[addon.status]}>
                          {t(statusLabel[addon.status])}
                        </StatusBadge>
                      ) : (
                        <span className="flex items-center gap-2">
                          <Switch
                            checked={addon.status === 'enabled'}
                            disabled={
                              !addon.actions.enable && !addon.actions.disable
                            }
                            label={
                              t(
                                addon.status === 'enabled'
                                  ? 'addons.disable'
                                  : 'addons.enable',
                              ) +
                              ': ' +
                              addon.title
                            }
                            onChange={(on) =>
                              run(addon, on ? 'enable' : 'disable')
                            }
                          />
                          <span
                            className={
                              addon.status === 'enabled'
                                ? 'text-slate-700'
                                : 'text-slate-500'
                            }
                          >
                            {t(statusLabel[addon.status])}
                          </span>
                        </span>
                      )}
                      {addon.installed_version &&
                        addon.installed_version !== addon.version && (
                          <span className="mt-1 block text-xs text-slate-500">
                            {t('addons.installed_version', {
                              version: addon.installed_version,
                            })}
                          </span>
                        )}
                      {addon.upgrade_available && addon.version && (
                        <span className="mt-1 block text-xs text-amber-700">
                          {t('addons.upgrade_available', {
                            version: addon.version,
                          })}
                        </span>
                      )}
                    </td>
                    <td>
                      <span className="flex flex-wrap items-center justify-end gap-2">
                        {addon.settings.map((link) => (
                          <IconButton
                            key={link.href}
                            icon="settings"
                            href={link.href}
                            label={
                              addon.settings.length > 1
                                ? t('addons.settings') + ': ' + link.name
                                : t('addons.settings')
                            }
                          />
                        ))}
                        {actions
                          .filter((a) => a !== 'enable' && a !== 'disable')
                          .map((action) => (
                            <button
                              key={action}
                              type="button"
                              className={
                                action === 'install' || action === 'upgrade'
                                  ? 'button-primary'
                                  : action === 'uninstall'
                                    ? 'button-secondary text-red-700'
                                    : 'button-secondary'
                              }
                              onClick={() => run(addon, action)}
                            >
                              {t(actionLabel[action])}
                            </button>
                          ))}
                      </span>
                    </td>
                  </tr>
                );
              })}
              {addons.length === 0 && (
                <tr>
                  <td colSpan={3} className="py-6 text-center text-slate-500">
                    {t('addons.empty')}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
      {uploads.allowed && uploads.enabled && (
        <dialog
          ref={dialog}
          aria-labelledby="upload-heading"
          onClose={() => {
            form.reset();
            setDragging(false);
            setFileError(null);
          }}
          className="m-auto w-[min(34rem,95vw)] rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-2xl backdrop:bg-black/40 backdrop:backdrop-blur-sm"
        >
          <form
            className="space-y-4"
            onSubmit={(event) => {
              event.preventDefault();
              form.post('/admin/addons/upload', {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => dialog.current?.close(),
              });
            }}
          >
            <div className="flex items-start gap-3">
              <span
                aria-hidden="true"
                className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary-tint p-2.5 text-emerald-700"
              >
                <Icon name="puzzle" />
              </span>
              <div className="min-w-0 flex-1">
                <h2
                  id="upload-heading"
                  className="text-lg leading-6 font-semibold"
                >
                  {t('addons.upload_title')}
                </h2>
                <p className="text-sm text-slate-500">
                  {t('addons.upload_subtitle')}
                </p>
              </div>
              <button
                type="button"
                aria-label={t('common.close')}
                title={t('common.close')}
                className="button-secondary px-2.5 py-2"
                onClick={() => dialog.current?.close()}
              >
                <span className="block size-4">
                  <Icon name="x" />
                </span>
              </button>
            </div>
            <div
              role="note"
              className="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
            >
              <span aria-hidden="true" className="mt-0.5 size-5 shrink-0">
                <Icon name="shield" />
              </span>
              <p>
                <strong className="block">
                  {t('addons.upload_warning_title')}
                </strong>
                {t('addons.upload_warning')}
              </p>
            </div>
            <FormErrors
              errors={{
                ...form.errors,
                ...(fileError ? { package: fileError } : {}),
              }}
            />
            <input
              id="package"
              ref={picker}
              type="file"
              accept=".zip,application/zip"
              aria-label={t('addons.upload_file')}
              className="sr-only"
              onChange={(e) => choose(e.target.files?.[0] ?? null)}
            />
            {file === null ? (
              <div
                onDragOver={(e) => {
                  e.preventDefault();
                  setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                  e.preventDefault();
                  setDragging(false);
                  choose(e.dataTransfer.files?.[0] ?? null);
                }}
                className={
                  'flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed px-4 py-8 text-center ' +
                  (dragging
                    ? 'border-emerald-500 bg-primary-tint'
                    : 'border-slate-200')
                }
              >
                <span
                  aria-hidden="true"
                  className="flex size-14 items-center justify-center rounded-2xl bg-primary-tint p-3.5 text-emerald-700"
                >
                  <Icon name="upload" />
                </span>
                <p className="font-semibold">{t('addons.upload_drop')}</p>
                <p className="text-sm text-slate-500">
                  {t('addons.upload_or')}
                </p>
                <button
                  type="button"
                  className="button-secondary gap-2"
                  onClick={() => picker.current?.click()}
                >
                  <span aria-hidden="true" className="size-4">
                    <Icon name="upload" />
                  </span>
                  {t('addons.upload_choose')}
                </button>
                <ul className="mt-2 flex flex-wrap justify-center gap-2 text-xs text-slate-600">
                  {[
                    '.zip',
                    t('addons.upload_tag_manifest'),
                    t('addons.upload_tag_max', { max: String(uploads.maxMb) }),
                  ].map((tag) => (
                    <li
                      key={tag}
                      className="rounded-full border border-slate-200 px-3 py-1"
                    >
                      {tag}
                    </li>
                  ))}
                </ul>
              </div>
            ) : (
              <div className="flex items-center gap-3 rounded-xl border border-emerald-200 bg-primary-tint/40 p-3">
                <span
                  aria-hidden="true"
                  className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary-tint p-2.5 text-emerald-700"
                >
                  <Icon name="file" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate font-medium">{file.name}</p>
                  <p className="text-sm text-slate-500">
                    {(file.size / 1048576).toLocaleString(undefined, {
                      maximumFractionDigits: 1,
                    })}{' '}
                    MB ·{' '}
                    <span className="text-emerald-700">
                      ✓ {t('addons.upload_ready')}
                    </span>
                  </p>
                </div>
                <button
                  type="button"
                  className="button-secondary"
                  onClick={() => picker.current?.click()}
                >
                  {t('addons.upload_change')}
                </button>
                <button
                  type="button"
                  aria-label={t('addons.upload_remove')}
                  title={t('addons.upload_remove')}
                  className="button-secondary px-2.5 py-2"
                  onClick={() => choose(null)}
                >
                  <span className="block size-4">
                    <Icon name="x" />
                  </span>
                </button>
              </div>
            )}
            <div className="flex items-center gap-3 rounded-xl border border-slate-200 p-4">
              <Switch
                checked={form.data.upgrade}
                label={t('addons.upload_upgrade')}
                onChange={(value) => form.setData('upgrade', value)}
              />
              <div className="text-sm">
                <p className="font-semibold">{t('addons.upload_upgrade')}</p>
                <p className="text-slate-500">
                  {t('addons.upload_upgrade_hint')}
                </p>
              </div>
            </div>
            <div className="flex justify-end gap-2 pt-1">
              <button
                type="button"
                className="button-secondary"
                onClick={() => dialog.current?.close()}
              >
                {t('common.cancel')}
              </button>
              <button
                className="button-primary gap-2"
                disabled={form.processing || file === null}
              >
                <span aria-hidden="true" className="size-4">
                  <Icon name="upload" />
                </span>
                {t(form.processing ? 'common.wait' : 'addons.upload_button')}
              </button>
            </div>
          </form>
        </dialog>
      )}
    </AdminLayout>
  );
}
