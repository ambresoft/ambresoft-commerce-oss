import { useTranslation } from '../../lib/useTranslation';
import { Link, useForm, usePage } from '@inertiajs/react';
import FormField from '../../components/auth/FormField';
import SocialButtons from '../../components/auth/SocialButtons';
import PasskeyButton from '../../components/auth/PasskeyButton';
import SubmitButton from '../../components/auth/SubmitButton';
import AuthLayout from '../../layouts/auth/AuthLayout';
import type { SharedProps } from '../../types/page';

export default function Login() {
  const t = useTranslation();
  const { links, authArea } = usePage<SharedProps>().props;
  const form = useForm({ email: '', password: '', remember: false });
  const customer = authArea === 'customer';

  return (
    <AuthLayout
      title={t('auth.login_title')}
      description={t(
        authArea === 'customer'
          ? 'auth.customer_login_description'
          : 'auth.login_description',
      )}
      footer={
        customer ? (
          <>
            {t('auth.new_here')}
            <Link className="store-secondary" href={links.register}>
              {t('auth.create_link')}
            </Link>
          </>
        ) : (
          <p>{t('auth.staff_invitation_only')}</p>
        )
      }
    >
      {customer && <SocialButtons />}
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          form.post(links['login.store'], {
            onFinish: () => form.reset('password'),
          });
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
        <FormField
          id="password"
          label={t('auth.password')}
          type="password"
          autoComplete="current-password"
          required
          revealable={customer}
          revealLabel={{
            show: t('auth.show_password'),
            hide: t('auth.hide_password'),
          }}
          labelAside={
            customer ? (
              <Link className="text-link" href={links['password.request']}>
                {t('auth.forgot')}
              </Link>
            ) : undefined
          }
          value={form.data.password}
          onChange={(e) => form.setData('password', e.target.value)}
          error={form.errors.password}
        />
        <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
          <label className="flex items-center gap-2 text-slate-600">
            <input
              className="size-4 rounded border-divider"
              type="checkbox"
              name="remember"
              checked={form.data.remember}
              onChange={(e) => form.setData('remember', e.target.checked)}
            />
            {t('auth.remember')}
          </label>
          {!customer && (
            <Link className="text-link" href={links['password.request']}>
              {t('auth.forgot')}
            </Link>
          )}
        </div>
        <SubmitButton processing={form.processing}>
          {t('auth.sign_in')}
        </SubmitButton>
      </form>
      {!customer && <PasskeyButton />}
    </AuthLayout>
  );
}
