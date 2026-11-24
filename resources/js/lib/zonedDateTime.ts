/** UTC instant to a datetime-local value in a named store timezone. */
export function toLocalInput(
  value: string | null | undefined,
  timezone: string,
): string {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(date);
  const get = (type: string) =>
    parts.find((part) => part.type === type)?.value ?? '';
  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

/** Convert wall time to an instant. Reject nonexistent DST times instead of silently moving a schedule. */
export function toInstant(value: string, timezone: string): string {
  if (!value) return '';
  const local = new Date(value + 'Z');
  if (Number.isNaN(local.getTime())) return 'invalid';
  let instant = local.getTime();
  for (let attempt = 0; attempt < 4; attempt++) {
    const shown = new Date(
      toLocalInput(new Date(instant).toISOString(), timezone) + 'Z',
    ).getTime();
    instant += local.getTime() - shown;
  }
  const result = new Date(instant).toISOString();
  return toLocalInput(result, timezone) === value ? result : 'invalid';
}
