import {
  createContext,
  useContext,
  useId,
  useEffect,
  useRef,
  useState,
  type CSSProperties,
  type ReactNode,
} from 'react';
import { DealCard } from './DealCard';
import StorefrontBanner from '../../components/StorefrontBanner';
import { useTranslation } from '../../../lib/useTranslation';
import { useDateFormat } from './AccountParts';
import { useCart } from './cartContext';
import { useWishlist } from './wishlistContext';
import type {
  CurrencyFormat,
  PublicBlock,
  PublicDisplay,
  PublicImage,
  PublicItem,
  PublicSticker,
} from '../../types';
/** A button that explains itself in a tooltip on hover, focus or tap; Escape closes it. */
export function InfoTip({
  className,
  label,
  text,
  children,
}: {
  className: string;
  label: string;
  text: string;
  children?: ReactNode;
}) {
  const tooltipId = useId();
  const [open, setOpen] = useState(false);
  return (
    <span
      className={`sf-infotip ${className}`}
      data-open={open || undefined}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <button
        type="button"
        aria-label={label}
        aria-describedby={tooltipId}
        onClick={() => setOpen(true)}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onKeyDown={(event) => {
          if (event.key === 'Escape') {
            setOpen(false);
            event.currentTarget.blur();
          }
        }}
      >
        {children}
        <svg
          aria-hidden="true"
          viewBox="0 0 20 20"
          width="15"
          height="15"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
        >
          <circle cx="10" cy="10" r="7" />
          <path d="M10 9v5M10 6v1" />
        </svg>
      </button>
      <span
        id={tooltipId}
        role="tooltip"
        className="sf-infotip-tooltip"
        hidden={!open}
      >
        {text}
      </span>
    </span>
  );
}
export function Picture({
  image,
  mobile,
  tablet,
  alt,
  eager = false,
  nearby = false,
}: {
  image: PublicImage;
  mobile?: PublicImage | null;
  tablet?: PublicImage | null;
  alt: string;
  /** Above-the-fold pictures such as the logo load immediately. */
  eager?: boolean;
  /** Loads immediately at normal priority (a carousel's first row, which a side-scrolling row would otherwise keep waiting). */
  nearby?: boolean;
}) {
  return (
    <picture>
      {mobile && <source media="(max-width: 767px)" srcSet={mobile.url} />}{' '}
      {tablet && <source media="(max-width: 1023px)" srcSet={tablet.url} />}
      <img
        src={image.url}
        alt={alt}
        width={image.width}
        height={image.height}
        loading={eager || nearby ? 'eager' : 'lazy'}
        fetchPriority={eager ? 'high' : undefined}
      />
    </picture>
  );
}

/** Formats a decimal string with the currency's own settings, without floating point. The decimals are set small. */
/** "(€53.00 without VAT)" beneath a price, when the store shows prices without tax. */
export function NetPrice({
  item,
  format,
}: {
  item: { price_without_tax?: string | null };
  format: CurrencyFormat;
}) {
  const t = useTranslation();
  if (!item.price_without_tax) return null;
  return (
    <small className="sf-price-net">
      (<Money amount={item.price_without_tax} format={format} inline />{' '}
      {t('storefront.without_vat')})
    </small>
  );
}

export function Money({
  amount,
  format,
  inline = false,
}: {
  amount: string;
  format: CurrencyFormat;
  /** Decimals on the line (struck-through and small amounts) instead of raised. */
  inline?: boolean;
}) {
  const [whole, fraction = ''] = amount.split('.');
  const major = whole.replace(/\B(?=(\d{3})+(?!\d))/g, format.thousands);
  const minor = fraction.padEnd(format.decimals, '0').slice(0, format.decimals);
  const text = `${major}${minor ? format.decimal + minor : ''}`;
  const before = format.position === 'before';
  return (
    <span
      className="sf-money"
      aria-label={
        before ? `${format.symbol}${text}` : `${text} ${format.symbol}`
      }
    >
      <span aria-hidden="true">
        {before && format.symbol}
        {major}
        {minor && (inline ? `${format.decimal}${minor}` : <sup>{minor}</sup>)}
        {!before && <span className="sf-symbol-after">{format.symbol}</span>}
      </span>
    </span>
  );
}
/** Neutral stand-in so cards without a picture keep their shape. */
export function NoImage() {
  return (
    <svg
      className="sf-no-image"
      aria-hidden="true"
      viewBox="0 0 64 64"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <rect x="8" y="12" width="48" height="40" rx="5" />
      <circle cx="23" cy="26" r="4" />
      <path d="m10 48 15-15 10 10 8-8 11 11" />
    </svg>
  );
}
export function Chevron({ direction }: { direction: 'prev' | 'next' }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="18"
      height="18"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d={direction === 'prev' ? 'm15 5-7 7 7 7' : 'm9 5 7 7-7 7'} />
    </svg>
  );
}
export function TrashIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="20"
      height="20"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12M9 7V4h6v3" />
    </svg>
  );
}

/** Questions and answers that open one by one (product pages and category landing pages). */
export function FaqList({
  faqs,
}: {
  faqs: { id: string; question: string; answer: string }[];
}) {
  return (
    <div className="sf-pdp-faqs">
      {faqs.map((faq) => (
        <details key={faq.id}>
          <summary>{faq.question}</summary>
          <div
            className="sf-prose"
            dangerouslySetInnerHTML={{ __html: faq.answer }}
          />
        </details>
      ))}
    </div>
  );
}

export function CartIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="22"
      height="22"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M3 4h2.5l2.2 11h10.2l2-8H6.4" />
      <circle cx="9" cy="19.5" r="1.3" />
      <circle cx="17" cy="19.5" r="1.3" />
    </svg>
  );
}
export function Stickers({
  stickers,
  placement,
}: {
  stickers: PublicSticker[];
  placement: PublicSticker['placement'];
}) {
  const own = stickers.filter((s) => s.placement === placement);
  if (own.length === 0) return null;
  return (
    <div className={`sf-stickers sf-stickers-${placement}`}>
      {own.map((s) => (
        <span
          key={s.id}
          title={s.tooltip || undefined}
          className={`sf-sticker ${s.visibility.mobile ? '' : 'sf-hide-mobile'} ${s.visibility.tablet ? '' : 'sf-hide-tablet'} ${s.visibility.desktop ? '' : 'sf-hide-desktop'} ${s.css_class}`}
          style={
            s.style === 'text'
              ? {
                  background: s.background,
                  color: s.color,
                  border: `${s.border_width}px solid ${s.border_color}`,
                  borderRadius: s.radius,
                  padding: s.padding,
                  fontSize: s.font_size,
                  textAlign: s.alignment,
                }
              : undefined
          }
        >
          {s.style === 'image' && s.image ? (
            <img
              src={s.image.url}
              alt={s.alt}
              width={s.image.width}
              height={s.image.height}
              loading="lazy"
            />
          ) : (
            s.text
          )}
        </span>
      ))}
    </div>
  );
}
/** In horizontal mobile cards, image stickers are shown with the title instead of covering the picture. */
function MobileCardStickers({ stickers }: { stickers: PublicSticker[] }) {
  const visible = stickers.filter(
    (sticker) =>
      sticker.visibility.mobile &&
      ['image_top', 'image_bottom'].includes(sticker.placement),
  );
  if (visible.length === 0) return null;
  return (
    <div className="sf-card-mobile-stickers">
      <Stickers stickers={visible} placement="image_top" />
      <Stickers stickers={visible} placement="image_bottom" />
    </div>
  );
}

/** A blog post card in the blog posts block: cover, date, title and excerpt, all leading to the post. */
export function BlogCard({
  item,
  display,
  featured = false,
}: {
  item: PublicItem;
  display: PublicDisplay;
  featured?: boolean;
}) {
  const date = useDateFormat();
  const t = useTranslation();
  return (
    <article
      className={`sf-blog-card ${display.card_shadow ? 'sf-card-shadow' : ''} ${featured ? 'sf-blog-featured' : ''}`}
    >
      <a href={item.url ?? undefined} className="sf-blog-card-link">
        {item.image && (
          <Picture
            image={item.image}
            mobile={item.image_mobile}
            alt={item.image_alt ?? ''}
          />
        )}
        <div className="sf-blog-card-body">
          {display.show_date && item.published_at && (
            <time dateTime={item.published_at}>{date(item.published_at)}</time>
          )}
          <h3>{item.name}</h3>
          {display.show_excerpt && item.excerpt && <p>{item.excerpt}</p>}
          {featured && item.url && (
            <span className="sf-blog-cta">
              {t('storefront.blog_read_article')}
              <span aria-hidden="true">→</span>
            </span>
          )}
        </div>
      </a>
    </article>
  );
}
export function CategoryTile({
  item,
  nearby = false,
}: {
  item: PublicItem;
  /** Tiles in a carousel's first row load at once: a lazy picture in a side-scrolling row waits for a scroll. */
  nearby?: boolean;
}) {
  const body = (
    <>
      <div className="sf-media">
        {item.image ? (
          <Picture
            image={item.image}
            alt={item.image_alt ?? ''}
            nearby={nearby}
          />
        ) : (
          <NoImage />
        )}
      </div>
      <h3>{item.name}</h3>
    </>
  );
  return item.url ? (
    <a
      href={item.url}
      className="sf-category-link"
      target={item.new_window ? '_blank' : undefined}
      rel={item.new_window ? 'noopener noreferrer' : undefined}
    >
      {body}
    </a>
  ) : (
    body
  );
}
function MenuTile({ item }: { item: PublicItem }) {
  const content = (
    <>
      <span className="sf-menu-picture">
        {item.image ? <Picture image={item.image} alt="" /> : <NoImage />}
      </span>
      <span className="sf-menu-label">{item.name}</span>
    </>
  );
  return item.url ? (
    <a
      className="sf-menu-tile"
      href={item.url}
      target={item.new_window ? '_blank' : undefined}
      rel={item.new_window ? 'noopener noreferrer' : undefined}
    >
      {content}
    </a>
  ) : (
    <span className="sf-menu-tile">{content}</span>
  );
}
export function ProductLink({
  url,
  label,
  children,
}: {
  url?: string | null;
  label?: string;
  children: React.ReactNode;
}) {
  return url ? (
    <a href={url} className="sf-product-link" aria-label={label}>
      {children}
    </a>
  ) : (
    <>{children}</>
  );
}
/** General settings → Catalog → product card style; the theme provides it for every product card. */
export const CardStyleContext = createContext<
  'classic' | 'showcase' | 'signature'
>('classic');

type CardProps = {
  item: PublicItem;
  display: PublicDisplay;
  format: CurrencyFormat;
  mobileHorizontal?: boolean;
};

/** A product card in the style chosen in the general settings (the product blocks' own settings apply to both). */
export function Product(props: CardProps) {
  const style = useContext(CardStyleContext);
  return style !== 'classic' ? (
    <ShowcaseProduct {...props} signature={style === 'signature'} />
  ) : (
    <ClassicProduct {...props} />
  );
}

function HeartIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="24"
      height="24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinejoin="round"
    >
      <path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" />
    </svg>
  );
}

/** Stars filled to the average (partial stars included) in the store's primary color. */
export function Stars({ value, size = 18 }: { value: number; size?: number }) {
  const id = useId();
  return (
    <span className="sf-stars" aria-hidden="true">
      {[1, 2, 3, 4, 5].map((n) => {
        const fill = Math.max(0, Math.min(1, value - n + 1));
        const gradient = `${id}-${n}`;
        return (
          <svg key={n} viewBox="0 0 24 24" width={size} height={size}>
            <defs>
              <linearGradient id={gradient}>
                <stop offset={`${fill * 100}%`} stopColor="currentColor" />
                <stop offset={`${fill * 100}%`} stopColor="#d9d9d9" />
              </linearGradient>
            </defs>
            <path
              fill={`url(#${gradient})`}
              d="m12 2.8 2.8 5.7 6.3.9-4.6 4.4 1.1 6.2L12 17.1 6.4 20l1.1-6.2L2.9 9.4l6.3-.9z"
            />
          </svg>
        );
      })}
    </span>
  );
}

/** Stars and review count under a product name (block option "show reviews"). */
function CardRating({
  item,
  display,
}: {
  item: PublicItem;
  display: PublicDisplay;
}) {
  const t = useTranslation();
  if (!display.show_rating || !item.rating) return null;
  return (
    <p
      className="sf-card-rating"
      aria-label={t('storefront.rating_summary', {
        average: item.rating.average ?? '',
        count: item.rating.count,
      })}
    >
      <Stars value={Number(item.rating.average ?? 0)} size={16} />
      <span aria-hidden="true">({item.rating.count})</span>
    </p>
  );
}

/** The heart on a product card: filled when the product is saved; opens the "add to list" window. */
function FavouriteButton({ item }: { item: PublicItem }) {
  const t = useTranslation();
  const wishlist = useWishlist();
  const saved = wishlist.isFavourite(item);
  if (!wishlist.enabled) return null;
  const label = t(
    saved ? 'storefront.wishlist_saved' : 'storefront.add_to_favourites',
  );
  return (
    <button
      type="button"
      className={`sf-card-heart ${saved ? 'is-saved' : ''}`}
      aria-label={label}
      aria-pressed={saved}
      title={label}
      onClick={() => wishlist.open(item)}
    >
      <HeartIcon />
    </button>
  );
}

/** The product listing's comparison: cards inside it get a compare button under the heart (product blocks do not). */
export const CardComparisonContext = createContext<{
  ids: string[];
  toggle: (id: string, item?: PublicItem) => void;
} | null>(null);

/** The compare button under a card's heart: adds the product to the visitor's comparison or removes it. */
function CompareButton({ item }: { item: PublicItem }) {
  const t = useTranslation();
  const comparison = useContext(CardComparisonContext);
  if (!comparison) return null;
  const active = comparison.ids.includes(item.id);
  const label = t(
    active ? 'storefront.comparison_remove' : 'storefront.comparison_add',
  );
  return (
    <button
      type="button"
      className={`sf-card-compare ${active ? 'is-active' : ''}`}
      aria-label={label}
      aria-pressed={active}
      title={label}
      onClick={() => comparison.toggle(item.id, item)}
    >
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        width="22"
        height="22"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7" />
      </svg>
    </button>
  );
}

/** The brand and the category above a card's name (block options "show the brand" and "show the product category"). */
function CardCategory({
  item,
  display,
}: {
  item: PublicItem;
  display: PublicDisplay;
}) {
  const links = [
    display.show_brand ? item.brand_link : null,
    display.show_category ? item.category : null,
  ].filter((link) => !!link);
  if (links.length === 0) return null;
  return (
    <p className="sf-card-category">
      {links.map((link, n) => (
        <span key={n}>
          {link!.url ? <a href={link!.url}>{link!.name}</a> : link!.name}
        </span>
      ))}
    </p>
  );
}

/**
 * The variation group as small pictures under the card's picture, in one sideways-scrolling row (swipe, scroll or the
 * arrows); the card's own product is marked and brought into view.
 */
function CardVariations({
  item,
  display,
}: {
  item: PublicItem;
  display: PublicDisplay;
}) {
  const t = useTranslation();
  const track = useRef<HTMLUListElement>(null);
  const [edges, setEdges] = useState({ start: true, end: true });
  const variations = item.variants ?? [];
  const shown = !!display.show_variations && variations.length > 1;
  useEffect(() => {
    const list = track.current;
    if (!list) return;
    const current = list.querySelector<HTMLElement>('[aria-current]');
    if (current)
      list.scrollLeft =
        current.offsetLeft - (list.clientWidth - current.offsetWidth) / 2;
    const update = () =>
      setEdges({
        start: list.scrollLeft <= 1,
        end: list.scrollLeft + list.clientWidth >= list.scrollWidth - 1,
      });
    const frame = requestAnimationFrame(update);
    list.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    return () => {
      cancelAnimationFrame(frame);
      list.removeEventListener('scroll', update);
      window.removeEventListener('resize', update);
    };
  }, [shown]);
  if (!shown) return null;
  const move = (direction: number) =>
    track.current?.scrollBy({
      left: direction * track.current.clientWidth * 0.8,
      behavior: 'smooth',
    });
  return (
    <div className="sf-card-variations">
      {!edges.start && (
        <button
          type="button"
          className="sf-card-variations-arrow is-prev"
          aria-label={t('storefront.previous')}
          onClick={() => move(-1)}
        >
          <Chevron direction="prev" />
        </button>
      )}
      <ul ref={track} aria-label={t('storefront.variations')}>
        {variations.map((v) => {
          const picture = v.image ? (
            <img src={v.image.url} alt="" loading="lazy" />
          ) : (
            <NoImage />
          );
          return (
            <li key={v.id}>
              {v.id === item.id ? (
                <span className="is-current" aria-current="true" title={v.name}>
                  {picture}
                  <span className="sf-visually-hidden">{v.name}</span>
                </span>
              ) : (
                <a href={v.url ?? undefined} title={v.name} aria-label={v.name}>
                  {picture}
                </a>
              )}
            </li>
          );
        })}
      </ul>
      {!edges.end && (
        <button
          type="button"
          className="sf-card-variations-arrow is-next"
          aria-label={t('storefront.next')}
          onClick={() => move(1)}
        >
          <Chevron direction="next" />
        </button>
      )}
    </div>
  );
}

/** A card's cart button: puts one of the product in the cart (the server raises it to the product's minimum). */
function CardCartButton({
  item,
  className,
  showLabel = false,
}: {
  item: PublicItem;
  className: string;
  showLabel?: boolean;
}) {
  const t = useTranslation();
  const cart = useCart();
  const [busy, setBusy] = useState(false);
  if (item.view_product && item.url) {
    const label = t('variations.view_product_link');
    return (
      <a href={item.url} className={className} aria-label={label} title={label}>
        <svg
          viewBox="0 0 24 24"
          width="24"
          height="24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.8"
          aria-hidden="true"
        >
          <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
          <circle cx="12" cy="12" r="3" />
        </svg>
        {showLabel && <span className="sf-card-cart-label">{label}</span>}
      </a>
    );
  }
  if (!cart.enabled || item.price == null) return null;
  const buyable = !!item.available;
  const label = t(
    buyable ? 'storefront.add_to_cart' : 'storefront.unavailable',
  );
  return (
    <button
      type="button"
      className={className}
      disabled={!buyable || busy}
      aria-label={label}
      title={label}
      onClick={async () => {
        setBusy(true);
        await cart.add({ id: item.id, name: item.name }, 1);
        setBusy(false);
      }}
    >
      <CartIcon />
      {showLabel && buyable && (
        <span className="sf-card-cart-label">{label}</span>
      )}
    </button>
  );
}

/** Share a trailing unit only when every value is numeric and uses that same unit. */
function compactFeatureValues(values: string[]): string {
  const parts = values.map((value) =>
    value.trim().match(/^(\d+(?:[.,]\d+)?)\s*(\p{L}[\p{L}\p{N}\s/%°²³-]*)$/u),
  );
  const unit = parts[0]?.[2].trim();
  if (
    !unit ||
    parts.some(
      (part) => !part || part[2].trim().toLowerCase() !== unit.toLowerCase(),
    )
  ) {
    return values.join(', ');
  }
  return parts.map((part) => part![1]).join(', ') + ' ' + unit;
}

function CardColorSwatch({
  swatch,
}: {
  swatch: {
    label: string;
    color: string | null;
    selected: boolean;
    url?: string;
  };
}) {
  const circle = (
    <span
      aria-hidden="true"
      style={swatch.color ? { backgroundColor: swatch.color } : undefined}
      className={swatch.color ? '' : 'is-unspecified'}
    />
  );
  const className =
    'sf-card-color-swatch' + (swatch.selected ? ' is-selected' : '');
  return swatch.url ? (
    <a
      href={swatch.url}
      aria-label={swatch.label}
      title={swatch.label}
      className={className}
    >
      {circle}
    </a>
  ) : (
    <span
      role="img"
      aria-label={swatch.label}
      title={swatch.label}
      tabIndex={0}
      className={className}
    >
      {circle}
    </span>
  );
}

function VariationFeatureSummary({
  item,
  colors = false,
}: {
  item: PublicItem;
  colors?: boolean;
}) {
  const t = useTranslation();
  return (
    <>
      {(item.variation_features ?? [])
        .filter((feature) => !!feature.swatches === colors)
        .map((feature) =>
          feature.swatches ? (
            <div
              key={feature.name}
              className="sf-card-feature-values sf-card-color-values"
              role="group"
              aria-label={feature.name}
            >
              {feature.swatches.slice(0, 6).map((swatch) => (
                <CardColorSwatch key={swatch.label} swatch={swatch} />
              ))}
              {feature.swatches.length > 6 && (
                <details>
                  <summary
                    aria-label={t('variations.more_values')}
                    title={t('variations.more_values')}
                  >
                    +{feature.swatches.length - 6}
                  </summary>
                  <div className="sf-card-color-overflow">
                    {feature.swatches.slice(6).map((swatch) => (
                      <CardColorSwatch key={swatch.label} swatch={swatch} />
                    ))}
                  </div>
                </details>
              )}
            </div>
          ) : (
            <div className="sf-card-feature-values" key={feature.name}>
              <strong>{feature.name}: </strong>
              {compactFeatureValues(feature.values.slice(0, 4))}
              {feature.values.length > 4 && (
                <details>
                  <summary aria-label={t('variations.more_values')}>…</summary>
                  <span>{compactFeatureValues(feature.values.slice(4))}</span>
                </details>
              )}
            </div>
          ),
        )}
    </>
  );
}

/** Showcase card: large picture with the cart button on its corner, reference price, price and the discount. */
function ShowcaseProduct({
  item,
  display,
  format,
  signature = false,
  mobileHorizontal = false,
}: CardProps & { signature?: boolean }) {
  const t = useTranslation();
  const stickers = item.stickers ?? [];
  const price =
    display.show_price !== false &&
    item.price !== null &&
    item.price !== undefined;
  return (
    <article
      className={`sf-product sf-product-showcase ${signature ? 'sf-product-signature' : ''} ${display.card_shadow === false ? 'sf-no-shadow' : ''}`}
    >
      <div className="sf-media-wrap">
        <div className="sf-card-media-inner">
          <ProductLink url={item.url} label={item.name}>
            <div className="sf-media">
              {item.image ? (
                <Picture image={item.image} alt={item.name ?? ''} />
              ) : (
                <NoImage />
              )}
            </div>
          </ProductLink>
          <Stickers stickers={stickers} placement="image_top" />
          <Stickers stickers={stickers} placement="image_bottom" />
          {display.show_favorites !== false && <FavouriteButton item={item} />}
          <CompareButton item={item} />
        </div>
        {!signature && display.show_add_to_cart !== false && (
          <CardCartButton item={item} className="sf-showcase-cart" />
        )}
      </div>
      <div className="sf-product-details">
        <CardVariations item={item} display={display} />
        <div className="sf-showcase-body">
          {mobileHorizontal && <MobileCardStickers stickers={stickers} />}
          <CardCategory item={item} display={display} />
          <VariationFeatureSummary item={item} colors />
          <h3 title={item.name}>
            <ProductLink url={item.url}>{item.name}</ProductLink>
          </h3>
          <CardRating item={item} display={display} />
          {display.show_sku && item.sku && (
            <p className="sf-sku">
              {t('storefront.sku')}: {item.sku}
            </p>
          )}
          {display.show_availability && (
            <p
              className={`sf-availability ${item.available ? 'is-in' : 'is-out'}`}
            >
              {t(
                item.available
                  ? 'storefront.available'
                  : 'storefront.unavailable',
              )}
            </p>
          )}
          <VariationFeatureSummary item={item} />
          <Stickers stickers={stickers} placement="above_price" />
          {price && (
            <div className="sf-showcase-prices">
              {!signature && item.base_price && (
                <del>
                  <Money amount={item.base_price} format={format} inline />
                </del>
              )}
              <div className={signature ? 'sf-signature-price-row' : undefined}>
                <p className="sf-showcase-price">
                  {item.price_from && (
                    <span>{t('storefront.price_from')} </span>
                  )}
                  <Money amount={item.price!} format={format} inline />
                </p>
                {signature && !!item.discount_percent && (
                  <span className="sf-showcase-badge">
                    -{item.discount_percent}%
                  </span>
                )}
              </div>
              {signature && item.base_price && (
                <del>
                  <Money amount={item.base_price} format={format} inline />
                </del>
              )}
              <NetPrice item={item} format={format} />
              {!signature && item.discount_percent ? (
                <p className="sf-showcase-drop">
                  <span className="sf-showcase-badge">
                    -{item.discount_percent}%
                  </span>
                  <span className="sf-showcase-drop-label">
                    {t('storefront.price_drop')}
                  </span>
                </p>
              ) : null}
            </div>
          )}
          <Stickers stickers={stickers} placement="below_price" />
          {signature && display.show_add_to_cart !== false && (
            <CardCartButton
              item={item}
              className="sf-signature-cart"
              showLabel
            />
          )}
        </div>
      </div>
    </article>
  );
}

function ClassicProduct({
  item,
  display,
  format,
  mobileHorizontal = false,
}: CardProps) {
  const t = useTranslation();
  const stickers = item.stickers ?? [];
  const price =
    display.show_price !== false &&
    item.price !== null &&
    item.price !== undefined;
  return (
    <article
      className={`sf-product ${display.card_shadow === false ? 'sf-no-shadow' : ''}`}
    >
      <div className="sf-media-wrap">
        <ProductLink url={item.url} label={item.name}>
          <div className="sf-media">
            {item.image ? (
              <Picture image={item.image} alt={item.name ?? ''} />
            ) : (
              <NoImage />
            )}
          </div>
        </ProductLink>
        <Stickers stickers={stickers} placement="image_top" />
        <Stickers stickers={stickers} placement="image_bottom" />
        {display.show_favorites !== false && <FavouriteButton item={item} />}
        <CompareButton item={item} />
      </div>
      <div className="sf-product-details">
        <CardVariations item={item} display={display} />
        {mobileHorizontal && <MobileCardStickers stickers={stickers} />}
        <CardCategory item={item} display={display} />
        <VariationFeatureSummary item={item} colors />
        <h3 title={item.name}>
          <ProductLink url={item.url}>{item.name}</ProductLink>
        </h3>
        <CardRating item={item} display={display} />
        {display.show_sku && item.sku && (
          <p className="sf-sku">
            {t('storefront.sku')}: {item.sku}
          </p>
        )}
        {display.show_availability && (
          <p
            className={`sf-availability ${item.available ? 'is-in' : 'is-out'}`}
          >
            {t(
              item.available
                ? 'storefront.available'
                : 'storefront.unavailable',
            )}
          </p>
        )}
        <VariationFeatureSummary item={item} />
        <Stickers stickers={stickers} placement="above_price" />
        <div className="sf-product-foot">
          {price ? (
            <p className="sf-price">
              {item.base_price && (
                <del>
                  <Money amount={item.base_price} format={format} inline />
                </del>
              )}
              {item.price_from && <span>{t('storefront.price_from')} </span>}
              <Money amount={item.price!} format={format} />
              <NetPrice item={item} format={format} />
            </p>
          ) : (
            <span />
          )}
          {display.show_add_to_cart !== false && (
            <CardCartButton item={item} className="sf-cart" />
          )}
        </div>
        <Stickers stickers={stickers} placement="below_price" />
      </div>
    </article>
  );
}
/** − value + buttons that only offer quantities the rules allow. */
export function QuantityStepper({
  rules,
  value,
  onChange,
}: {
  /** Smallest quantity, largest (none: unlimited) and step. */
  rules: { min: number; max?: number | null; step: number };
  value: number;
  onChange: (value: number) => void;
}) {
  const t = useTranslation();
  const down = value - rules.step >= rules.min;
  const up = rules.max == null || value + rules.step <= rules.max;
  return (
    <div
      className="sf-pdp-qty"
      role="group"
      aria-label={t('storefront.quantity')}
    >
      <button
        type="button"
        aria-label={t('storefront.quantity_decrease')}
        disabled={!down}
        onClick={() => onChange(value - rules.step)}
      >
        −
      </button>
      <output aria-live="polite">{value}</output>
      <button
        type="button"
        aria-label={t('storefront.quantity_increase')}
        disabled={!up}
        onClick={() => onChange(value + rules.step)}
      >
        +
      </button>
    </div>
  );
}

export const FALLBACK_FORMAT: CurrencyFormat = {
  code: 'EUR',
  symbol: '€',
  position: 'before',
  thousands: ',',
  decimal: '.',
  decimals: 2,
};
const REDUCED = '(prefers-reduced-motion: reduce)';
export function Collection({
  block,
  renderItem,
}: {
  block: PublicBlock;
  renderItem?: (item: PublicItem, index: number) => ReactNode;
}) {
  const menuCards = block.template === 'menu_cards_carousel';
  const t = useTranslation();
  const track = useRef<HTMLDivElement>(null);
  const [active, setActive] = useState(0);
  const [paused, setPaused] = useState(false);
  const [edges, setEdges] = useState({
    overflow: false,
    start: true,
    end: true,
    pages: 1,
  });
  // "Infinite scroll": the first items follow the last (copies, hidden from assistive technology), so "next" keeps
  // moving the same way and the track jumps back unseen once the copies are in view.
  const [looping, setLooping] = useState(false);
  const settle = useRef<number | undefined>(undefined);
  const [dragging, setDragging] = useState(false);
  const drag = useRef<{ x: number; left: number; moved: boolean } | null>(null);
  const justDragged = useRef(false);
  const carousel =
    block.template.endsWith('carousel') &&
    !(block.template === 'blog_carousel' && block.content.items?.length === 1);
  const kind =
    block.template === 'coupon_carousel' || block.template === 'offer_carousel'
      ? 'deal'
      : block.template.startsWith('product') || block.content.brand
        ? 'product'
        : block.template.startsWith('reel')
          ? 'reel'
          : block.template.startsWith('banner')
            ? 'banner'
            : block.template.startsWith('brand')
              ? 'brand'
              : block.template.startsWith('blog')
                ? 'blog'
                : block.template.startsWith('menu_')
                  ? 'menu'
                  : 'category';
  const display = block.content.display ?? {};
  const format = block.content.currency_format ?? FALLBACK_FORMAT;
  const items = carousel
    ? (block.content.items ?? [])
    : (block.content.items?.slice(0, 1) ?? []);
  const navigation = display.navigation ?? 'outside';
  const dots = kind === 'banner' && display.navigation_style === 'dots';
  const seconds =
    kind === 'banner'
      ? (display.autoplay_seconds ?? 0)
      : display.autoscroll
        ? 4
        : 0;
  const step = () => {
    const el = track.current;
    const first = el?.querySelector<HTMLElement>('.sf-item');
    if (!el || !first) return 0;
    return (
      first.offsetWidth + (parseFloat(getComputedStyle(el).columnGap) || 0)
    );
  };
  const measure = () => {
    const el = track.current;
    if (!el) return;
    if (kind === 'blog') {
      const cover = el.querySelector<HTMLElement>('.sf-item picture');
      el.parentElement?.style.setProperty(
        '--sf-blog-arrow-top',
        cover ? `${cover.offsetTop + cover.offsetHeight / 2}px` : '50%',
      );
    }
    const width = step();
    const gap = parseFloat(getComputedStyle(el).columnGap) || 0;
    // The real items alone decide whether there is anything to scroll; the copies must not.
    const count = el.querySelectorAll('.sf-item:not([data-clone])').length;
    const overflows = count * width - gap > el.clientWidth + 1;
    if (display.infinite_scroll) setLooping(overflows);
    const looped = display.infinite_scroll && overflows;
    const next = {
      overflow: looped || el.scrollWidth > el.clientWidth + 1,
      start: el.scrollLeft <= 1,
      end: el.scrollLeft + el.clientWidth >= el.scrollWidth - 1,
      // Positions the carousel can rest on (one dot each): fewer than the banners when several show at once.
      pages: looped
        ? count
        : width > 0
          ? Math.max(
              1,
              Math.round((el.scrollWidth - el.clientWidth) / width) + 1,
            )
          : 1,
    };
    setEdges((old) =>
      old.overflow === next.overflow &&
      old.start === next.start &&
      old.end === next.end &&
      old.pages === next.pages
        ? old
        : next,
    );
  };
  useEffect(() => {
    const el = track.current;
    if (!carousel || !el) return;
    measure();
    // Images and fonts change the content width after the first paint, so measure again when they settle.
    window.addEventListener('load', measure);
    el.addEventListener('load', measure, true);
    const observer =
      typeof ResizeObserver === 'undefined'
        ? null
        : new ResizeObserver(measure);
    observer?.observe(el);
    el.querySelectorAll('.sf-item').forEach((item) => observer?.observe(item));
    return () => {
      window.removeEventListener('load', measure);
      el.removeEventListener('load', measure, true);
      observer?.disconnect();
    };
  }, [carousel, items.length]);
  /** The width of the real items: where the copies begin. */
  const loopWidth = () => {
    const el = track.current;
    const first = el?.querySelector<HTMLElement>('.sf-item');
    const copy = el?.querySelector<HTMLElement>('.sf-item[data-clone]');
    return first && copy ? copy.offsetLeft - first.offsetLeft : 0;
  };
  /** Once scrolling has settled inside the copies, return to the same spot among the real items. */
  const wrap = () => {
    const el = track.current;
    if (!looping || !el || drag.current) return;
    const width = loopWidth();
    if (width > 0 && el.scrollLeft >= width - 1)
      el.scrollTo({ left: el.scrollLeft - width, behavior: 'instant' });
  };
  useEffect(() => () => window.clearTimeout(settle.current), []);
  const go = (direction: 1 | -1) => {
    const el = track.current;
    if (!el) return;
    if (looping) {
      // Going back from the very start first steps (unseen) onto the same spot in the copies.
      if (direction === -1 && el.scrollLeft <= 4)
        el.scrollTo({ left: loopWidth(), behavior: 'instant' });
      el.scrollBy({ left: direction * step(), behavior: 'smooth' });
      return;
    }
    const atEnd = el.scrollLeft + el.clientWidth >= el.scrollWidth - 4;
    if (direction === 1 && atEnd) el.scrollTo({ left: 0, behavior: 'smooth' });
    else if (direction === -1 && el.scrollLeft <= 4 && display.infinite_scroll)
      el.scrollTo({ left: el.scrollWidth, behavior: 'smooth' });
    else el.scrollBy({ left: direction * step(), behavior: 'smooth' });
  };
  useEffect(() => {
    if (!carousel || seconds <= 0 || paused || !edges.overflow) return;
    if (window.matchMedia(REDUCED).matches) return;
    const timer = window.setInterval(() => go(1), seconds * 1000);
    return () => window.clearInterval(timer);
  }, [carousel, seconds, paused, edges.overflow, looping]);
  const rows =
    Number(display.rows ?? 1) === 2 &&
    (kind === 'category' || kind === 'brand');
  const style = {
    '--sf-w': `${display.image_width ?? 240}px`,
    '--sf-h': `${display.image_height ?? 240}px`,
    '--sf-radius': `${display.image_radius ?? 16}px`,
    '--sf-n': display.per_row ?? 5,
    // Banners visible at once (never more than there are); phones always show one.
    '--sf-vis-d': Math.min(
      Math.max(1, display.visible_desktop ?? 1),
      Math.max(1, items.length),
    ),
    '--sf-vis-t': Math.min(
      Math.max(1, display.visible_tablet ?? 1),
      Math.max(1, items.length),
    ),
  } as CSSProperties;
  return (
    <div
      style={style}
      className={`sf-carousel-wrap ${carousel && display.hide_arrows_mobile ? 'sf-hide-arrows-mobile' : ''} sf-nav-${carousel ? navigation : 'none'} ${kind === 'menu' ? `sf-menu-carousel ${menuCards ? 'sf-menu-cards' : block.template === 'menu_tiles_carousel' ? 'sf-menu-tiles' : 'sf-menu-circles'}` : ''} ${kind === 'banner' && display.full_width ? 'sf-banners-full' : ''} ${kind === 'banner' && display.fill_height ? 'sf-banners-fill' : ''} ${kind === 'banner' && (display.full_width || display.remove_border_radius) ? 'sf-banners-square' : ''}`}
      onPointerEnter={() => setPaused(true)}
      onPointerLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
    >
      <div
        ref={track}
        className={`sf-items sf-${menuCards ? 'category' : kind}-items ${kind === 'brand' ? 'sf-category-items' : ''} ${carousel ? 'sf-carousel' : 'sf-single'} ${rows ? 'sf-rows-2' : ''} ${display.image_width || kind === 'reel' || kind === 'blog' || kind === 'deal' || menuCards ? 'sf-sized' : ''} ${carousel ? (display.drag_scroll ? 'sf-drag' : 'sf-locked') : ''} ${dragging ? 'is-dragging' : ''}`}
        tabIndex={carousel ? 0 : undefined}
        role={carousel ? 'region' : undefined}
        aria-label={
          carousel
            ? block.title ||
              t(kind === 'menu' ? 'storefront.menu' : 'storefront.home')
            : undefined
        }
        onPointerDown={(e) => {
          const el = track.current;
          if (
            !display.drag_scroll ||
            !el ||
            e.pointerType !== 'mouse' ||
            e.button !== 0
          )
            return;
          drag.current = { x: e.clientX, left: el.scrollLeft, moved: false };
        }}
        onPointerMove={(e) => {
          const el = track.current;
          const d = drag.current;
          if (!d || !el) return;
          const dx = e.clientX - d.x;
          if (!d.moved && Math.abs(dx) > 5) {
            d.moved = true;
            setDragging(true);
          }
          if (d.moved) el.scrollLeft = d.left - dx;
        }}
        onPointerUp={() => {
          justDragged.current = !!drag.current?.moved;
          drag.current = null;
          setDragging(false);
        }}
        onPointerLeave={() => {
          drag.current = null;
          setDragging(false);
        }}
        onClickCapture={(e) => {
          // A drag must not open the link under the pointer.
          if (justDragged.current) {
            e.preventDefault();
            e.stopPropagation();
            justDragged.current = false;
          }
        }}
        onDragStart={(e) => e.preventDefault()}
        onScroll={(e) => {
          measure();
          const width = step();
          if (looping) {
            window.clearTimeout(settle.current);
            settle.current = window.setTimeout(wrap, 140);
          }
          if (dots && width > 0)
            setActive(
              Math.round(e.currentTarget.scrollLeft / width) %
                Math.max(1, items.length),
            );
        }}
      >
        {items.map((item, index) => (
          <div className="sf-item" key={item.id}>
            {renderItem ? (
              renderItem(item, index)
            ) : kind === 'deal' ? (
              <DealCard item={item} shadow={display.card_shadow} />
            ) : kind === 'product' ? (
              <Product item={item} display={display} format={format} />
            ) : kind === 'banner' ? (
              <StorefrontBanner
                heights={{
                  pc: display.height_desktop,
                  tablet: display.height_tablet,
                }}
                banner={item.banner}
                fill={display.fill_height}
                eager={
                  display.preload === 'all' ||
                  (display.preload === 'first' && item.id === items[0]?.id)
                }
              />
            ) : kind === 'menu' && !menuCards ? (
              <MenuTile item={item} />
            ) : kind === 'blog' ? (
              <BlogCard item={item} display={display} featured={!carousel} />
            ) : (
              <article
                className={`sf-category ${kind === 'brand' ? 'sf-brand' : ''} ${menuCards || (kind === 'category' && display.card_shadow) ? 'sf-card-shadow' : ''}`}
              >
                <CategoryTile
                  item={item}
                  nearby={carousel && index <= Number(display.per_row ?? 5)}
                />
              </article>
            )}
          </div>
        ))}
        {looping &&
          items.slice(0, Math.min(items.length, 8)).map((item, index) => (
            <div
              className="sf-item"
              key={'clone-' + item.id}
              data-clone=""
              aria-hidden="true"
              inert
            >
              {renderItem ? (
                renderItem(item, index)
              ) : kind === 'deal' ? (
                <DealCard item={item} shadow={display.card_shadow} />
              ) : kind === 'product' ? (
                <Product item={item} display={display} format={format} />
              ) : kind === 'banner' ? (
                <StorefrontBanner
                  heights={{
                    pc: display.height_desktop,
                    tablet: display.height_tablet,
                  }}
                  banner={item.banner}
                />
              ) : kind === 'menu' && !menuCards ? (
                <MenuTile item={item} />
              ) : kind === 'blog' ? (
                <BlogCard item={item} display={display} />
              ) : (
                <article
                  className={`sf-category ${kind === 'brand' ? 'sf-brand' : ''} ${menuCards || (kind === 'category' && display.card_shadow) ? 'sf-card-shadow' : ''}`}
                >
                  <CategoryTile item={item} />
                </article>
              )}
            </div>
          ))}
      </div>
      {carousel && navigation !== 'none' && !dots && edges.overflow && (
        <>
          <button
            type="button"
            className="sf-arrow sf-arrow-prev"
            aria-label={t('storefront.previous')}
            disabled={edges.start && !display.infinite_scroll}
            onClick={() => go(-1)}
          >
            <Chevron direction="prev" />
          </button>
          <button
            type="button"
            className="sf-arrow sf-arrow-next"
            aria-label={t('storefront.next')}
            disabled={edges.end && !display.infinite_scroll}
            onClick={() => go(1)}
          >
            <Chevron direction="next" />
          </button>
        </>
      )}
      {carousel && navigation !== 'none' && dots && edges.overflow && (
        <div className="sf-dots">
          {Array.from({ length: edges.pages }, (_, index) => (
            <button
              type="button"
              key={index}
              aria-label={`${t('storefront.go_to_slide')} ${index + 1}`}
              aria-current={index === active}
              className={index === active ? 'is-active' : ''}
              onClick={() =>
                track.current?.scrollTo({
                  left: index * step(),
                  behavior: 'smooth',
                })
              }
            />
          ))}
        </div>
      )}
    </div>
  );
}
/** Per-block presentation: background and title size/color arrive as CSS variables. */
export function blockStyle(block: PublicBlock): CSSProperties | undefined {
  const d = block.content.display;
  if (!d) return undefined;
  const style: Record<string, string> = {};
  if (d.background_color) {
    style.background = d.background_color;
    style['--sf-block-bg'] = d.background_color;
  }
  if (d.title_size_desktop) style['--sf-title-d'] = `${d.title_size_desktop}px`;
  if (d.title_size_tablet) style['--sf-title-t'] = `${d.title_size_tablet}px`;
  if (d.title_size_mobile) style['--sf-title-m'] = `${d.title_size_mobile}px`;
  if (d.title_color) style['--sf-title-color'] = d.title_color;
  if (d.text_color) style.color = d.text_color;
  if (d.icon_color) style['--sf-icon-color'] = d.icon_color;
  if (d.padding_top !== undefined) style.paddingTop = `${d.padding_top}px`;
  if (d.padding_bottom !== undefined)
    style.paddingBottom = `${d.padding_bottom}px`;
  if (d.heading_size) style['--sf-footer-h'] = `${d.heading_size}px`;
  if (d.text_size) style['--sf-footer-t'] = `${d.text_size}px`;
  if (d.font_size_desktop) style['--sf-tb-d'] = `${d.font_size_desktop}px`;
  if (d.font_size_tablet) style['--sf-tb-t'] = `${d.font_size_tablet}px`;
  if (d.font_size_mobile) style['--sf-tb-m'] = `${d.font_size_mobile}px`;
  return Object.keys(style).length > 0 ? (style as CSSProperties) : undefined;
}
