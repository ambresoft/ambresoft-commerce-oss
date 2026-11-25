import type { Tone } from '../components/admin/list/StatusBadge';
import type { GiftCardStatus } from '../types/giftCards';

// Literal keys: translation keys are never built at runtime.
export const giftCardStatus: Record<GiftCardStatus, [string, Tone]> = {
  pending: ['giftcards.status_pending', 'warning'],
  active: ['giftcards.status_active', 'success'],
  cancelled: ['giftcards.status_cancelled', 'danger'],
  used: ['giftcards.status_used', 'neutral'],
};
