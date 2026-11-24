import type { AuditRow } from '../types/audit';
type Translate = (key: string) => string;
export const readable = (value: string) =>
  value.replaceAll('_', ' ').replaceAll('.', ' · ');
export const entityLabel = (value: string, t: Translate) =>
  t('audit.entities.' + value) || readable(value);
export const actionLabel = (
  log: Pick<AuditRow, 'action' | 'entity_type'>,
  t: Translate,
) => {
  const verb = log.action.split('.').slice(1).join('.');
  return (
    entityLabel(log.entity_type, t) +
    ' · ' +
    (t('audit.verbs.' + verb) || readable(verb))
  );
};
export const actorLabel = (log: AuditRow, t: Translate) =>
  log.actor_email ||
  (log.actor_id
    ? '#' + log.actor_id
    : t(
        ['system', 'background_job'].includes(log.source)
          ? 'audit.system'
          : 'audit.anonymous',
      ));
export const sourceLabel = (source: string, t: Translate) =>
  t('audit.' + (source === 'system' ? 'system_source' : source)) ||
  readable(source);
export const fieldLabel = (field: string, t: Translate) =>
  t('audit.fields.' + field) || readable(field);
export function valueLabel(value: unknown, t: Translate): string {
  if (value === null || value === undefined) return t('audit.unset');
  if (typeof value === 'boolean') return t(value ? 'audit.yes' : 'audit.no');
  return typeof value === 'object' ? JSON.stringify(value) : String(value);
}
