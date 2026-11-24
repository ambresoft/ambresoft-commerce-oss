export type ReviewStatus = 'pending' | 'approved' | 'rejected' | 'spam';
export type RatingSummary = {
  count: number;
  /** One decimal, rounded half up; null when there are no approved reviews. */
  average: string | null;
  distribution: Record<'1' | '2' | '3' | '4' | '5', number>;
};
export type ReviewRow = {
  id: string;
  version: number;
  rating: number;
  title: string | null;
  status: ReviewStatus;
  verified_purchase: boolean;
  product: {
    id: string;
    name: string;
    sku: string | null;
    status: string;
    trashed: boolean;
  };
  /** E-mail is only sent to staff who may view customers. */
  customer: { id: string; name: string; email: string | null };
  created_at: string;
  updated_at: string;
  moderated_at: string | null;
  edited_at: string | null;
  excerpt: string;
  truncated: boolean;
};
export type ReviewDetail = Omit<ReviewRow, 'excerpt' | 'truncated'> & {
  body: string;
  moderation_note: string;
  moderator_name: string | null;
  editor_name: string | null;
  transitions: ReviewStatus[];
  product_rating: RatingSummary;
};
export type ReviewBulkResult = { id: string; success: boolean; reason: string };
