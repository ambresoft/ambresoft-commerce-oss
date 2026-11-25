import { Head, usePage } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import DocumentLocale from '../../components/DocumentLocale';
import Notifications from '../../components/Notifications';
import AddonStyles from '../../storefront/components/AddonStyles';
import type { SharedProps } from '../../types/page';

/** Customer sign-in, registration and password pages in the store's colors under a slim logo header. */
export default function StoreAuthLayout({
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
  const { app, storeBrand } = usePage<SharedProps>().props;
  const name = storeBrand?.name ?? app.name;
  const colors = storeBrand?.colors;
  const style = colors
    ? ({
        '--sf-primary': colors.primary,
        '--sf-primary-hover': colors.primary_hover,
        '--sf-on-primary': colors.on_primary,
        '--sf-text': colors.text,
        '--sf-background': colors.background,
        '--sf-sale': colors.sale,
      } as CSSProperties)
    : undefined;
  return (
    <div className="store-auth" style={style}>
      <AddonStyles />
      <DocumentLocale />
      <Head title={`${title} · ${name}`} />
      <header
        className="store-auth-header"
        style={
          {
            '--store-auth-header-bg': storeBrand?.auth_header.background,
            '--store-auth-header-text': storeBrand?.auth_header.text,
          } as CSSProperties
        }
      >
        <a href="/" className="store-auth-brand" aria-label={name}>
          {storeBrand?.logo ? <img src={storeBrand.logo} alt={name} /> : name}
        </a>
      </header>
      <main id="main-content" className="store-auth-main">
        <section className="store-auth-card" aria-labelledby="auth-title">
          <h1 id="auth-title">{title}</h1>
          <p className="store-auth-lead">{description}</p>
          <Notifications />
          {children}
          {footer && <div className="store-auth-footer">{footer}</div>}
        </section>
      </main>
      <footer className="store-auth-bottom">
        © {new Date().getFullYear()} {name}
      </footer>
    </div>
  );
}
