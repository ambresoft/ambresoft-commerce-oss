import { useTranslation } from '../../lib/useTranslation';
import { Link, useForm, usePage } from '@inertiajs/react';
import FormField from '../../components/auth/FormField';
import SubmitButton from '../../components/auth/SubmitButton';
import AuthLayout from '../../layouts/auth/AuthLayout';
import type { SharedProps } from '../../types/page';
import PasskeyButton from '../../components/auth/PasskeyButton';

export default function Register({
  invitation,
}: {
  invitation?: { name: string; email: string; submitUrl: string };
}) {
  const t = useTranslation();
  const { links } = usePage<SharedProps>().props;
  const form = useForm({
    name: invitation?.name ?? '',
    email: invitation?.email ?? '',
    password: '',
    password_confirmation: '',
  });

  return (
    <AuthLayout
      title={t('auth.register_title')}
      description={t('auth.staff_invitation_intro')}
      footer={
        <>
          {t('auth.already_registered')}{' '}
          <Link className="text-link" href={links.login}>
            {t('auth.sign_in')}
          </Link>
        </>
      }
    >
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          form.post(invitation?.submitUrl ?? links['register.store'], {
            onFinish: () => form.reset('password', 'password_confirmation'),
          });
        }}
      >
        <FormField
          id="name"
          label={t('auth.full_name')}
          autoComplete="name"
          autoFocus
          required
          readOnly={Boolean(invitation)}
          value={form.data.name}
          onChange={(e) => form.setData('name', e.target.value)}
          error={form.errors.name}
        />
        <FormField
          id="email"
          label={t('auth.email')}
          type="email"
          readOnly={Boolean(invitation)}
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
          hint={t('auth.password_hint')}
          required
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
      {invitation && <PasskeyButton registrationUrl={invitation.submitUrl} />}
    </AuthLayout>
  );
}
