export type PageStatus = 'active' | 'hidden' | 'disabled';
export type PageState =
  'live' | 'hidden' | 'disabled' | 'scheduled' | 'expired';

export type PageNode = {
  id: string;
  parent_id: string | null;
  name: string;
  slug: string;
  status: PageStatus;
  position: number;
  depth: number;
  state: PageState;
  version: number;
  children: number;
};

export type PageText = {
  name: string;
  content: string;
  slug: string;
  seo_title: string;
  meta_description: string;
  meta_keywords: string;
};

export type PageEditor = {
  /** The storefront address with preview on, once a layout is linked to the page. */
  preview_url?: string | null;
  faq_ids?: string[];
  id: string;
  version: number;
  parent_id: string | null;
  position: number;
  status: PageStatus;
  created_on: string;
  facebook_type: string;
  available_from: string | null;
  available_until: string | null;
  state: PageState;
  translations: Record<string, PageText>;
  customer_groups: string[];
  form: PageFormDefinition | null;
};

export type FieldType =
  | 'input'
  | 'textarea'
  | 'select'
  | 'radio'
  | 'checkboxes'
  | 'checkbox'
  | 'date'
  | 'email'
  | 'number'
  | 'phone'
  | 'country'
  | 'region'
  | 'file'
  | 'header'
  | 'separator';

export type FormVariant = {
  key?: string;
  position: number;
  labels: Record<string, string>;
};
export type FormField = {
  key: string;
  id: string | null;
  position: number;
  type: FieldType;
  required: boolean;
  status: 'active' | 'disabled';
  names: Record<string, string>;
  variants: FormVariant[];
};
export type PageFormDefinition = {
  enabled: boolean;
  placement: 'before' | 'after' | 'marker';
  subject_mode: 'form_name' | 'field' | 'custom';
  subject_field: string;
  recipients: string;
  translations: Record<string, { submit_text: string; custom_subject: string }>;
  fields: FormField[];
};
