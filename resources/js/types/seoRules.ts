export type SeoRule = {
  id: string;
  source_path: string;
  destination: string;
  status_code: 301 | 302;
  enabled: boolean;
  external: boolean;
  version: number;
  updated_at: string | null;
  /** The enabled rule's destination when this rule's destination is itself redirected. */
  chain_to: string | null;
  /** Old addresses of other enabled rules that lead to this rule's old address. */
  incoming: string[];
};
