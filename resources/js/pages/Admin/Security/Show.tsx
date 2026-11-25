import AdminLayout from '../../../layouts/admin/AdminLayout';
import EditorHeader from '../../../components/admin/EditorHeader';
import Card from '../../../components/admin/AdminCard';
import Pagination from '../../../components/admin/Pagination';
import AccountSecuritySummary from '../../../components/admin/AccountSecuritySummary';
import { useTranslation } from '../../../lib/useTranslation';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import {
  securityKindLabels,
  type SecurityEvent,
  type SecuritySummary,
} from '../../../types/accountSecurity';

type Seen = { first_seen: string; last_seen: string; count: number };
export default function SecurityShow({
  identity,
  security,
  events,
  ips,
  devices,
  ...shell
}: AdminShell & {
  identity: { id: string; name: string; email: string };
  security: SecuritySummary;
  events: Page<SecurityEvent>;
  ips: Page<Seen & { ip: string }>;
  devices: Page<
    Seen & { device_key: string; browser: string; platform: string }
  >;
}) {
  const t = useTranslation();
  const { date, time } = useDateDisplay();
  const at = (value: string) =>
    date.format(new Date(value)) + ' ' + time.format(new Date(value));
  const registration = security.registration;
  return (
    <AdminLayout title={t('account_security.title')} {...shell}>
      <EditorHeader
        heading={identity.name}
        iconKey="security"
        metadata={identity.email}
        description={t('account_security.title')}
        backHref="/admin"
      />
      <div className="space-y-6">
        <AccountSecuritySummary security={security} link={false} />
        <Card
          id="registration-security"
          icon="user"
          title={t('account_security.registration')}
        >
          {registration ? (
            <dl className="grid gap-4 text-sm sm:grid-cols-4">
              <div>
                <dt className="text-slate-500">{t('account_security.when')}</dt>
                <dd>{at(registration.occurred_at)}</dd>
              </div>
              <div>
                <dt className="text-slate-500">{t('account_security.ip')}</dt>
                <dd>{registration.ip ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-slate-500">
                  {t('account_security.device')}
                </dt>
                <dd>
                  {registration.browser} · {registration.platform}
                </dd>
              </div>
              <div>
                <dt className="text-slate-500">
                  {t('account_security.location')}
                </dt>
                <dd>
                  {[registration.city, registration.country]
                    .filter(Boolean)
                    .join(', ') || t('account_security.unknown')}
                </dd>
              </div>
            </dl>
          ) : (
            <p className="text-sm text-slate-500">
              {t('account_security.no_registration')}
            </p>
          )}
        </Card>
        <Card
          id="security-devices"
          icon="shield"
          title={t('account_security.devices')}
        >
          <div className="overflow-x-auto">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('account_security.device')}</th>
                  <th>{t('account_security.first_seen')}</th>
                  <th>{t('account_security.last_seen')}</th>
                  <th>{t('account_security.count')}</th>
                </tr>
              </thead>
              <tbody>
                {devices.data.map((device) => (
                  <tr key={device.device_key}>
                    <td>
                      {device.browser} · {device.platform}
                    </td>
                    <td>{at(device.first_seen)}</td>
                    <td>{at(device.last_seen)}</td>
                    <td>{device.count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination page={devices} />
        </Card>
        <Card id="security-ips" icon="pin" title={t('account_security.ips')}>
          <div className="overflow-x-auto">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('account_security.ip')}</th>
                  <th>{t('account_security.first_seen')}</th>
                  <th>{t('account_security.last_seen')}</th>
                  <th>{t('account_security.count')}</th>
                </tr>
              </thead>
              <tbody>
                {ips.data.map((ip) => (
                  <tr key={ip.ip}>
                    <td className="font-mono">{ip.ip}</td>
                    <td>{at(ip.first_seen)}</td>
                    <td>{at(ip.last_seen)}</td>
                    <td>{ip.count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination page={ips} />
        </Card>
        <Card
          id="security-events"
          icon="clock"
          title={t('account_security.history')}
        >
          <div className="overflow-x-auto">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('account_security.when')}</th>
                  <th>{t('account_security.event')}</th>
                  <th>{t('account_security.ip')}</th>
                  <th>{t('account_security.device')}</th>
                  <th>{t('account_security.location')}</th>
                  <th>{t('account_security.alerts')}</th>
                </tr>
              </thead>
              <tbody>
                {events.data.map((event) => (
                  <tr key={event.id}>
                    <td className="whitespace-nowrap">
                      {at(event.occurred_at)}
                    </td>
                    <td>
                      {t(securityKindLabels[event.kind])}
                      <span className="block text-xs text-slate-500">
                        {event.area === 'admin'
                          ? t('account_security.admin')
                          : t('account_security.customer')}
                      </span>
                    </td>
                    <td className="font-mono">{event.ip ?? '—'}</td>
                    <td>
                      {event.browser} · {event.platform}
                    </td>
                    <td>
                      {[event.city, event.country].filter(Boolean).join(', ') ||
                        '—'}
                    </td>
                    <td className="text-amber-700">
                      {event.new_device && (
                        <span className="block">
                          {t('account_security.new_device')}
                        </span>
                      )}
                      {event.new_ip && (
                        <span className="block">
                          {t('account_security.new_ip')}
                        </span>
                      )}
                      {event.kind === 'password_failed' &&
                        t('account_security.password_failed')}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination page={events} />
        </Card>
      </div>
    </AdminLayout>
  );
}
