import { useTranslation } from '../lib/useTranslation';
import DocumentLocale from '../components/DocumentLocale';
type FoundationProps = { applicationName: string };

export default function Foundation({ applicationName }: FoundationProps) {
  const t = useTranslation();
  return (
    <main className="mx-auto max-w-2xl px-6 py-20 text-slate-900">
      <DocumentLocale />
      <p className="text-sm font-medium text-slate-600">
        {t('navigation.administration')}
      </p>
      <h1 className="mt-3 text-3xl font-semibold">{applicationName}</h1>
      <p className="mt-6 leading-relaxed">
        {t('auth.customer_login_description')}
      </p>
      <div className="mt-6 flex flex-wrap gap-3">
        <a className="button-primary" href="/login">
          {t('auth.sign_in')}
        </a>
        <a className="button-secondary" href="/admin/login">
          {t('auth.staff_sign_in')}
        </a>
      </div>
    </main>
  );
}
