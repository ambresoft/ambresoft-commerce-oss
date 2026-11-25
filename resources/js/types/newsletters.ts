export type NewsletterType = 'newsletter' | 'template' | 'autoresponder';

export type DeliveryStats = {
  total: number;
  sent: number;
  pending: number;
  failed: number;
};

export type NewsletterRow = {
  id: string;
  version: number;
  name: string;
  subject: string;
  active: boolean;
  state: 'draft' | 'sending' | 'sent' | null;
  campaign: string | null;
  sent_at: string | null;
  updated_at: string | null;
  stats: DeliveryStats;
};

export type NewsletterText = {
  name: string;
  subject: string;
  random_subjects: string;
  body_html: string;
};

export type NewsletterEditor = {
  id: string;
  version: number;
  type: NewsletterType;
  state: 'draft' | 'sending' | 'sent' | null;
  active: boolean;
  campaign_id: string;
  translations: Record<string, NewsletterText>;
  sent_at: string | null;
  list_ids: string[];
  customers: { id: string; name: string; email: string }[];
  stats: DeliveryStats;
};

export type Choice = { id: string; name: string };

export type TemplateChoice = Choice & { bodies: Record<string, string> };

export type ListRow = {
  id: string;
  version: number;
  name: string;
  active: boolean;
  subscribers: number;
  confirmed: number;
  show_at_checkout: boolean;
  show_on_registration: boolean;
  updated_at: string | null;
};

export type ListEditor = {
  id: string;
  version: number;
  status: 'active' | 'disabled';
  from_name: string;
  from_email: string;
  reply_to: string;
  confirmation_id: string;
  show_at_checkout: boolean;
  show_on_registration: boolean;
  translations: Record<string, { name: string; description: string }>;
};

export type CampaignRow = {
  id: string;
  version: number;
  name: string;
  active: boolean;
  newsletters: number;
  sent: number;
  updated_at: string | null;
};

export type SubscriberRow = {
  id: string;
  version: number;
  email: string;
  status: 'active' | 'unsubscribed';
  source: 'admin' | 'import' | 'storefront';
  locale: string | null;
  lists: { id: string; name: string; confirmed: boolean }[];
  created_at: string | null;
};

export type SubscriberEditor = {
  id: string;
  version: number;
  email: string;
  status: 'active' | 'unsubscribed';
  locale: string;
  source: string;
  memberships: Record<string, boolean>;
  created_at: string | null;
};
