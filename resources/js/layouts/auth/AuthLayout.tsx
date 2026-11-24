import { useTranslation } from '../../lib/useTranslation';
import DocumentLocale from '../../components/DocumentLocale';
import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Notifications from '../../components/Notifications';
import type { SharedProps } from '../../types/page';
import DancingBull from '../../components/auth/DancingBull';
import StoreAuthLayout from './StoreAuthLayout';

export default function AuthLayout({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  const t = useTranslation();
  const { app, authArea } = usePage<SharedProps>().props;
  // Customers get the store's own look; staff keep the administration's.
  if (authArea === 'customer')
    return (
      <StoreAuthLayout title={title} description={description} footer={footer}>
        {children}
      </StoreAuthLayout>
    );
  const area = t('navigation.administration');

  return (
    <div className="auth-page flex min-h-dvh items-center justify-center bg-canvas px-4 py-6 sm:px-8 sm:py-12">
      <DocumentLocale />
      <Head title={`${title} · ${app.name}`} />
      <main
        id="main-content"
        className="grid w-full max-w-5xl overflow-hidden rounded-3xl border border-divider bg-surface shadow-[0_16px_64px_-32px_rgb(0_0_0_/_0.18)] md:grid-cols-[0.9fr_1.1fr]"
      >
        <div className="flex flex-col justify-between gap-8 bg-[#0c3026] px-7 py-8 text-on-primary sm:px-10 md:p-12">
          <img
            src="/brand/ambresoft-lockup-white.svg"
            alt="Ambresoft"
            width="416"
            height="100"
            className="h-auto w-44 md:w-56"
          />
          <DancingBull />
          <div className="hidden border-t border-on-primary/15 pt-7 md:block">
            <p className="text-2xl leading-snug font-medium tracking-tight break-words">
              {app.name}
            </p>
            <p className="mt-3 text-sm text-on-primary/65">{area}</p>
          </div>
        </div>
        <section
          className="min-w-0 px-6 py-8 sm:px-10 sm:py-10 md:p-12"
          aria-labelledby="auth-title"
        >
          <p className="mb-5 text-xs font-semibold tracking-[0.14em] text-muted uppercase md:hidden">
            {area}
          </p>
          <h1
            id="auth-title"
            className="text-[1.75rem] leading-tight font-semibold tracking-tight text-ink"
          >
            {title}
          </h1>
          <p className="mt-3 mb-8 text-sm leading-6 text-muted">
            {description}
          </p>
          <Notifications />
          {children}
          {footer && (
            <div className="mt-8 border-t border-divider pt-6 text-center text-sm leading-6 text-muted">
              {footer}
            </div>
          )}
        </section>
      </main>
    </div>
  );
}
