export type SecurityEvent = {
  id: string;
  user_id: string;
  kind: 'registration' | 'login' | 'password_failed';
  area: 'admin' | 'customer';
  ip: string | null;
  browser: string;
  platform: string;
  country: string | null;
  city: string | null;
  timezone: string | null;
  new_device: boolean;
  new_ip: boolean;
  occurred_at: string;
};
export type SecuritySummary = {
  identity_id: string;
  registration: SecurityEvent | null;
  latest: SecurityEvent | null;
  failures: number;
  recent_failures: number;
  failure_threshold: number;
  geoip_available: boolean;
  alerts: number;
};
export const securityKindLabels = {
  registration: 'account_security.registration',
  login: 'account_security.login',
  password_failed: 'account_security.password_failed',
} as const;
