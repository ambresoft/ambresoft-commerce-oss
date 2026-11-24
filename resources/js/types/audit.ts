export type AuditRow = {
  id: string;
  occurred_at: string;
  actor_id: string | null;
  actor_email: string | null;
  action: string;
  operation?: string | null;
  entity_type: string;
  entity_id: string | null;
  entity_label: string | null;
  description: string;
  status: 'success' | 'failure' | 'denied';
  request_id: string | null;
  source: string;
  ip_address: string | null;
};
export type AuditDetails = AuditRow & {
  actor_roles: string[];
  user_agent: string | null;
  changes: Record<string, { before: unknown; after: unknown }>;
  metadata: Record<string, unknown>;
};
