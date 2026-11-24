import type { OrderStatusOption } from './orders';
export type CallStatus = 'new' | 'in_progress' | 'completed' | 'no_answer';
export type CallAssignee = { id: string; name: string; eligible: boolean };
export type CallProduct = { id: string; name: string; sku: string | null };
export type CallRequestRow = {
  id: string;
  version: number;
  name: string;
  phone: string;
  convenient_time: string;
  status: CallStatus;
  responsible_id: string;
  responsible_name: string | null;
  responsible_active: boolean;
  order_id: string | null;
  order_number: string;
  order_status: OrderStatusOption | null;
  created_at: string;
  updated_at: string;
  notes_preview: string;
  notes_truncated: boolean;
  product_count: number;
};
export type CallRequestDetail = Omit<
  CallRequestRow,
  'notes_preview' | 'notes_truncated' | 'product_count'
> & {
  notes: string;
  products: {
    id: string | null;
    name: string;
    sku: string | null;
    quantity: number;
    available: boolean;
  }[];
};
