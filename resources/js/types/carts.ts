export type CartRow = {
  id: string;
  uuid: string;
  /** Null for a guest's cart. The e-mail is shown only to staff who may view customers. */
  customer: { id: string; name: string; email: string | null } | null;
  /** Number of products (lines) and of units. */
  lines: number;
  units: number;
  created_at: string;
  updated_at: string;
};

export type CartDetail = CartRow & {
  /** The lines its owner sees, priced for them now (formatted amounts; total null when the line cannot be bought). */
  items: {
    id: string;
    name: string;
    sku: string | null;
    url: string | null;
    image: { url: string } | null;
    quantity: number;
    price: string | null;
    total: string | null;
    problem: string | null;
  }[];
  /** Lines of products the owner cannot see now. */
  hidden: { id: string; name: string; sku: string | null; quantity: number }[];
  subtotal: string;
};
