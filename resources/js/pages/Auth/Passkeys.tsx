import { useRef, useState } from 'react';
import AdminLayout from '../../layouts/admin/AdminLayout';
import type { AdminShell } from '../../types/admin';
import { useTranslation } from '../../lib/useTranslation';
import { useDateDisplay } from '../../lib/useDateDisplay';
import FormField from '../../components/auth/FormField';
import Dropdown from '../../components/admin/Dropdown';
import { faqRequest } from '../../lib/faqRequest';
import { performPasskey } from '../../lib/passkeys';

export default function Passkeys({
  passkeys,
  ...shell
}: AdminShell & {
  passkeys: { id: number; created_at: string }[];
}) {
  const t = useTranslation();
  const { date } = useDateDisplay();
  const dialog = useRef<HTMLDialogElement>(null);
  const listTitle = useRef<HTMLHeadingElement>(null);
  const [removing, setRemoving] = useState<number | null>(null);
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const open = (id: number | null) => {
    setRemoving(id);
    setPassword('');
    setError('');
    dialog.current?.showModal();
  };
  const close = () => {
    if (busy) return;
    dialog.current?.close();
    if (removing !== null) listTitle.current?.focus();
    setPassword('');
    setError('');
  };
  return (
    <AdminLayout title={t('auth.passkeys')} {...shell}>
      <div className="max-w-2xl">
        <h1 className="text-2xl font-semibold">{t('auth.passkeys')}</h1>
        <p className="mt-2 text-sm text-muted">
          {t('auth.passkey_description')}
        </p>

        <section className="mt-5 flex items-start gap-4 rounded-lg border border-divider p-5">
          <span
            className="flex size-12 shrink-0 items-center justify-center rounded-lg bg-primary/8 text-[#76b38e]"
            aria-hidden="true"
          >
            <svg
              className="size-7"
              viewBox="0 0 32 32"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M20 3a9 9 0 0 0-8.2 12.7L4 23.5V29h5v-4h4v-4l3.3-3.3A9 9 0 1 0 20 3Z" />
              <circle cx="22" cy="9" r="2" />
            </svg>
          </span>
          <div className="min-w-0">
            <h2 className="text-base font-semibold">
              {t('auth.passkey_add_title')}
            </h2>
            <p className="mt-1.5 max-w-lg text-sm leading-6 text-muted">
              {t('auth.passkey_add_description')}
            </p>
            <button
              type="button"
              className="mt-4 inline-flex min-h-9 items-center justify-center rounded-md border border-[#d9ddda] bg-[#f5f6f5] px-3.5 py-1.5 text-sm font-semibold text-[#242825] shadow-sm transition-colors hover:bg-[#e5e9e6]"
              onClick={() => open(null)}
            >
              {t('auth.passkey_add')}
            </button>
          </div>
        </section>

        <section className="mt-7" aria-labelledby="passkey-list-title">
          <h2
            ref={listTitle}
            tabIndex={-1}
            id="passkey-list-title"
            className="text-lg font-semibold"
          >
            {t('auth.passkey_yours')}
          </h2>
          <p className="mt-1 text-sm text-muted">
            {t(
              passkeys.length === 1
                ? 'auth.passkey_count_one'
                : 'auth.passkey_count',
              { count: passkeys.length },
            )}
          </p>
          <ul className="mt-4 space-y-3">
            {passkeys.map((passkey) => (
              <li
                key={passkey.id}
                className="flex items-center gap-4 rounded-lg border border-divider p-4"
              >
                <span
                  className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-muted"
                  aria-hidden="true"
                >
                  <svg
                    className="size-6"
                    viewBox="0 0 28 28"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.6"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  >
                    <rect x="5" y="5" width="18" height="15" rx="1.5" />
                    <path d="m5 20-3 3h24l-3-3M11 23h6" />
                  </svg>
                </span>
                <div className="min-w-0 flex-1">
                  <h3 className="text-base font-medium">
                    {t('auth.passkey_item', { id: String(passkey.id) })}
                  </h3>
                  <p className="mt-1 text-sm text-muted">
                    {t('auth.passkey_added', {
                      date: date.format(new Date(passkey.created_at)),
                    })}
                  </p>
                </div>
                <span className="hidden items-center gap-2 text-sm font-medium text-[#76b38e] sm:inline-flex">
                  <span
                    className="size-2 rounded-full bg-current"
                    aria-hidden="true"
                  />
                  {t('auth.passkey_active')}
                </span>
                <Dropdown
                  label={t('auth.passkey_actions', { id: String(passkey.id) })}
                  triggerClassName="flex size-9 shrink-0 items-center justify-center rounded-md border border-divider text-muted transition-colors hover:bg-slate-100"
                  panelClassName="w-48 p-1.5"
                  trigger={
                    <svg
                      className="size-5"
                      viewBox="0 0 20 20"
                      fill="currentColor"
                      aria-hidden="true"
                    >
                      <circle cx="4" cy="10" r="1.2" />
                      <circle cx="10" cy="10" r="1.2" />
                      <circle cx="16" cy="10" r="1.2" />
                    </svg>
                  }
                >
                  {(dismiss) => (
                    <button
                      type="button"
                      className="w-full rounded-md px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50"
                      onClick={() => {
                        dismiss();
                        open(passkey.id);
                      }}
                    >
                      {t('common.delete')}
                    </button>
                  )}
                </Dropdown>
              </li>
            ))}
          </ul>
          {passkeys.length === 0 && (
            <p className="mt-4 rounded-lg border border-dashed border-divider p-6 text-sm text-muted">
              {t('auth.passkey_empty')}
            </p>
          )}
        </section>

        <p className="mt-6 flex items-start gap-2 text-sm leading-6 text-muted">
          <svg
            className="mt-0.5 size-5 shrink-0"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6l9-4Z" />
            <path d="m8 12 3 3 5-6" />
          </svg>
          {t('auth.passkey_security')}
        </p>
      </div>

      <dialog
        ref={dialog}
        aria-labelledby="passkey-dialog-title"
        className="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-divider bg-surface p-6 text-ink shadow-2xl backdrop:bg-black/60 backdrop:backdrop-blur-sm"
        onCancel={(event) => {
          event.preventDefault();
          close();
        }}
        onClick={(event) => {
          if (event.target === dialog.current) close();
        }}
      >
        <form
          className="space-y-5"
          onSubmit={async (event) => {
            event.preventDefault();
            if (busy || !password) return;
            setBusy(true);
            setError('');
            try {
              const result =
                removing === null
                  ? await performPasskey(
                      '/admin/passkeys/options',
                      '/admin/passkeys',
                      true,
                      { operation: 'passkey_options', password },
                    )
                  : await faqRequest<{ redirect: string }>(
                      `/admin/passkeys/${removing}/delete`,
                      { password },
                    );
              window.location.assign(result.redirect);
            } catch (failure) {
              setError(
                t(
                  failure instanceof Error &&
                    failure.message === 'passkey_unavailable'
                    ? 'auth.passkey_unavailable'
                    : 'auth.passkey_failed',
                ),
              );
            } finally {
              setBusy(false);
            }
          }}
        >
          <h2 id="passkey-dialog-title" className="text-xl font-semibold">
            {removing === null
              ? t('auth.passkey_add')
              : t('auth.passkey_remove_title')}
          </h2>
          {removing !== null && (
            <p className="text-sm text-muted">
              {t('auth.passkey_item', { id: String(removing) })}
            </p>
          )}
          <p className="text-sm leading-6 text-muted">
            {t('auth.passkey_manage_hint')}
          </p>
          <FormField
            id="password"
            label={t('auth.password')}
            type="password"
            autoComplete="current-password"
            required
            maxLength={128}
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            disabled={busy}
          />
          {error && (
            <p role="alert" className="text-sm text-red-700">
              {error}
            </p>
          )}
          <div className="flex justify-end gap-2">
            <button
              type="button"
              className="button-secondary"
              disabled={busy}
              onClick={close}
            >
              {t('common.cancel')}
            </button>
            <button
              type="submit"
              className={
                removing === null
                  ? 'button-primary'
                  : 'button-secondary border-red-600 text-red-700 hover:bg-red-50'
              }
              disabled={busy || !password}
            >
              {t(
                busy
                  ? 'common.wait'
                  : removing === null
                    ? 'auth.passkey_add'
                    : 'common.delete',
              )}
            </button>
          </div>
        </form>
      </dialog>
    </AdminLayout>
  );
}
