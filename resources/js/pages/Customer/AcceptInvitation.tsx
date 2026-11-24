import { useForm } from '@inertiajs/react';
import FormField from '../../components/auth/FormField';
import SubmitButton from '../../components/auth/SubmitButton';
import AuthLayout from '../../layouts/auth/AuthLayout';
import { useTranslation } from '../../lib/useTranslation';

/** Setting the first password from a staff invitation; the signed URL (with its query) is the form target. */
export default function AcceptInvitation({ email }: { email: string }) {
  const t = useTranslation();
  const form = useForm({ password: '', password_confirmation: '' });

  return (
    <AuthLayout
      title={t('account.invitation_title')}
      description={t('account.invitation_description', { email })}
    >
      <form
        className="space-y-5"
        onSubmit={(e) => {
          e.preventDefault();
          form.post(window.location.pathname + window.location.search, {
            onFinish: () => form.reset(),
          });
        }}
      >
        <FormField
          id="password"
          label={t('auth.password')}
          type="password"
          autoComplete="new-password"
          hint={t('auth.customer_password_hint')}
          autoFocus
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
          {t('account.invitation_action')}
        </SubmitButton>
      </form>
    </AuthLayout>
  );
}
