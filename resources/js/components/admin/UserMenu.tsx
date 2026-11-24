import { useTranslation } from '../../lib/useTranslation';
import { Link, useForm, usePage } from '@inertiajs/react';
import type { SharedProps } from '../../types/page';
import Dropdown from './Dropdown';

export default function UserMenu() {
  const t = useTranslation();
  const { auth, links } = usePage<SharedProps>().props;
  const logout = useForm({});
  const user = auth.user;

  if (!user) return null;

  return (
    <Dropdown
      label={t('navigation.account', { name: user.name })}
      triggerClassName="admin-header-control size-9 justify-center text-sm font-medium"
      trigger={
        <>
          <span
            aria-hidden="true"
            className="flex size-7 items-center justify-center rounded-full bg-primary-tint text-[11px] font-semibold text-emerald-700"
          >
            {user.name.slice(0, 2).toUpperCase()}
          </span>
        </>
      }
    >
      {() => (
        <>
          <div className="border-b border-slate-100 px-3 py-2.5">
            <p className="truncate text-sm font-medium text-slate-900">
              {user.name}
            </p>
            <p className="mt-1 truncate text-xs text-slate-500">{user.email}</p>
          </div>
          <Link
            href="/admin/passkeys"
            className="block rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
          >
            {t('auth.passkeys')}
          </Link>
          <form
            className="pt-1"
            onSubmit={(e) => {
              e.preventDefault();
              logout.post(links.logout);
            }}
          >
            <button
              type="submit"
              disabled={logout.processing}
              className="w-full rounded-md px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
            >
              {t(
                logout.processing
                  ? 'navigation.signing_out'
                  : 'navigation.sign_out',
              )}
            </button>
          </form>
        </>
      )}
    </Dropdown>
  );
}
