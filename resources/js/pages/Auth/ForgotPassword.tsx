import { useTranslation } from '../../lib/useTranslation';
import { Link, useForm, usePage } from '@inertiajs/react';
import FormField from '../../components/auth/FormField';
import SubmitButton from '../../components/auth/SubmitButton';
import AuthLayout from '../../layouts/auth/AuthLayout';
import type { SharedProps } from '../../types/page';

export default function ForgotPassword() {
  const t = useTranslation();
  const { links } = usePage<SharedProps>().props;
  const form = useForm({ email: '' });

  return (
    <AuthLayout
      title={t('auth.forgot_title')}
      description={t('auth.forgot_description')}
      footer={
        <Link className="text-link" href={links.login}>
          {t('auth.back')}
        </Link>
      }
    >
      <form
        className="space-y-5"
        onSubmit={(e) => {
          e.preventDefault();
          form.post(links['password.email']);
        }}
      >
        <FormField
          id="email"
          label={t('auth.email')}
          type="email"
          autoComplete="username"
          autoFocus
          required
          value={form.data.email}
          onChange={(e) => form.setData('email', e.target.value)}
          error={form.errors.email}
        />
        <SubmitButton processing={form.processing}>
          {t('auth.send_reset')}
        </SubmitButton>
      </form>
    </AuthLayout>
  );
}
