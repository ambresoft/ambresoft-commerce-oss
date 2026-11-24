import { Head, Link, usePage } from '@inertiajs/react';
import type { SharedProps } from '../types/page';
import { useTranslation } from '../lib/useTranslation';
import DocumentLocale from '../components/DocumentLocale';

export default function ErrorPage({
  status,
}: {
  status: 403 | 419 | 429;
  retryAfter: string | null;
}) {
  const t = useTranslation();
  const { links, authArea } = usePage<SharedProps>().props;
  const customer = authArea === 'customer';
  const title = t('admin.error.' + status + '.title');
  const description = t('admin.error.' + status + '.description');

  return (
    <main className="mx-auto max-w-lg px-6 py-20">
      <DocumentLocale />
      <Head title={title} />
      <p className="text-sm font-medium text-slate-500">{status}</p>
      <h1 className="mt-3 text-2xl font-semibold text-slate-950">{title}</h1>
      <p role="alert" className="mt-4 text-sm leading-6 text-slate-600">
        {description}
      </p>
      <Link
        className="button-primary mt-6"
        href={
          status === 403
            ? customer
              ? links.account
              : links['access.pending']
            : links.login
        }
      >
        {t(status === 403 ? 'admin.error.account' : 'admin.error.login')}
      </Link>
    </main>
  );
}
