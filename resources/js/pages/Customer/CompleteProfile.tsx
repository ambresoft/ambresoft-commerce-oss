import { useTranslation } from '../../lib/useTranslation';
import { useForm, usePage } from '@inertiajs/react';
import FormField from '../../components/auth/FormField';
import SubmitButton from '../../components/auth/SubmitButton';
import AuthLayout from '../../layouts/auth/AuthLayout';
import type { SharedProps } from '../../types/page';

/** A verified identity without a customer profile (for example a staff member) names the profile once. */
export default function CompleteProfile() {
  const t = useTranslation();
  const { links } = usePage<SharedProps>().props;
  const form = useForm({ first_name: '', last_name: '' });
  const logout = useForm({});

  return (
    <AuthLayout
      title={t('auth.profile_title')}
      description={t('auth.profile_description')}
    >
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          form.post('/account/profile');
        }}
      >
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
        <SubmitButton processing={form.processing}>
          {t('auth.profile_save')}
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
