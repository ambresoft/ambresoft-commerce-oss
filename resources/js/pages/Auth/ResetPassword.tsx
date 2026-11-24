import { useTranslation } from '../../lib/useTranslation';
import { Link, useForm, usePage } from '@inertiajs/react';
import FormField from '../../components/auth/FormField';
import SubmitButton from '../../components/auth/SubmitButton';
import AuthLayout from '../../layouts/auth/AuthLayout';
import type { SharedProps } from '../../types/page';

export default function ResetPassword({
  token,
  email,
}: {
  token: string;
  email: string;
}) {
  const t = useTranslation();
  const { links, authArea } = usePage<SharedProps>().props;
  const form = useForm({
    token,
    email,
    password: '',
    password_confirmation: '',
  });

  return (
    <AuthLayout
      title={t('auth.reset_title')}
      description={t('auth.reset_description')}
      footer={
        <Link className="text-link" href={links['password.request']}>
          {t('auth.request_reset')}
        </Link>
      }
    >
      <form
        className="space-y-5"
        onSubmit={(e) => {
          e.preventDefault();
          form.post(links['password.update'], {
            onFinish: () => form.reset('password', 'password_confirmation'),
          });
        }}
      >
        <FormField
          id="email"
          label={t('auth.email')}
          type="email"
          autoComplete="username"
          required
          value={form.data.email}
          onChange={(e) => form.setData('email', e.target.value)}
          error={form.errors.email}
        />
        <FormField
          id="password"
          label={t('auth.new_password')}
          type="password"
          autoComplete="new-password"
          autoFocus
          hint={t(
            authArea === 'customer'
              ? 'auth.customer_password_hint'
              : 'auth.password_hint',
          )}
          required
          value={form.data.password}
          onChange={(e) => form.setData('password', e.target.value)}
          error={form.errors.password}
        />
        <FormField
          id="password_confirmation"
          label={t('auth.confirm_new')}
          type="password"
          autoComplete="new-password"
          required
          value={form.data.password_confirmation}
          onChange={(e) =>
            form.setData('password_confirmation', e.target.value)
          }
          error={form.errors.password_confirmation}
        />
        {form.errors.token && (
          <p role="alert" className="text-sm text-red-700">
            {form.errors.token}
          </p>
        )}
        <SubmitButton processing={form.processing}>
          {t('auth.reset')}
        </SubmitButton>
      </form>
    </AuthLayout>
  );
}
