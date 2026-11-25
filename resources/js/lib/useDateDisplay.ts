import { usePreferences } from './usePreferences';

/** Format instants in the store's IANA timezone, including daylight-saving transitions. */
export function formatDate(
  value: Date,
  format: string,
  timezone: string,
): string {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: timezone,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).formatToParts(value);
  const get = (type: string) =>
    parts.find((part) => part.type === type)?.value ?? '';
  return format.replace(/[dmY]/g, (token) =>
    get({ d: 'day', m: 'month', Y: 'year' }[token]!),
  );
}

export function useDateDisplay() {
  const preferences = usePreferences();
  return {
    date: {
      format: (value: Date) =>
        formatDate(value, preferences.date_format, preferences.timezone),
    },
    time: {
      format: (value: Date) =>
        new Intl.DateTimeFormat('en-US', {
          timeZone: preferences.timezone,
          hour: '2-digit',
          minute: '2-digit',
          hourCycle: preferences.time_format === 'H:i' ? 'h23' : 'h12',
        }).format(value),
    },
  };
}
