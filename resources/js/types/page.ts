export type AuthUser = {
  id: string;
  name: string;
  email: string;
  emailVerified: boolean;
};

export type SharedProps = {
  [key: string]: unknown;
  searchHealth?: {
    status: 'healthy' | 'pending' | 'attention';
    pending: number;
    hidden: number;
    errors: number;
    failed: number;
    stalled: number;
  } | null;
  faqAiReady?: boolean;
  faqFieldRules?: Record<
    'question' | 'answer',
    { required: boolean; maxLength: number }
  >;
  preferences?: typeof import('../lib/usePreferences').defaultPreferences;
  app: { name: string };
  auth: { user: AuthUser | null; canAccessAdmin: boolean };
  flash: { status: string | null };
  capabilities: Record<string, boolean>;
  i18n: {
    locale: string;
    direction: 'ltr' | 'rtl';
    strings: Record<string, string>;
    locales: { code: string; name: string }[];
  };
  links: Record<
    | 'login'
    | 'login.store'
    | 'register'
    | 'register.store'
    | 'logout'
    | 'password.request'
    | 'password.email'
    | 'password.update'
    | 'verification.send'
    | 'admin.dashboard'
    | 'access.pending'
    | 'admin.login'
    | 'account',
    string
  >;
  /** Which sign-in pages the request belongs to: staff (`/admin/...`) or customer. */
  authArea?: 'admin' | 'customer';
  /** Sign-in providers configured in Website → Social login (customer login and registration only). */
  socialProviders?: { provider: string; name: string; url: string }[];
  /** The store's name, logo and colors for customer-facing pages. */
  storeBrand?: {
    name: string;
    logo: string | null;
    colors: Record<
      | 'primary'
      | 'primary_hover'
      | 'on_primary'
      | 'text'
      | 'background'
      | 'sale',
      string
    >;
    /** The sign-in header's background and the text color that reads on it. */
    auth_header: { background: string; text: string };
  } | null;
};

export type NavigationItem = {
  id: string;
  label: string;
  href: string;
  active: boolean;
  description?: string;
  group?: {
    id: string;
    label: string;
    parent?: { id: string; label: string };
  };
};

export type Breadcrumb = { label: string; href: string | null };
