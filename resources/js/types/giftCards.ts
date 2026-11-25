export type GiftCardStatus = 'pending' | 'active' | 'cancelled' | 'used';

export type GiftCardRow = {
  id: string;
  version: number;
  code: string;
  sender: string;
  recipient: string;
  email: string;
  created_at: string;
  status: GiftCardStatus;
  balance: string;
  amount: string;
  partly_used: boolean;
  notify: boolean;
};

export type GiftCardData = {
  version: number;
  recipient_name: string;
  sender_name: string;
  recipient_email: string;
  message: string;
  amount: string;
  locale: string;
  status: GiftCardStatus;
  notify: boolean;
};

export type GiftCardDetail = {
  id: string;
  version: number;
  code: string;
  recipient_name: string;
  sender_name: string;
  recipient_email: string;
  message: string;
  locale: string;
  status: GiftCardStatus;
  notify: boolean;
  amount: string;
  currency_code: string;
  balance: string;
  amount_locked: boolean;
  deleted_at: string | null;
  created_at: string;
};

export type GiftCardUse = {
  id: string;
  type: 'issued' | 'redeemed';
  order_id: string | null;
  order_number: string | null;
  actor: string | null;
  amount: string;
  balance_after: string;
  created_at: string;
};

export type GiftCardEmail = {
  id: string;
  kind: 'delivery' | 'cancelled';
  recipient: string;
  state: 'pending' | 'sending' | 'sent' | 'failed';
  attempts: number;
  error: string | null;
  actor: string | null;
  at: string;
};
