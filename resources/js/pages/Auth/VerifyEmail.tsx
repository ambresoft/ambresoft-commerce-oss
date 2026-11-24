import { useTranslation } from '../../lib/useTranslation';
import { useForm, usePage } from '@inertiajs/react';
import SubmitButton from '../../components/auth/SubmitButton';
import AuthLayout from '../../layouts/auth/AuthLayout';
import type { SharedProps } from '../../types/page';

export default function VerifyEmail() {
  const t = useTranslation();
  const { auth, links } = usePage<SharedProps>().props;
  const resend = useForm({});
  const logout = useForm({});

  return (
    <AuthLayout
      title={t('auth.verify_title')}
      description={t('auth.verify_description')}
    >
      <p className="mb-6 break-words text-sm text-slate-700">
        {t('auth.verify_sent', { email: auth.user?.email ?? '' })}
      </p>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          resend.post(links['verification.send']);
        }}
      >
        <SubmitButton processing={resend.processing}>
          {t('auth.resend')}
        </SubmitButton>
      </form>
      <form
        className="mt-4 text-center"
        onSubmit={(e) => {
          e.preventDefault();
          logout.post(links.logout);
        }}
      >
        <button
          type="submit"
          className="text-link text-sm"
          disabled={logout.processing}
        >
          {t('navigation.sign_out')}
        </button>
      </form>
    </AuthLayout>
  );
}
