import NavigationIcon from './NavigationIcon';

const icons: Record<string, string[]> = {
  external: ['M14 3h7v7M21 3 10 14M10 4H4v16h16v-6'],
  clock: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z', 'M12 7v5l3 2'],
  phone: [
    'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.4 1.8.6 2.8.7a2 2 0 0 1 1.8 2.1Z',
  ],
  mail: ['M3 5h18v14H3Z', 'm3 6 9 7 9-7'],
  currency: [
    'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z',
    'M15 7h-4a2.5 2.5 0 0 0 0 5h2a2.5 2.5 0 0 1 0 5H9M12 5v14',
  ],
  building: ['M4 21V3h12v18M16 9h4v12M2 21h20M8 7h4M8 11h4M8 15h4M9 21v-3h2v3'],
  cookie: [
    'M21 12a9 9 0 1 1-9-9 5 5 0 0 0 9 9Z',
    'M7 9h.01M8 15h.01M13 17h.01M12 11h.01',
  ],
  video: ['M3 5h13v14H3Z', 'm16 10 5-3v10l-5-3Z'],
  star: [
    'm12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9Z',
  ],
  pin: [
    'M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 0 1 14 0Z',
    'M12 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
  ],
  preview: [
    'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z',
    'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
  ],
  send: ['m3 3 18 9-18 9 4-9-4-9ZM7 12h14'],
  code: ['m8 6-6 6 6 6m8-12 6 6-6 6m-3-15-2 18'],
  download: ['M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4'],
  trash: ['M4 7h16M9 7V3h6v4M6 7l1 14h10l1-14M10 11v6m4-6v6'],
  check: ['M4 12a8 8 0 1 0 16 0 8 8 0 0 0-16 0Z', 'm8 12 3 3 5-6'],
  sparkles: ['m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5Z'],

  general: [
    'M20 13 13 20a2 2 0 0 1-3 0L3 13V3h10l7 7a2 2 0 0 1 0 3Z',
    'M7 7h.01',
  ],
  pricing: [
    'M12 8c5 0 8-1 8-3s-3-3-8-3-8 1-8 3 3 3 8 3Z',
    'M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0',
  ],
  sales: [
    'm5 19 14-14',
    'M7 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
  ],
  price_history: ['m3 17 6-6 4 3 8-10', 'M15 4h6v6'],
  media: ['M4 3h16v18H4z', 'm4 16 5-5 4 4 3-3 4 4M8 7h.01'],
  seo: ['M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Z', 'm16 16 5 5'],
  features: ['M9 5h12M9 12h12M9 19h12M3 5h.01M3 12h.01M3 19h.01'],
  variations: [
    'M6 6h12M6 6l12 12M6 18 18 6',
    'M6 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM18 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM6 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM18 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
  ],
  similar_products: [
    'M10 13a5 5 0 0 0 7 0l4-4a5 5 0 0 0-7-7l-2 2',
    'M14 11a5 5 0 0 0-7 0l-4 4a5 5 0 0 0 7 7l2-2',
  ],
  bought_together: ['M3 3h2l3 13h11l3-9H6M9 21h.01M18 21h.01'],
  attachments: [
    'm8 12 7-7a4 4 0 0 1 6 6L10 22a6 6 0 0 1-8-8L13 3',
    'm6 16 9-9',
  ],
  faqs: [
    'M21 11a9 9 0 0 1-9 9H3l2-5a9 9 0 1 1 16-4Z',
    'M9 8a3 3 0 0 1 6 0c0 2-3 2-3 4M12 16h.01',
  ],
  content: ['M5 3h14v18H5z', 'M8 7h8M8 11h8M8 15h5'],
  form: ['M4 4h16v16H4z', 'M8 8h8M8 12h8M8 16h4'],
  history: ['M3 11a9 9 0 1 1 2 7M3 4v7h7', 'M12 7v5l3 2'],
  security: ['m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z', 'm8 12 3 3 5-6'],
  pc: ['M2 3h20v14H2zM8 21h8M12 17v4'],
  mobile: ['M7 2h10v20H7zM11 18h2'],
  new: ['M12 4v16M4 12h16'],
  appearance: ['m4 17 12-12 3 3L7 20H4v-3Z', 'm14 7 3 3'],
};
const aliases: Record<string, string> = {
  package: 'core.catalog-products',
  reviews: 'star',
  email: 'mail',
  emails: 'mail',
  company: 'building',
  ai: 'sparkles',
  cookies: 'cookie',
  details: 'content',
  variables: 'code',
  live_preview: 'preview',
  test_send: 'send',
  test_title: 'send',
  send_to: 'customers',
  send_title: 'send',
  rates_title: 'currency',
  carrier_rates_title: 'shipping',
  restrictions_title: 'security',
  group_delete: 'trash',
  delete: 'trash',
  status_emails: 'mail',
  changes: 'history',
  rates: 'currency',
  notes: 'content',
  items: 'products',
  customer: 'customers',
  billing_address: 'pin',
  manifest_lists: 'features',
  manifest_contents: 'products',
  image_title: 'media',
  xml_sitemap: 'tree',
  age_restrictions: 'security',
  by_payment: 'payments',
  sales_detail: 'reports',
  by_coupon: 'sales',
  context_title: 'reports',
  by_shipping: 'shipping',
  by_status: 'check',
  orders_list: 'orders',
  moderation: 'check',
  area_countries: 'core.website',
  settings_title: 'settings',

  redis: 'core.cache',
  brand: 'general',
  gift: 'core.payment-methods',

  layouts: 'core.categories',
  access: 'core.access',
  orders: 'core.orders',
  marketing: 'core.marketing',
  website: 'core.website',
  shipping_taxes: 'core.shipping-taxes',
  addons: 'core.addons',
  reports: 'core.reports',
  logs: 'core.logs',
  basic: 'general',

  address_tab: 'core.website',
  legal: 'security',
  contacts: 'core.customers',
  social: 'similar_products',
  logo: 'media',
  payments: 'core.payment-methods',
  catalog: 'core.catalog',
  inventory: 'core.catalog-products',
  promotions: 'sales',
  taxes: 'pricing',
  storefront: 'core.website',
  accounts: 'core.customers',
  configuration: 'core.settings',
  connection: 'similar_products',
  webhooks: 'similar_products',
  scripts: 'content',
  product_options: 'features',
  identity_options: 'appearance',
  mobile_links: 'mobile',

  ranking: 'core.reports',
  declining: 'price_history',
  no_sales: 'history',
  never_sold: 'core.catalog-products',
  no_orders: 'core.orders',
  direct: 'core.categories',
  tree: 'core.catalog',
  out_of_stock: 'core.catalog-products',
  low: 'price_history',
  lowest: 'price_history',
  highest: 'core.reports',
  above: 'core.reports',
  new_customer: 'new',
  existing_customer: 'core.customers',
  home: 'core.dashboard',
  product: 'core.catalog-products',
  category: 'core.catalog',
  search: 'seo',
  page: 'content',
  cart: 'bought_together',
  checkout: 'core.payment-methods',
  image: 'media',
  images: 'media',
  banners: 'media',

  discounts: 'sales',
  benefits: 'sales',
  conditions: 'form',
  shipping: 'core.shipping-taxes',
  categories: 'core.catalog',
  additional: 'core.addons',
  extras: 'core.addons',
  settings: 'core.settings',
  options: 'features',
  products: 'core.catalog-products',
  members: 'core.catalog-products',
  contents: 'core.catalog-products',
  add: 'new',
  existing: 'core.catalog',
  listing: 'core.categories',
  display: 'pc',
  stickers: 'general',
  permissions: 'security',
  roles: 'core.access',
  contact: 'core.customers',
  customers: 'core.customers',

  consents: 'security',

  assignment: 'similar_products',
  assignments: 'similar_products',
  blog: 'content',
  metadata: 'seo',
  design: 'appearance',
  tablet: 'mobile',
  translations: 'core.website',
  language: 'core.website',
  all: 'core.categories',
  active: 'security',
  archived: 'history',
};

/** Icon is optional at call sites; semantic tab keys supply the standard icon. */
export default function TabIcon({ name }: { name: string }) {
  // Aliases may point at other aliases (orders_list → orders → core.orders); follow them, bounded.
  let key = aliases[name] ?? name;
  for (let hop = 0; hop < 3 && !icons[key] && aliases[key]; hop++)
    key = aliases[key]!;
  const paths = icons[key];
  if (!paths)
    return (
      <NavigationIcon id={key.startsWith('core.') ? key : 'core.settings'} />
    );
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      className="size-5 shrink-0"
    >
      {paths.map((d) => (
        <path d={d} key={d} />
      ))}
    </svg>
  );
}
