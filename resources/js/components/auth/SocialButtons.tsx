import { usePage } from '@inertiajs/react';
import { useTranslation } from '../../lib/useTranslation';
import type { SharedProps } from '../../types/page';

function GoogleMark() {
  return (
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
      <path
        fill="#4285F4"
        d="M22.5 12.2c0-.8-.1-1.5-.2-2.2H12v4.3h5.9a5 5 0 0 1-2.2 3.3v2.7h3.5c2.1-1.9 3.3-4.7 3.3-8.1Z"
      />
      <path
        fill="#34A853"
        d="M12 23c3 0 5.5-1 7.3-2.7l-3.5-2.7c-1 .7-2.2 1-3.8 1-2.9 0-5.4-2-6.3-4.6H2.1v2.8A11 11 0 0 0 12 23Z"
      />
      <path
        fill="#FBBC05"
        d="M5.7 14c-.2-.7-.4-1.3-.4-2s.1-1.4.4-2V7.2H2.1a11 11 0 0 0 0 9.6L5.7 14Z"
      />
      <path
        fill="#EA4335"
        d="M12 5.4c1.6 0 3.1.6 4.2 1.7l3.1-3.1A11 11 0 0 0 2.1 7.2L5.7 10C6.600 7.400 9.100 5.400 12 5.400Z"
      />
    </svg>
  );
}

/** Sign-in buttons for the providers configured in Website → Social login; nothing renders when none is active. */
export default function SocialButtons() {
  const t = useTranslation();
  const { socialProviders } = usePage<SharedProps>().props;
  if (!socialProviders || socialProviders.length === 0) return null;

  return (
    <div className="store-auth-social">
      {socialProviders.map((provider) => (
        // A full navigation: the provider flow leaves the app.
        <a
          key={provider.provider}
          className="store-social-button"
          href={provider.url}
        >
          {provider.provider === 'google' && <GoogleMark />}
          {t('auth.social_continue', { provider: provider.name })}
        </a>
      ))}
      <p className="store-auth-or">
        <span>{t('auth.social_or')}</span>
      </p>
    </div>
  );
}
