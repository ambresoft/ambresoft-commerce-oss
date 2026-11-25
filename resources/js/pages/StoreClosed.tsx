import { Head } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import DocumentLocale from '../components/DocumentLocale';
import AddonStyles from '../storefront/components/AddonStyles';
import { useTranslation } from '../lib/useTranslation';

/** Shown instead of the storefront while the store is closed: the store's own name, logo and colours, never the platform's. */
export default function StoreClosed({
  storeName,
  logo,
  colors,
  message,
}: {
  storeName: string;
  logo: { url: string; width: number; height: number } | null;
  colors: {
    primary: string;
    on_primary?: string;
    background: string;
    text: string;
  };
  message: string;
}) {
  const t = useTranslation();
  return (
    <main
      className="store-closed flex min-h-dvh flex-col text-center"
      style={
        {
          backgroundColor: colors.background,
          color: colors.text,
          '--closed-primary': colors.primary,
        } as CSSProperties
      }
    >
      <DocumentLocale />
      <AddonStyles />
      <Head title={storeName} />
      {/* The header's colours, so the logo made for the storefront header stays readable. */}
      <header
        className="flex justify-center px-6 py-5"
        style={{
          backgroundColor: colors.primary,
          color: colors.on_primary ?? '#ffffff',
        }}
      >
        {logo ? (
          <img
            src={logo.url}
            width={logo.width}
            height={logo.height}
            alt={storeName}
            className="h-auto max-h-12 w-auto max-w-[220px] object-contain"
          />
        ) : (
          <p className="text-xl font-bold tracking-tight">{storeName}</p>
        )}
      </header>
      <div className="mx-auto flex w-full max-w-xl flex-1 flex-col items-center justify-center px-6 py-16">
        <span
          aria-hidden="true"
          className="mb-8 block h-1 w-14 rounded-full bg-(--closed-primary)"
        />
        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
          {t('settings.closed_title')}
        </h1>
        <p className="mt-5 text-base leading-relaxed whitespace-pre-wrap opacity-80 sm:text-lg">
          {message}
        </p>
      </div>
      <p className="pb-8 text-xs opacity-60">
        © {new Date().getFullYear()} {storeName}
      </p>
    </main>
  );
}
