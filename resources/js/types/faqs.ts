export type FaqType = 'general' | 'category' | 'product';
export type FaqText = { question: string; answer: string };
export type FaqDraft = { translations: Record<string, FaqText> };
export type FaqOption = {
  id: string;
  question: string;
  type: FaqType;
  status: 'active' | 'disabled';
};
export type FaqRow = FaqOption & {
  position: number;
  version: number;
  links: number;
};
export type FaqEditor = FaqRow & FaqDraft;
export type FaqSource = {
  owner: FaqType | 'page';
  object_id: string | null;
  source_name: string;
  source_text: string;
};
