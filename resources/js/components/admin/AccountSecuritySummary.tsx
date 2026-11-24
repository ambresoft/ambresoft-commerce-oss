import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import Card from './AdminCard';
import { useTranslation } from '../../lib/useTranslation';
import type { SecuritySummary } from '../../types/accountSecurity';
import { useDateDisplay } from '../../lib/useDateDisplay';

export function LocalTime({ timezone }: { timezone: string | null }) {
  const t = useTranslation();
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 60000);
    return () => clearInterval(timer);
  }, []);
  if (!timezone) return <>{t('account_security.unknown')}</>;
  let formatted: string;
  try {
    formatted = new Intl.DateTimeFormat(undefined, {
      timeZone: timezone,
      hour: '2-digit',
      minute: '2-digit',
    }).format(now);
  } catch {
    formatted = t('account_security.unknown');
  }
  return (
    <time dateTime={now.toISOString()}>
      {formatted} · {timezone}
    </time>
  );
}
export default function AccountSecuritySummary({
  security,
  link = true,
}: {
  security: SecuritySummary;
  link?: boolean;
}) {
  const t = useTranslation();
  const { date, time } = useDateDisplay();
  const at = (value: string) =>
    date.format(new Date(value)) + ' ' + time.format(new Date(value));
  const latest = security.latest;
  return (
    <Card
      id="account-security-summary"
      icon="shield"
      title={t('account_security.title')}
      action={
        link && (
          <Link
            className="text-link text-sm"
            href={'/admin/account-security/' + security.identity_id}
          >
            {t('account_security.history')}
          </Link>
        )
      }
    >
      <dl className="grid gap-4 text-sm sm:grid-cols-2">
        <div>
          <dt className="text-xs text-slate-500">
            {t('account_security.local_time')}
          </dt>
          <dd className="mt-1 font-medium">
            <LocalTime timezone={latest?.timezone ?? null} />
          </dd>
        </div>
        <div>
          <dt className="text-xs text-slate-500">
            {t('account_security.last_login')}
          </dt>
          <dd className="mt-1">
            {latest ? at(latest.occurred_at) : t('account_security.no_history')}
          </dd>
        </div>
        <div>
          <dt className="text-xs text-slate-500">
            {t('account_security.location')}
          </dt>
          <dd className="mt-1">
            {[latest?.city, latest?.country].filter(Boolean).join(', ') ||
              t('account_security.unknown')}
          </dd>
        </div>
        <div>
          <dt className="text-xs text-slate-500">
            {t('account_security.failures')}
          </dt>
          <dd className="mt-1 font-medium">
            {security.failures} ·{' '}
            {t('account_security.recent_failures', {
              count: security.recent_failures,
            })}
          </dd>
        </div>
        <div>
          <dt className="text-xs text-slate-500">
            {t('account_security.alerts')}
          </dt>
          <dd className="mt-1 font-medium">{security.alerts}</dd>
        </div>
      </dl>
      {security.recent_failures >= security.failure_threshold && (
        <p
          role="status"
          className="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800"
        >
          {t('account_security.failure_alert')}
        </p>
      )}
      <p className="mt-4 text-xs leading-5 text-slate-500">
        {t('account_security.approximate')}
      </p>
      {!security.geoip_available && (
        <p className="mt-2 text-xs text-slate-500">
          {t('account_security.geoip_missing')}
        </p>
      )}
    </Card>
  );
}
