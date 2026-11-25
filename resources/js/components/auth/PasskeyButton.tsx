import { useState } from 'react';
import { useTranslation } from '../../lib/useTranslation';
import { performPasskey } from '../../lib/passkeys';

export default function PasskeyButton({
  registrationUrl,
  password,
}: {
  registrationUrl?: string;
  password?: string;
}) {
  const t = useTranslation();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const create = Boolean(registrationUrl);
  return (
    <div className="mt-5 space-y-2">
      <button
        type="button"
        className="button-secondary w-full"
        disabled={busy}
        onClick={async () => {
          setBusy(true);
          setError('');
          try {
            const result = await performPasskey(
              registrationUrl ?? '/admin/passkeys/login/options',
              registrationUrl === '/admin/passkeys/options'
                ? '/admin/passkeys'
                : (registrationUrl ?? '/admin/passkeys/login'),
              create,
              {
                operation: 'passkey_options',
                ...(password !== undefined ? { password } : {}),
              },
            );
            window.location.assign(result.redirect);
          } catch (failure) {
            setError(
              t(
                failure instanceof Error &&
                  failure.message === 'passkey_unavailable'
                  ? 'auth.passkey_unavailable'
                  : 'auth.passkey_failed',
              ),
            );
          } finally {
            setBusy(false);
          }
        }}
      >
        {t(
          busy
            ? 'common.wait'
            : create
              ? 'auth.passkey_register'
              : 'auth.passkey_login',
        )}
      </button>
      {error && (
        <p role="alert" className="text-sm text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}
