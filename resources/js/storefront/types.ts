import type { MobileMenuLinks } from '../types/layouts';
import type { PreviewContent } from '../components/banners/BannerCanvas';
import type { Device, Layout, ProductOption, Settings } from '../types/banners';

export type PublicImage = { url: string; width: number; height: number };
export type PublicBanner = {
  settings: Settings;
  devices: Record<Device, { layout: Layout; content: PreviewContent }>;
  products: ProductOption[];
};
export type PublicSticker = {
  id: string;
  style: 'text' | 'image';
  text: string;
  alt: string;
  tooltip: string;
  placement: 'image_top' | 'image_bottom' | 'above_price' | 'below_price';
  visibility: { mobile: boolean; tablet: boolean; desktop: boolean };
  image: PublicImage | null;
  background: string;
  color: string;
  border_color: string;
  border_width: number;
  radius: number;
  padding: number;
  font_size: number;
  alignment: 'left' | 'center' | 'right';
  css_class: string;
};
export type PublicDeal = {
  kind: 'coupon' | 'offer';
  benefit: string;
  code: string | null;
  description: string;
  expires_on: string | null;
  terms: string[];
};
export type PublicItem = {
  price_from?: boolean;
  view_product?: boolean;
  variation_features?: {
    name: string;
    values: string[];
    swatches?: {
      label: string;
      color: string | null;
      selected: boolean;
      url?: string;
    }[];
  }[];
  views?: number;
  deal?: PublicDeal;
  id: string;
  name?: string;
  /** Blog post cards. */
  excerpt?: string;
  published_at?: string | null;
  image_alt?: string;
  /** Blog post cards: the optional phone cover. */
  image_mobile?: PublicImage | null;
  sku?: string | null;
  url?: string | null;
  new_window?: boolean;
  stickers?: PublicSticker[];
  banner?: PublicBanner;
  image?: PublicImage | null;
  price?: string | null;
  base_price?: string | null;
  /** The price without tax, when the store shows it beneath the price. */
  price_without_tax?: string | null;
  /** Whole-percent reduction from the reference price. */
  discount_percent?: number | null;
  /** In the signed-in customer's general wishlist. */
  favorite?: boolean;
  /** Approved reviews (average out of 5 and count); null without any. */
  rating?: { average: string | null; count: number } | null;
  currency?: string;
  available?: boolean;
  /** The product's brand and its brand page, if any (shown above the name where the block asks for it). */
  brand_link?: { name: string; url: string | null } | null;
  /** The product's category (shown above the name where the block asks for it). */
  category?: { name: string; url: string | null } | null;
  /** The visible members of the product's variation group, this product included (where the block asks for them). */
  variants?: {
    id: string;
    name: string;
    url: string | null;
    image: PublicImage | null;
  }[];
};
export type CurrencyFormat = {
  code: string;
  symbol: string;
  position: 'before' | 'after';
  thousands: string;
  decimal: string;
  decimals: number;
};
export type PublicMenuNode = {
  id: string;
  name: string;
  url: string | null;
  new_window: boolean;
  image: PublicImage | null;
  children: PublicMenuNode[];
};
export type PublicLanguage = {
  code: string;
  name: string;
  url: string;
  active: boolean;
};
export type PublicSocialLink = {
  network: string;
  name: string;
  url: string;
  image?: PublicImage | null;
};
export type PublicPaymentIcon = { name: string; image: PublicImage };
export type PublicDisplay = {
  title_icon?: 'none' | 'ticket' | 'gift' | 'trophy' | 'percent';
  show_language_switcher?: boolean;
  show_mobile_account_links?: boolean;
  /** Header block: the original header or the one-line compact one. */
  header_style?: 'classic' | 'compact' | 'fashion';
  /** Compact header: the order of the icons on the right. */
  icon_order?: string[];
  /** Blog post cards. */
  show_date?: boolean;
  show_excerpt?: boolean;
  padding_top?: number;
  padding_bottom?: number;
  heading_size?: number;
  text_size?: number;
  columns?: number;
  show_top_border?: boolean;
  main_desktop?: boolean;
  main_tablet?: boolean;
  main_mobile?: boolean;
  secondary_desktop?: boolean;
  secondary_tablet?: boolean;
  secondary_mobile?: boolean;
  /** Design of the opened panel: columns that cascade level by level, or a directory (top categories beside a grid of their subcategories). */
  dropdown_style?: 'cascade' | 'directory';
  /** Desktop and tablet: the opened menu panel spans the whole screen width. */
  full_width_dropdown?: boolean;
  /** Desktop and tablet: the page behind an opened menu panel is dimmed. */
  dim_page_on_open?: boolean;
  /** Desktop and tablet: the panel opens on click instead of hover. */
  open_on_click?: boolean;
  mobile_style?: 'burger' | 'inline' | 'stacked' | 'accordion';
  /** The block's color band can extend past its box (menus open dropdowns below it). */
  allow_overflow?: boolean;
  show_logo?: boolean;
  show_search?: boolean;
  /** Product suggestions under the header's search field (0 = none). */
  search_suggestions?: number;
  /** Characters a search needs (the search add-on's setting; 0 for any). */
  search_min_length?: number;
  show_account?: boolean;
  show_call_request?: boolean;
  show_favorites?: boolean;
  show_cart?: boolean;
  /** Desktop and tablet: the cart icon opens a mini cart instead of the cart page. */
  show_mini_cart?: boolean;
  layout?: 'split' | 'left' | 'right' | 'center';
  font_size_desktop?: number;
  font_size_tablet?: number;
  font_size_mobile?: number;
  text_color?: string;
  /** Header and top bar: the color runs edge to edge. */
  full_bleed?: boolean;
  show_price?: boolean;
  show_add_to_cart?: boolean;
  /** Header icons color (empty follows the header text color). */
  icon_color?: string;
  /** Stars and review count under the product name. */
  show_rating?: boolean;
  /** The product's brand, linked to its brand page, above the name. */
  show_brand?: boolean;
  /** The product's category, underlined, above the name. */
  show_category?: boolean;
  /** The variation group's pictures under the product picture. */
  show_variations?: boolean;
  /** Shadow around product cards. */
  card_shadow?: boolean;
  show_sku?: boolean;
  show_availability?: boolean;
  autoscroll?: boolean;
  drag_scroll?: boolean;
  infinite_scroll?: boolean;
  autoplay_seconds?: number;
  /** Banners visible at once in a carousel on desktop and tablet (phones show one). */
  visible_desktop?: number;
  visible_tablet?: number;
  /** Banner height as a percentage of the design's own height (100: as designed), on desktop and tablet. */
  height_desktop?: number;
  height_tablet?: number;
  hide_arrows_mobile?: boolean;
  navigation?: 'outside' | 'inside' | 'none';
  navigation_style?: 'arrows' | 'dots';
  full_width?: boolean;
  /** Which banners load at once. */
  preload?: 'none' | 'first' | 'all';
  remove_border_radius?: boolean;
  background_color?: string;
  title_size_desktop?: number;
  title_size_tablet?: number;
  title_size_mobile?: number;
  title_color?: string;
  full_width_background?: boolean;
  per_row?: number;
  rows?: string | number;
  image_width?: number;
  image_height?: number;
  image_radius?: number;
};
export type PublicBlock = {
  id: string;
  template: string;
  title: string;
  css_class: string;
  mobile: boolean;
  tablet: boolean;
  desktop: boolean;
  content: {
    paid_ad?: boolean;
    is_bestsellers?: boolean;
    /** Additional validated public payload fields supplied by registered block types. */
    [key: string]: unknown;
    items?: PublicItem[];
    brand?: {
      url?: string | null;
      id: string;
      name: string;
      image: PublicImage | null;
      image_alt: string;
    };
    /** Validated presentation settings of the block. */
    display?: PublicDisplay;
    /** Currency settings used to format product prices. */
    currency_format?: CurrencyFormat;
    html?: string;
    html_right?: string;
    form?: string;
    call_request_form?: string | null;
    account_signed_in?: boolean;
    mobile_links?: MobileMenuLinks;
    confirm?: boolean;
    terms_url?: string | null;
    groups?: PublicMenuNode[];
    social?: PublicSocialLink[];
    apps?: PublicSocialLink[];
    payments?: PublicPaymentIcon[];
    main?: PublicMenuNode[];
    secondary?: PublicMenuNode[];
    languages?: PublicLanguage[];
    /** Compact header: the menu its drawer shows. */
    drawer?: PublicMenuNode[];
    /** Optional "see all" link beside the block title. */
    link?: { text: string; url: string };
    name?: string;
    logo?: PublicImage | null;
    mobile_logo?: PublicImage | null;
    /** The product on product pages (main content block). */
    product?: ProductPageData;
    /** A product in an age-restricted category before the visitor confirms the age: only this is sent. */
    age_gate?: { age: number; message: string };
    /** The customer's lists on the wishlist page (main content block). */
    wishlist?: WishlistPageData;
    /** The signed-in customer's account section (main content block of the Account layout). */
    account?: AccountPageData;
    /** The visitor's cart (main content block of the Cart layout). */
    cart?: CartPageData;
    /** The checkout form, or the placed order's confirmation (main content block of the Checkout layout). */
    checkout?: CheckoutPageData;
    /** The checkout's checkboxes above the order button (main content block settings). */
    checkout_options?: CheckoutOptions;
    /** A product carousel's picture beside the products (left or right; above them on phones). Not a link. */
    side_image?: PublicImage & {
      side: 'left' | 'right';
      /** The phone version; null uses the desktop picture. */
      mobile: PublicImage | null;
      /** CSS object-fit. */
      fit: 'contain' | 'cover' | 'fill' | 'scale-down';
      /** CSS object-fit on phones. */
      fit_mobile: 'contain' | 'cover' | 'fill' | 'scale-down';
      /** Optional link of the picture (desktop and phone). */
      link: string | null;
    };
    /** Search terms linking to their results (popular searches block). */
    popular_searches?: { term: string; url: string }[];
    /** The checkout's own header: the store's logo or name and its background (checkout header block). */
    checkout_header?: {
      name: string;
      logo: PublicImage | null;
      background_color: string;
      /** One colour for every text and icon; null keeps the theme's. */
      text_color: string | null;
    };
    /** A landing page category and its subcategory cards (main content block of the Category landing layout). */
    category?: CategoryLandingData;
    /** A product listing: a category's or a brand's products (main content block of the Category or Catalog landing layout). */
    listing?: ListingData;
    /** The product listing's filters (product filters block). */
    filters?: ListingFilters;
    /** A brand page's heading (page heading block of the Catalog landing layout). */
    brand_page?: BrandPageData;
    /** A static page, shown by its layout's main content block. */
    page?: StaticPageData;
    /** A blog's post list (Blog layout) or one post (Blog post layout). */
    blog?: BlogData;
    post?: BlogPostData;
    /** Visitor-facing sitemap and alphabetical brand directory. */
    directory?: DirectoryData;
    /** Order tracking: the number asked about and the answer. */
    tracking?: {
      number: string;
      state: 'found' | 'not_found' | 'login' | null;
      order: {
        number: string;
        created_at: string;
        status: { name: string; color: string } | null;
        timeline: {
          status: { name: string; color: string } | null;
          at: string;
        }[];
        account_url: string | null;
      } | null;
    };
    /** Category landing pages: card shadow and the mobile presentation of the cards. */
    card_shadow?: boolean;
    mobile_display?: 'grid' | 'carousel';
    mobile_per_row?: number;
    /** The visitor's compared products on the comparison page (main content block of the Comparison layout). */
    comparison?: ComparisonPageData;
    /** Cards per row of the wishlist grid (main content block setting). */
    per_row?: number;
    section_order?: string[];
    /** Items per row of each product page carousel (main content block setting). */
    carousel_per_row?: Partial<
      Record<
        'similar' | 'newest' | 'recently_viewed' | 'variation_alternatives',
        number
      >
    >;
    /** Which product page sections the main content block shows. */
    sections?: Partial<
      Record<
        | 'show_bought_together'
        | 'show_similar'
        | 'show_newest'
        | 'newest_same_category'
        | 'show_recently_viewed'
        | 'show_description'
        | 'show_features'
        | 'show_reviews'
        | 'show_faqs'
        | 'show_documents'
        | 'show_price_history'
        | 'show_product_bar'
        | 'show_variation_alternatives',
        boolean
      >
    >;
  };
};
export type PublicGrid = {
  id: string;
  width: number;
  row: number;
  blocks: PublicBlock[];
  children: PublicGrid[];
};
export type StorefrontColors = {
  primary: string;
  primary_hover: string;
  on_primary: string;
  text: string;
  background: string;
  sale: string;
};
export type StorefrontPage = {
  theme: string;
  colors?: StorefrontColors;
  /** General settings: white product picture backgrounds take the grey behind them. */
  frame?: { addon: string; src: string; title: string };
  blend_images?: boolean;
  /** General settings: how product cards look. */
  card_style?: 'classic' | 'showcase' | 'signature';
  /** Units in the visitor's cart (the header badge). */
  cart_count?: number;
  /** The store's cart; off removes every add-to-cart button and the cart icon. */
  cart_enabled?: boolean;
  /** A customer is signed in (hearts open the list window instead of the sign-in page). */
  signed_in?: boolean;
  /** General settings: wishlists are on (hearts, the list window, the header link). */
  wishlist_enabled?: boolean;
  /** General settings: product reviews and ratings are on. */
  reviews_enabled?: boolean;
  max_width?: number;
  show_back_to_top?: boolean;
  regions: Record<string, PublicGrid[]>;
};
export type ProductFeature = { id: string; name: string; value: string };
export type ProductVariationOption = {
  compatible?: boolean;
  id: string;
  label: string;
  selected: boolean;
  url: string | null;
  image: PublicImage | null;
  price: string | null;
  available: boolean;
  swatch: string | null;
};
export type ProductBundleItem = PublicItem & {
  main: boolean;
  included: boolean;
  quantity: number;
};
/** Everything the product page's main content block shows (see ProductPage on the server). */
export type CartLine = PublicItem & {
  quantity: number;
  /** Price × quantity; null when the line cannot be bought as it stands (then `problem` says why). */
  total: string | null;
  /** The line total without tax, when the store shows prices without tax and they differ. */
  total_without_tax?: string | null;
  problem: string | null;
  /** What the quantity stepper allows (max already respects enforced stock). */
  rules: { min: number; max: number; step: number };
};
/** The order as the server would place it with the shopper's current choices; every amount is the server's. */
export type CheckoutQuote = {
  needs_shipping: boolean;
  /** The store has shipping methods, so one that delivers to the address must be chosen. */
  shipping_required: boolean;
  /** A cart line cannot be bought as it stands: the cart must be reviewed first. */
  blocked: boolean;
  shipping_methods: {
    id: string;
    name: string;
    amount: string;
    image: PublicImage | null;
  }[];
  shipping_method_id: string | null;
  payment_methods: {
    id: string;
    name: string;
    description: string;
    fee: string | null;
    image: PublicImage | null;
  }[];
  payment_method_id: string | null;
  totals: {
    subtotal: string;
    discount: string;
    shipping: string | null;
    payment_fee: string;
    tax_added: string;
    tax_included: string;
    total: string;
    gift_card: string;
    due: string;
  } | null;
  discounts: string[];
  coupon: { code: string; problem: string | null } | null;
  gift_card: {
    code: string;
    amount: string | null;
    problem: string | null;
  } | null;
};
export type CheckoutConfirmation = {
  number: string;
  email: string;
  lines: {
    name: string;
    variation: string | null;
    quantity: number;
    total: string;
    image: PublicImage | null;
    url: string | null;
  }[];
  totals: {
    subtotal: string;
    discount: string;
    shipping: string | null;
    payment_fee: string;
    tax_added: string;
    total: string;
    due: string;
  };
  shipping_method: string | null;
  invoice_requested: boolean;
  /** The buyer's comments on the order; empty when none. */
  note: string;
  /** The delivery (or billing) address; null when the order has none. */
  address: {
    name: string;
    lines: string[];
    phone: string | null;
    extras: { label: string; value: string }[];
  } | null;
  payment: {
    name: string | null;
    state: string;
    instructions: string;
    pay_url: string | null;
  };
  account_url: string | null;
  currency_format: CurrencyFormat;
};
export type CheckoutOptions = {
  /** Pages to accept; required ones must be ticked before ordering. Each opens in a dialog. */
  pages: { id: string; name: string; content: string; required: boolean }[];
  newsletter: { list: string; label: string } | null;
  invoice: boolean;
};
export type CheckoutPageData = {
  cart?: CartPageData;
  /** The store requires an account and the visitor is a guest: only sign-in and registration are offered. */
  account_required?: boolean;
  /** The signed-in customer's details; null for guests. */
  contact?: {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
  } | null;
  addresses?: { id: string; label: string | null; lines: string[] }[];
  defaults?: { shipping: string | null; billing: string | null };
  countries?: { code: string; name: string }[];
  invoice_greek_sign_in?: boolean;
  invoice_countries?: { code: string; name: string }[];
  regions?: Record<string, { code: string; name: string }[]>;
  quote?: CheckoutQuote;
  /** Set on the confirmation page instead of everything above. */
  placed?: CheckoutConfirmation;
};
export type CartPageData = {
  /** The cart's public id; null while the visitor has no cart. */
  id: string | null;
  lines: CartLine[];
  /** Units of every shown line. */
  count: number;
  /** Total of the lines that can be bought. */
  subtotal: string;
  /** The products total without tax, when the store shows prices without tax and it differs. */
  subtotal_without_tax?: string | null;
  /** Automatic promotions actually granted by the server, including any tax savings. */
  promotions: {
    id: string;
    name: string;
    description: string;
    discount: string;
  }[];
  /** The entered coupon: what it takes off, or why it does not apply (then discount is null). */
  coupon: {
    code: string;
    discount: string | null;
    problem: string | null;
  } | null;
  /** The entered gift card (code masked): what it pays, or why it cannot (then amount is null). */
  gift_card: {
    code: string;
    amount: string | null;
    problem: string | null;
  } | null;
  /** What is left to pay after the coupon and the gift card. */
  total: string;
  /** That amount without tax, when the store shows prices without tax. */
  total_without_tax?: string | null;
  /** Whether shoppers may enter coupon and gift card codes. */
  codes: boolean;
  currency_format: CurrencyFormat;
};
export type StaticPageData = {
  id: string;
  name: string;
  /** Sanitized page HTML; empty when it has none. */
  content: string;
  url: string;
  faqs: { id: string; question: string; answer: string }[];
};
type BlogImage = { url: string; width: number; height: number } | null;
export type BlogData = {
  id: string;
  name: string;
  /** Sanitized description HTML; empty when it has none. */
  content: string;
  url: string;
  posts: {
    id: string;
    name: string;
    url: string;
    excerpt: string;
    published_at: string | null;
    cover: BlogImage;
    cover_alt: string;
    /** Optional cover for phones; phones show `cover` without one. */
    cover_mobile?: BlogImage;
  }[];
  page: number;
  last_page: number;
};
export type BlogPostData = {
  id: string;
  name: string;
  content: string;
  excerpt: string;
  url: string;
  published_at: string | null;
  cover: BlogImage;
  cover_alt: string;
  cover_mobile?: BlogImage;
  tags: string[];
  blog: { name: string; url: string };
};
export type CategoryLandingData = {
  /** Blog posts the category links to (shown above its questions). */
  posts?: BlogData['posts'];
  /** Set until the visitor confirms the age the category requires; then nothing else is sent. */
  age_gate?: { age: number; message: string } | null;
  id: string;
  name: string;
  /** The category's description (sanitized rich text); empty when it has none. */
  description: string;
  url: string | null;
  /** The category trail from the top category down to this one. */
  path: { name: string; url: string | null }[];
  /** The wide picture under the title, if the category has one. */
  banner?: PublicImage | null;
  banner_alt?: string;
  /** Where a click on the banner goes, if anywhere. */
  banner_link?: string | null;
  items: {
    id: string;
    name: string;
    url: string | null;
    image: PublicImage | null;
    image_alt: string;
  }[];
  /** The category's questions and answers. */
  faqs?: { id: string; question: string; answer: string }[];
};
/** One filter of a product listing; every value is set in the address under `param` (comma-separated). */
export type ListingFilterGroup = {
  key: string;
  name: string;
  param: string;
} & (
  | {
      kind: 'choice' | 'rating';
      options: {
        value: string;
        label: string;
        count: number;
        selected: boolean;
        stars?: number;
        /** An option with its own address parameter (sticker labels: ?product-label=…). */
        param?: string;
      }[];
    }
  | {
      /** `param_min` and `param_max` in the address; money ranges are prices. */
      kind: 'range';
      money: boolean;
      unit: string;
      min: string;
      max: string;
      from: string | null;
      to: string | null;
    }
);
/** A filter in effect, shown as a removable chip above the products. */
export type ListingActiveFilter =
  | {
      kind: 'choice';
      param: string;
      name: string;
      value: string;
      label: string;
    }
  | {
      kind: 'range';
      param: string;
      name: string;
      money: boolean;
      unit: string;
      from: string | null;
      to: string | null;
    };
export type ListingFilters = {
  total: number;
  groups: ListingFilterGroup[];
  active: ListingActiveFilter[];
  currency_format: CurrencyFormat;
  /** Values each filter shows before "Show more". */
  visible_options: number;
};
/** A brand page (or a legacy feature-option landing page): its picture, name and description. */
export type BrandPageData = {
  id: string;
  source: 'brand' | 'feature_option';
  name: string;
  description: string;
  url: string;
  image: PublicImage | null;
  image_alt: string;
  banner?: PublicImage | null;
  banner_link?: string | null;
  banner_alt?: string;
};
/** What a listing shows above its products: a category, or a brand. */
export type ListingData = {
  /** The search page: the query and whether the search engine answered (`results`), was not asked (`no_query`),
   * could not answer (`unavailable`), is still building its index (`not_ready`) or answered while counts are withheld
   * (`degraded`). */
  search?: {
    query: string;
    state:
      | 'results'
      | 'no_query'
      | 'too_short'
      | 'unavailable'
      | 'not_ready'
      | 'degraded';
    /** The store's minimum number of characters (`too_short`). */
    min?: number;
  };
  category?: CategoryLandingData;
  brand?: BrandPageData;
  items?: PublicItem[];
  currency_format?: CurrencyFormat;
  display?: PublicDisplay;
  total?: number;
  page?: number;
  last_page?: number;
  sort?: string;
  default_sort?: string;
  sorts?: string[];
  active?: ListingActiveFilter[];
  per_row?: number;
  mobile_per_row?: 1 | 2;
  tablet_per_row?: 2 | 3;
  show_count?: boolean;
  show_sorting?: boolean;
  /** The visitor's comparison (null while comparison is switched off). */
  comparison?: { max: number; ids: string[] } | null;
};
export type ComparisonState = {
  enabled: boolean;
  max: number;
  ids: string[];
  in_list: boolean;
};
export type ComparisonRow = {
  id: string;
  name: string;
  /** One value per compared product (null where a product has none). */
  values: (string | null)[];
  /** Every product has the same value. */
  same: boolean;
};
export type ComparisonPageData = {
  items: PublicItem[];
  groups: { name: string; rows: ComparisonRow[] }[];
  max: number;
  currency_format: CurrencyFormat;
};
export type ProductPageData = PublicItem & {
  package?: {
    name: string;
    items: (PublicItem & { quantity: number })[];
    available: boolean;
    total: string | null;
    currency_format: CurrencyFormat;
  } | null;
  /** The visitor's comparison list; null while comparison is switched off. */
  comparison?: ComparisonState | null;
  name: string;
  url: string;
  short_description: string;
  description: string;
  currency_format: CurrencyFormat;
  images: PublicImage[];
  category: {
    name: string;
    url: string | null;
    path: { name: string; url: string | null }[];
  } | null;
  availability: { label: string; in_stock: boolean };
  free_shipping: boolean;
  returnable: boolean;
  faqs: { id: string; question: string; answer: string }[];
  documents: { id: string; name: string; type: string; url: string }[];
  brand_documents?: { id: string; name: string; type: string; url: string }[];
  price_history: { at: string; price: string }[];
  available_from: string | null;
  /** False while reviews are off for the store or this product: no stars or reviews section. */
  reviews_enabled?: boolean;
  rating: {
    count: number;
    average: string | null;
    distribution: Record<'1' | '2' | '3' | '4' | '5', number>;
  };
  features: { name: string; features: ProductFeature[] }[];
  highlights: ProductFeature[];
  variations: {
    id: string;
    name: string;
    style: 'text' | 'color' | 'image' | 'dropdown';
    options: ProductVariationOption[];
  }[];
  variation_alternatives?: (NonNullable<PublicItem['variants']>[number] & {
    price: string | null;
  })[];
  newest?: ProductPageData['similar'];
  recently_viewed?: ProductPageData['similar'];
  similar: {
    items: PublicItem[];
    currency_format: CurrencyFormat;
    display: PublicDisplay;
  } | null;
  saving: string | null;
  /** Active brand and its page; a missing logo uses the product image placeholder. */
  brand: {
    name: string;
    image: PublicImage | null;
    image_alt: string;
    url: string | null;
  } | null;
  /** The sale behind the shown price; ends_at is when it runs out (null without an end). */
  sale: { title: string | null; ends_at: string | null } | null;
  /** Purchasable amounts: the first allowed amount, an optional ceiling and the step between amounts. */
  quantity: { min: number; max: number | null; step: number };
  bought_together: {
    items: ProductBundleItem[];
    total: string | null;
    included: number;
    currency_format: CurrencyFormat;
  } | null;
  review: {
    url: string;
    signed_in: boolean;
    /** The signed-in customer's own review of this product, if any. */
    mine?: 'published' | 'pending' | 'not_published' | null;
  };
};
export type PublicReview = {
  id: string;
  rating: number;
  title: string | null;
  body: string;
  author: string;
  verified_purchase: boolean;
  created_at: string;
};
export type ReviewPage = {
  data: PublicReview[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};
export type WishlistSummary = {
  id: string;
  name: string | null;
  default: boolean;
  count: number;
  thumb: string | null;
  has: boolean;
};
export type WishlistPageData = {
  lists: WishlistSummary[];
  current: string;
  items: PublicItem[];
  currency_format: CurrencyFormat;
  display: PublicDisplay;
};

export type AccountAddress = {
  id: string;
  label: string | null;
  first_name: string;
  last_name: string;
  company: string | null;
  line1: string;
  line2: string | null;
  street_number: string | null;
  city: string;
  postal_code: string | null;
  country_code: string;
  country: string;
  region_code: string | null;
  region: string | null;
  phone: string | null;
  floor: string | null;
  bell_name: string | null;
  notes: string | null;
};
export type AccountOrder = {
  invoice_url?: string | null;
  id: string;
  number: string;
  created_at: string;
  status: { name: string; color: string } | null;
  units: number;
  total: string;
};
export type AccountOrderAddress = { lines: string[]; phone: string | null };
export type AccountOrderDetail = {
  invoice_details: {
    country_code: string;
    country: string;
    vat_number: string;
    business_name: string | null;
  } | null;
  invoice_url?: string | null;
  id: string;
  number: string;
  created_at: string;
  status: { name: string; color: string } | null;
  units: number;
  items: {
    id: string;
    name: string;
    url: string | null;
    sku: string | null;
    variation: string | null;
    quantity: number;
    unit_price: string;
    total: string;
    image: { url: string; width: number; height: number } | null;
  }[];
  totals: {
    subtotal: string;
    discount: string | null;
    shipping: string;
    shipping_free: boolean;
    /** The payment method's fee (e.g. cash on delivery), when the order has one. */
    payment_fee?: string | null;
    tax_included: string | null;
    total: string;
  };
  billing: AccountOrderAddress | null;
  shipping: AccountOrderAddress | null;
  shipping_method: string | null;
  payment_method: string | null;
  note: string | null;
  invoice_requested: boolean;
  timeline: { status: { name: string; color: string }; at: string }[];
  /** The customer may cancel the order now (its status allows it and nothing is paid). */
  can_cancel: boolean;
};
export type AccountReview = {
  id: string;
  product: string;
  rating: number;
  title: string | null;
  state: 'published' | 'pending' | 'not_published';
  verified_purchase: boolean;
  created_at: string;
};
/** What the Account layout's main content block shows; `section` names the part of the account that is open. */
export type AccountPageData = {
  section:
    | 'profile'
    | 'orders'
    | 'order'
    | 'addresses'
    | 'reviews'
    | 'newsletters'
    | 'password';
  wishlist_enabled: boolean;
  name: string;
  profile?: {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    since: string;
    version: number;
  };
  orders?: {
    rows: AccountOrder[];
    page: number;
    last_page: number;
    total: number;
  };
  order?: AccountOrderDetail;
  version?: number;
  defaults?: { billing: string | null; shipping: string | null };
  addresses?: AccountAddress[];
  countries?: { code: string; name: string }[];
  regions?: Record<string, { code: string; name: string }[]>;
  reviews?: AccountReview[];
  reviewable?: { id: string; name: string }[];
  /** Newsletter lists the customer is on; `confirmed` is false until they answer the confirmation e-mail. */
  newsletters?: {
    id: string;
    name: string;
    since: string;
    confirmed: boolean;
  }[];
  staff_rule?: boolean;
};

export type DirectoryLink = { name: string; url: string | null };
export type DirectoryNode = DirectoryLink & {
  id: string;
  children: DirectoryNode[];
};
export type DirectoryData =
  | {
      kind: 'sitemap';
      pages: DirectoryLink[];
      account: DirectoryLink[];
      categories: DirectoryNode[];
    }
  | {
      kind: 'brands';
      groups: { letter: string; items: (DirectoryLink & { id: string })[] }[];
    };
