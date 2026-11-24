import { useTranslation } from '../../lib/useTranslation';
import { Link, useForm, usePage } from '@inertiajs/react';
import FormField from '../../components/auth/FormField';
import SocialButtons from '../../components/auth/SocialButtons';
import SubmitButton from '../../components/auth/SubmitButton';
import AuthLayout from '../../layouts/auth/AuthLayout';
import type { SharedProps } from '../../types/page';

export default function CustomerRegister() {
  const t = useTranslation();
  const { links } = usePage<SharedProps>().props;
  const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    password_confirmation: '',
  });

  return (
    <AuthLayout
      title={t('auth.register_title')}
      description={t('auth.customer_register_description')}
      footer={
        <>
          {t('auth.already_registered')}{' '}
          <Link className="store-secondary" href={links.login}>
            {t('auth.sign_in')}
          </Link>
        </>
      }
    >
      <SocialButtons />
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          form.post(links['register.store'], {
            onFinish: () => form.reset('password', 'password_confirmation'),
          });
        }}
      >
        <ul className="store-auth-benefits">
          <li>{t('auth.benefit_orders')}</li>
          <li>{t('auth.benefit_checkout')}</li>
          <li>{t('auth.benefit_favourites')}</li>
        </ul>
        <div className="grid gap-5 sm:grid-cols-2">
          <FormField
            id="first_name"
            label={t('auth.first_name')}
            autoComplete="given-name"
            autoFocus
            required
            maxLength={60}
            value={form.data.first_name}
            onChange={(e) => form.setData('first_name', e.target.value)}
            error={form.errors.first_name}
          />
          <FormField
            id="last_name"
            label={t('auth.last_name')}
            autoComplete="family-name"
            required
            maxLength={60}
            value={form.data.last_name}
            onChange={(e) => form.setData('last_name', e.target.value)}
            error={form.errors.last_name}
          />
        </div>
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
          label={t('auth.password')}
          type="password"
          autoComplete="new-password"
          hint={t('auth.customer_password_hint')}
          required
          revealable
          revealLabel={{
            show: t('auth.show_password'),
            hide: t('auth.hide_password'),
          }}
          value={form.data.password}
          onChange={(e) => form.setData('password', e.target.value)}
          error={form.errors.password}
        />
        <FormField
          id="password_confirmation"
          label={t('auth.confirm')}
          type="password"
          autoComplete="new-password"
          required
          revealable
          revealLabel={{
            show: t('auth.show_password'),
            hide: t('auth.hide_password'),
          }}
          value={form.data.password_confirmation}
          onChange={(e) =>
            form.setData('password_confirmation', e.target.value)
          }
          error={form.errors.password_confirmation}
        />
        <SubmitButton processing={form.processing}>
          {t('auth.create')}
        </SubmitButton>
      </form>
    </AuthLayout>
  );
}
