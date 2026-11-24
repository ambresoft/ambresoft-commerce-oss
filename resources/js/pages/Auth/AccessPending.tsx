import { useTranslation } from '../../lib/useTranslation';
import { Link, useForm, usePage } from '@inertiajs/react';
import AuthLayout from '../../layouts/auth/AuthLayout';
import type { SharedProps } from '../../types/page';

export default function AccessPending({
  passwordTooShort = false,
}: {
  passwordTooShort?: boolean;
}) {
  const t = useTranslation();
  const { auth, links } = usePage<SharedProps>().props;
  const logout = useForm({});

  return (
    <AuthLayout
      title={t('auth.pending_title')}
      description={t('auth.pending_description')}
    >
      <p className="text-sm leading-6 text-slate-600">
        {passwordTooShort
          ? t('auth.pending_password')
          : t('auth.pending_body', { email: auth.user?.email ?? '' })}
      </p>
      {passwordTooShort && (
        <Link
          className="text-link mt-4 inline-block text-sm"
          href="/admin/forgot-password"
        >
          {t('auth.forgot')}
        </Link>
      )}
      {auth.canAccessAdmin && (
        <Link className="button-primary mt-6" href={links['admin.dashboard']}>
          {t('auth.open_admin')}
        </Link>
      )}
      <form
        className="mt-6"
        onSubmit={(e) => {
          e.preventDefault();
          logout.post(links.logout);
        }}
      >
        <button
          className="button-secondary w-full"
          type="submit"
          disabled={logout.processing}
        >
          {t('navigation.sign_out')}
        </button>
      </form>
    </AuthLayout>
  );
}
