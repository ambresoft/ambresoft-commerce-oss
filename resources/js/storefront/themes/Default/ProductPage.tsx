import { router, useForm, usePage } from '@inertiajs/react';
import {
  Fragment,
  useEffect,
  useId,
  useRef,
  useState,
  type ReactNode,
  type FormEvent,
} from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type {
  CurrencyFormat,
  ProductPageData,
  PublicBlock,
  ReviewPage,
} from '../../types';
import {
  blockStyle,
  CartIcon,
  Chevron,
  Collection,
  FaqList,
  Money,
  NetPrice,
  NoImage,
  Picture,
  QuantityStepper,
  Stars,
  Stickers,
} from './parts';
import { useCart } from './cartContext';
import AgeGate from './AgeGate';
import Dialog from './Dialog';
import Gallery from './Gallery';
import PriceHistory from './PriceHistory';
import DocumentPreview from './DocumentPreview';

function ChartIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="18"
      height="18"
      fill="currentColor"
    >
      <rect x="4" y="12" width="4" height="8" rx="1" />
      <rect x="10" y="6" width="4" height="14" rx="1" />
      <rect x="16" y="9" width="4" height="11" rx="1" />
    </svg>
  );
}
function ReturnIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="18"
      height="18"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M9 14 4 9l5-5" />
      <path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11" />
    </svg>
  );
}

/** Whole days, hours, minutes and seconds left until a moment (zeros once it has passed). */
function remaining(until: number, now: number) {
  const left = Math.max(0, Math.floor((until - now) / 1000));
  return {
    days: Math.floor(left / 86400),
    hours: Math.floor((left % 86400) / 3600),
    minutes: Math.floor((left % 3600) / 60),
    seconds: left % 60,
  };
}

/** The sale notice above the price: a promotion banner with a live countdown when the sale has an end. */
function SaleBanner({ sale }: { sale: NonNullable<ProductPageData['sale']> }) {
  const t = useTranslation();
  const until = sale.ends_at ? new Date(sale.ends_at).getTime() : null;
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (until === null) return;
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [until]);
  const left = until === null ? null : remaining(until, now);
  return (
    <div className={`sf-pdp-sale${left ? '' : ' sf-pdp-sale-compact'}`}>
      <p className="sf-pdp-sale-title">
        <svg
          width="26"
          height="26"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.8"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
        >
          <path d="M3 9a2 2 0 0 0 0 6v3h18v-3a2 2 0 0 1 0-6V6H3z" />
          <path d="m12 9.5.9 1.8 2 .3-1.4 1.4.3 2-1.8-.9-1.8.9.3-2-1.4-1.4 2-.3z" />
        </svg>
        {sale.title ?? t('storefront.on_sale')}
      </p>
      {left && (
        <div className="sf-pdp-countdown" role="timer">
          <span>{t('storefront.sale_expires_within')}</span>
          {(['days', 'hours', 'minutes', 'seconds'] as const).map((unit) => (
            <span key={unit} className="sf-pdp-countdown-unit">
              <strong>{left[unit]}</strong>
              {t(`storefront.${unit}`)}
            </span>
          ))}
        </div>
      )}
    </div>
  );
}

/** Minus / plus around the amount to buy, honouring the product's minimum, maximum and step. */
function Price({
  product,
  format,
  quantity,
  setQuantity,
  buyable,
}: {
  product: ProductPageData;
  format: CurrencyFormat;
  quantity: number;
  setQuantity: (value: number) => void;
  /** Available and priced: the product can go in the cart. */
  buyable: boolean;
}) {
  const t = useTranslation();
  const cart = useCart();
  const [busy, setBusy] = useState(false);
  const addToCart = async () => {
    setBusy(true);
    await cart.add(product, quantity);
    setBusy(false);
  };
  return (
    <div className="sf-pdp-buy">
      {product.brand && <BrandLogo brand={product.brand} />}
      {product.sale && product.price != null && (
        <SaleBanner sale={product.sale} />
      )}
      <div className="sf-pdp-priceline">
        {product.price != null && (
          <div className="sf-pdp-price">
            {product.base_price && (
              <p className="sf-pdp-was">
                <del>
                  <Money amount={product.base_price} format={format} inline />
                </del>
                {product.saving && (
                  <span>
                    {t('storefront.you_save')}{' '}
                    <Money amount={product.saving} format={format} inline />
                  </span>
                )}
              </p>
            )}
            <p className="sf-pdp-now">
              <Money amount={product.price} format={format} />
            </p>
            <NetPrice item={product} format={format} />
          </div>
        )}
        {product.available && product.price != null && cart.enabled && (
          <QuantityStepper
            rules={product.quantity}
            value={quantity}
            onChange={setQuantity}
          />
        )}
      </div>
      <Stickers stickers={product.stickers ?? []} placement="above_price" />
      <ul className="sf-pdp-facts">
        <li className={product.available ? 'is-in' : 'is-out'}>
          <span className="sf-pdp-dot" aria-hidden="true" />
          {product.available
            ? product.availability.label
            : t('storefront.unavailable')}
        </li>
        {product.free_shipping && <li>{t('storefront.free_shipping')}</li>}
        <li className={product.returnable ? 'is-returnable' : 'is-final'}>
          <ReturnIcon />
          {t(
            product.returnable
              ? 'storefront.returnable'
              : 'storefront.not_returnable',
          )}
        </li>
        {product.available_from && (
          <li>
            {t('storefront.available_from', {
              date: new Date(product.available_from).toLocaleDateString(),
            })}
          </li>
        )}
      </ul>
      {cart.enabled && product.price != null && (
        <button
          type="button"
          className="sf-pdp-add"
          disabled={!buyable || busy}
          onClick={addToCart}
        >
          <CartIcon />
          {t(
            product.available
              ? 'storefront.add_to_cart'
              : 'storefront.unavailable',
          )}
        </button>
      )}
      <Stickers stickers={product.stickers ?? []} placement="below_price" />
    </div>
  );
}

/** Key features as equal cards; when they do not all fit, they scroll with arrow buttons. */
function Highlights({
  product,
  linked,
}: {
  product: ProductPageData;
  linked: boolean;
}) {
  const t = useTranslation();
  const track = useRef<HTMLUListElement>(null);
  const [edges, setEdges] = useState({ start: true, end: true });
  const measure = () => {
    const el = track.current;
    if (!el) return;
    setEdges({
      start: el.scrollLeft <= 4,
      end: el.scrollLeft + el.clientWidth >= el.scrollWidth - 4,
    });
  };
  useEffect(() => {
    measure();
    window.addEventListener('resize', measure);
    return () => window.removeEventListener('resize', measure);
  }, []);
  if (product.highlights.length === 0) return null;
  const move = (direction: 1 | -1) =>
    track.current?.scrollBy({
      left: direction * track.current.clientWidth * 0.8,
      behavior: 'smooth',
    });
  return (
    <div className="sf-pdp-highlights">
      <h2>{t('storefront.highlights')}</h2>
      <div className="sf-pdp-highlights-wrap">
        <ul ref={track} onScroll={measure}>
          {product.highlights.map((f) => (
            <li key={f.id}>
              {linked ? (
                <a href="#features">
                  <span>{f.name}</span>
                  <strong>{f.value}</strong>
                </a>
              ) : (
                <div>
                  <span>{f.name}</span>
                  <strong>{f.value}</strong>
                </div>
              )}
            </li>
          ))}
        </ul>
        {!edges.start && (
          <button
            type="button"
            className="sf-pdp-scroll is-prev"
            aria-label={t('storefront.gallery_prev')}
            onClick={() => move(-1)}
          >
            <Chevron direction="prev" />
          </button>
        )}
        {!edges.end && (
          <button
            type="button"
            className="sf-pdp-scroll is-next"
            aria-label={t('storefront.gallery_next')}
            onClick={() => move(1)}
          >
            <Chevron direction="next" />
          </button>
        )}
      </div>
    </div>
  );
}

function Variations({
  product,
  alternativesPerRow,
}: {
  product: ProductPageData;
  alternativesPerRow?: number;
}) {
  const t = useTranslation();
  const [alternativesOpen, setAlternativesOpen] = useState(true);
  const alternativesId = useId();
  if (product.variations.length === 0) return null;
  return (
    <div className="sf-pdp-variations">
      {product.variations.map((v) => {
        const current = v.options.find((o) => o.selected);
        return (
          <div key={v.id} className="sf-pdp-variation">
            <p className="sf-pdp-label">
              {v.name}: <strong>{current?.label}</strong>
            </p>
            {v.style === 'dropdown' ? (
              <select
                aria-label={v.name}
                value={current?.url ?? ''}
                onChange={(e) => {
                  if (
                    v.options.some(
                      (o) => o.url === e.target.value && o.compatible !== false,
                    )
                  )
                    router.visit(e.target.value);
                }}
              >
                {v.options.map((o) => (
                  <option
                    key={o.id}
                    value={o.url ?? o.id}
                    disabled={o.compatible === false || !o.url}
                  >
                    {o.label}
                    {o.compatible === false
                      ? ` — ${t('storefront.variation_unavailable')}`
                      : ''}
                  </option>
                ))}
              </select>
            ) : (
              <div
                className={`sf-pdp-options ${v.style === 'text' ? 'is-text' : 'is-tiles'}`}
              >
                {v.options.map((o) => (
                  <a
                    key={o.id}
                    href={
                      o.compatible === false ? undefined : (o.url ?? undefined)
                    }
                    role="link"
                    aria-disabled={o.compatible === false ? true : undefined}
                    title={
                      o.compatible === false
                        ? t('storefront.variation_incompatible')
                        : undefined
                    }
                    aria-current={o.selected ? 'true' : undefined}
                    aria-label={o.label}
                    className={`sf-pdp-option ${o.selected ? 'is-selected' : ''} ${o.available ? '' : 'is-out'} ${o.compatible === false ? 'is-disabled' : ''}`}
                  >
                    {v.style === 'text' ? (
                      <span>{o.label}</span>
                    ) : (
                      <>
                        <span className="sf-pdp-option-media">
                          {o.image ? (
                            <img src={o.image.url} alt="" loading="lazy" />
                          ) : (
                            <span
                              className="sf-pdp-swatch"
                              style={{ background: o.swatch ?? '#e5e5e5' }}
                            />
                          )}
                        </span>
                        <span className="sf-pdp-option-text">{o.label}</span>
                      </>
                    )}
                  </a>
                ))}
              </div>
            )}
            {v.options.some((o) => o.compatible === false) && (
              <p className="sf-pdp-variation-hint">
                {t('storefront.variation_incompatible')}
              </p>
            )}
          </div>
        );
      })}
      {!!product.variation_alternatives?.length && (
        <div className="sf-pdp-variation-alternatives sf-compact-product-cards">
          <h2 className="sf-pdp-label">
            <button
              id={`${alternativesId}-toggle`}
              type="button"
              className="sf-pdp-alternatives-toggle"
              aria-expanded={alternativesOpen}
              aria-controls={alternativesId}
              onClick={() => setAlternativesOpen((open) => !open)}
            >
              {t('storefront.variation_other_combinations')}
              <Chevron direction="next" />
            </button>
          </h2>
          <div
            id={alternativesId}
            role="region"
            aria-labelledby={`${alternativesId}-toggle`}
            aria-hidden={!alternativesOpen}
            inert={!alternativesOpen}
            className={`sf-pdp-alternatives-panel ${alternativesOpen ? 'is-open' : ''}`}
          >
            <div className="sf-pdp-alternatives-content">
              <p className="sf-pdp-variation-hint">
                {t('storefront.variation_other_combinations_help')}
              </p>
              <Collection
                block={{
                  id: `variation-alternatives-${product.id}`,
                  template: 'product_carousel',
                  title: '',
                  css_class: '',
                  mobile: true,
                  tablet: true,
                  desktop: true,
                  content: {
                    items: product.variation_alternatives,
                    currency_format: product.currency_format,
                    display: {
                      image_width: 300,
                      image_height: 80,
                      per_row: alternativesPerRow,
                      show_add_to_cart: false,
                      show_favorites: false,
                      drag_scroll: true,
                      hide_arrows_mobile: true,
                      navigation: 'inside',
                    },
                  },
                }}
              />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/** Decimal amounts added as whole minor units (cents), never as floating point. */
function sumAmounts(amounts: string[], decimals: number): string {
  const minor = (amount: string) => {
    const [whole, fraction = ''] = amount.split('.');
    return BigInt(whole + fraction.padEnd(decimals, '0').slice(0, decimals));
  };
  const total = amounts.reduce((sum, amount) => sum + minor(amount), 0n);
  const digits = total.toString().padStart(decimals + 1, '0');
  return decimals === 0
    ? digits
    : `${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}

/** Frequently bought together: the visitor can untick companions; the main product always stays in. */
function BoughtTogether({
  product,
  reusable = false,
}: {
  product: ProductPageData;
  reusable?: boolean;
}) {
  const t = useTranslation();
  const cart = useCart();
  const [busy, setBusy] = useState(false);
  const packageData = reusable ? product.package : null;
  const bundle = reusable
    ? packageData && {
        ...packageData,
        items: packageData.items.map((item) => ({
          ...item,
          main: item.id === product.id,
          included: !!item.available && item.price != null,
        })),
      }
    : product.bought_together;
  const [chosen, setChosen] = useState<Set<string>>(
    () =>
      new Set((bundle?.items ?? []).filter((i) => i.included).map((i) => i.id)),
  );
  if (!bundle || product.price == null) return null;
  const format = bundle.currency_format;
  const selected = reusable
    ? bundle.items
    : bundle.items.filter((i) => i.included && (i.main || chosen.has(i.id)));
  const total = reusable
    ? bundle.total
    : bundle.total === null
      ? null
      : sumAmounts(
          selected.flatMap((i) =>
            i.price == null ? [] : Array(i.quantity).fill(i.price),
          ),
          format.decimals,
        );
  const toggle = (id: string) => {
    const next = new Set(chosen);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setChosen(next);
  };
  const main = bundle.items.find((i) => i.main);
  const extras = bundle.items.filter((i) => !i.main);
  const check = (item: (typeof bundle.items)[number]) => (
    <label className="sf-pdp-check">
      <input
        type="checkbox"
        checked={item.included && (item.main || chosen.has(item.id))}
        disabled={reusable || item.main || !item.included}
        onChange={() => toggle(item.id)}
      />
      <span className="sf-visually-hidden">{item.name}</span>
    </label>
  );
  const media = (item: (typeof bundle.items)[number]) =>
    item.image ? <Picture image={item.image} alt="" /> : <NoImage />;
  return (
    <section
      id={reusable ? 'product-package' : 'bundle'}
      className="sf-pdp-section"
      aria-labelledby={reusable ? 'sf-pdp-package' : 'sf-pdp-bundle'}
    >
      <div className="sf-bundle">
        <div className="sf-bundle-head">
          <h2 id={reusable ? 'sf-pdp-package' : 'sf-pdp-bundle'}>
            {packageData?.name ??
              t('storefront.bundle_title', { count: bundle.items.length })}
          </h2>
          <p>
            {t(reusable ? 'packages.all_included' : 'storefront.bundle_choose')}
          </p>
        </div>
        <div className="sf-bundle-body">
          {main && (
            <div className="sf-bundle-main">
              <h3>{t('storefront.bundle_main')}</h3>
              <div className="sf-bundle-main-card">
                {check(main)}
                <div className="sf-bundle-main-media">{media(main)}</div>
                <p className="sf-bundle-name">
                  {main.name}
                  {reusable && main.quantity > 1 && ` × ${main.quantity}`}
                </p>
                {main.price != null && (
                  <p className="sf-bundle-price">
                    <Money amount={main.price} format={format} />
                  </p>
                )}
              </div>
            </div>
          )}
          <div className="sf-bundle-extras">
            <h3>{t('storefront.bundle_accessories')}</h3>
            <ul>
              {extras.map((item) => {
                const on = item.included && (reusable || chosen.has(item.id));
                return (
                  <li
                    key={item.id}
                    className={`${on ? 'is-included' : 'is-excluded'} ${item.included ? '' : 'is-unavailable'}`}
                  >
                    {check(item)}
                    <a
                      href={item.url ?? undefined}
                      className="sf-bundle-thumb"
                      tabIndex={-1}
                      aria-hidden="true"
                    >
                      {media(item)}
                    </a>
                    <span className="sf-bundle-extra-text">
                      <a
                        href={item.url ?? undefined}
                        className="sf-pdp-bundle-name"
                      >
                        {item.name}
                        {reusable && item.quantity > 1 && ` × ${item.quantity}`}
                      </a>
                      {!item.included && (
                        <span className="sf-pdp-bundle-note">
                          {t('storefront.unavailable')}
                        </span>
                      )}
                    </span>
                    {item.price != null && (
                      <span className="sf-bundle-price">
                        <Money amount={item.price} format={format} />
                      </span>
                    )}
                  </li>
                );
              })}
            </ul>
          </div>
          {(reusable || total !== null) && (
            <div className="sf-bundle-summary">
              <p className="sf-bundle-summary-label">
                {t('storefront.bundle_yours')}
              </p>
              <ul>
                {selected.map((item) => (
                  <li key={item.id}>
                    <span>
                      {item.name}
                      {reusable && item.quantity > 1 && ` × ${item.quantity}`}
                    </span>
                    {item.price != null && (
                      <Money
                        amount={
                          reusable
                            ? sumAmounts(
                                Array(item.quantity).fill(item.price),
                                format.decimals,
                              )
                            : item.price
                        }
                        format={format}
                      />
                    )}
                  </li>
                ))}
              </ul>
              {total !== null && (
                <div className="sf-bundle-total">
                  <span>{t('storefront.bundle_total_label')}</span>
                  <p>
                    <Money amount={total} format={format} />
                  </p>
                </div>
              )}
              {reusable && !packageData?.available && (
                <p role="status">{t('storefront.cart_unavailable')}</p>
              )}
              {cart.enabled && (
                <button
                  type="button"
                  className="sf-pdp-bundle-add"
                  disabled={busy || (reusable && !packageData?.available)}
                  onClick={async () => {
                    setBusy(true);
                    try {
                      if (reusable) {
                        await cart.add({ id: product.id, addPackage: true });
                      } else {
                        // Each chosen product at its smallest order quantity; the last notice tells the outcome.
                        for (const item of selected)
                          await cart.add(item, item.quantity);
                      }
                    } finally {
                      setBusy(false);
                    }
                  }}
                >
                  <CartIcon />
                  {reusable
                    ? t('packages.add_all')
                    : t('storefront.bundle_add', { count: selected.length })}
                </button>
              )}
            </div>
          )}
        </div>
      </div>
    </section>
  );
}

function Reviews({ product }: { product: ProductPageData }) {
  const t = useTranslation();
  const { reviews } = usePage<{ reviews?: ReviewPage }>().props;
  const [writing, setWriting] = useState(false);
  const [sent, setSent] = useState(false);
  const mine = sent ? 'pending' : (product.review.mine ?? null);
  const form = useForm({
    rating: 0,
    title: '',
    body: '',
    return_to: product.url,
  });
  const rating = product.rating;
  const average = rating.average === null ? 0 : Number(rating.average);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post(product.review.url, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        setSent(true);
        setWriting(false);
        form.reset();
      },
    });
  };
  return (
    <section
      id="reviews"
      className="sf-pdp-section"
      aria-labelledby="sf-pdp-reviews"
    >
      <h2 id="sf-pdp-reviews">{t('storefront.reviews')}</h2>
      <div className="sf-pdp-rating">
        <div className="sf-pdp-rating-score">
          <strong>
            {rating.average === null
              ? '–'
              : rating.average.replace('.', product.currency_format.decimal)}
          </strong>
          <Stars value={average} size={22} />
          <span>{t('storefront.rating_count', { count: rating.count })}</span>
          {mine ? (
            <p className="sf-pdp-mine" role="status">
              {t(`storefront.review_mine_${mine}`)}
            </p>
          ) : product.review.signed_in ? (
            <button
              type="button"
              className="sf-pdp-outline"
              onClick={() => setWriting(!writing)}
            >
              {t('storefront.rate_product')}
            </button>
          ) : (
            <a className="sf-pdp-outline" href="/login">
              {t('storefront.rate_product')}
            </a>
          )}
        </div>
        <ul className="sf-pdp-bars">
          {(['5', '4', '3', '2', '1'] as const).map((n) => (
            <li key={n}>
              <span>
                {t(n === '1' ? 'storefront.star_one' : 'storefront.stars', {
                  count: n,
                })}
              </span>
              <span className="sf-pdp-bar" aria-hidden="true">
                <span
                  style={{
                    width: `${rating.count === 0 ? 0 : (rating.distribution[n] / rating.count) * 100}%`,
                  }}
                />
              </span>
              <span>{rating.distribution[n]}</span>
            </li>
          ))}
        </ul>
      </div>
      {writing && (
        <Dialog
          title={t('storefront.rate_product')}
          onClose={() => setWriting(false)}
        >
          <form className="sf-pdp-review-form" onSubmit={submit}>
            {/* Errors that belong to no field (already reviewed, product or account unavailable). */}
            {Object.entries(form.errors)
              .filter(([key]) => !['rating', 'title', 'body'].includes(key))
              .map(([key, message]) => (
                <p key={key} role="alert" className="sf-pdp-form-error">
                  {message}
                </p>
              ))}
            <fieldset>
              <legend>{t('storefront.review_rating')}</legend>
              <div className="sf-pdp-rate">
                {[1, 2, 3, 4, 5].map((n) => (
                  <label key={n}>
                    <input
                      type="radio"
                      name="rating"
                      value={n}
                      checked={form.data.rating === n}
                      onChange={() => form.setData('rating', n)}
                    />
                    <span aria-hidden="true">
                      {n <= form.data.rating ? '★' : '☆'}
                    </span>
                    <span className="sf-visually-hidden">
                      {t(n === 1 ? 'storefront.star_one' : 'storefront.stars', {
                        count: n,
                      })}
                    </span>
                  </label>
                ))}
              </div>
              {form.errors.rating && <p role="alert">{form.errors.rating}</p>}
            </fieldset>
            <label>
              {t('storefront.review_title')}
              <input
                value={form.data.title}
                maxLength={120}
                onChange={(e) => form.setData('title', e.target.value)}
              />
            </label>
            {form.errors.title && <p role="alert">{form.errors.title}</p>}
            <label>
              {t('storefront.review_body')}
              <textarea
                value={form.data.body}
                rows={5}
                required
                onChange={(e) => form.setData('body', e.target.value)}
              />
            </label>
            {form.errors.body && <p role="alert">{form.errors.body}</p>}
            <button
              type="submit"
              className="sf-pdp-add"
              disabled={form.processing || form.data.rating === 0}
            >
              {t('storefront.review_submit')}
            </button>
          </form>
        </Dialog>
      )}
      {reviews && reviews.total > 0 ? (
        <>
          <ul className="sf-pdp-review-list">
            {reviews.data.map((r) => (
              <li key={r.id}>
                <Stars value={r.rating} size={16} />
                {r.title && <h3>{r.title}</h3>}
                <p>{r.body}</p>
                <p className="sf-pdp-review-meta">
                  {r.author} · {new Date(r.created_at).toLocaleDateString()}
                  {r.verified_purchase && (
                    <span className="sf-pdp-verified">
                      {t('storefront.verified_purchase')}
                    </span>
                  )}
                </p>
              </li>
            ))}
          </ul>
          {reviews.current_page < reviews.last_page && (
            <button
              type="button"
              className="sf-pdp-outline"
              onClick={() =>
                router.reload({
                  only: ['reviews'],
                  data: { reviews_page: reviews.current_page + 1 },
                })
              }
            >
              {t('storefront.more_reviews')}
            </button>
          )}
        </>
      ) : (
        <p className="sf-pdp-empty">{t('storefront.no_reviews')}</p>
      )}
    </section>
  );
}

/** A bar that follows the visitor once the buying area has scrolled away: sections, price and the cart button. */
function StickyBar({
  product,
  format,
  visible,
  reviews,
  faqs,
  quantity,
  buyable,
}: {
  product: ProductPageData;
  format: CurrencyFormat;
  visible: boolean;
  reviews: boolean;
  faqs: boolean;
  /** The quantity chosen in the buy box. */
  quantity: number;
  buyable: boolean;
}) {
  const t = useTranslation();
  const cart = useCart();
  const [busy, setBusy] = useState(false);
  const links = [
    product.description && ['description', 'storefront.description'],
    product.features.length > 0 && ['features', 'storefront.specifications'],
    reviews && ['reviews', 'storefront.reviews'],
    product.similar && ['similar', 'storefront.similar'],
    faqs && ['faqs', 'storefront.faqs'],
  ].filter(Boolean) as [string, string][];
  // The section on screen (the last one whose top has passed the bar) is highlighted.
  const [current, setCurrent] = useState<string | null>(null);
  const ids = links.map(([id]) => id).join(',');
  useEffect(() => {
    const sections = ids
      .split(',')
      .map((id) => document.getElementById(id))
      .filter((el): el is HTMLElement => el !== null);
    if (sections.length === 0) return;
    // The section whose top has most recently passed under the bar, in page order (not the bar's order).
    const update = () => {
      let found: string | null = null;
      let nearest = -Infinity;
      for (const el of sections) {
        const top = el.getBoundingClientRect().top;
        if (top <= 140 && top > nearest) {
          nearest = top;
          found = el.id;
        }
      }
      setCurrent(found);
    };
    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    return () => {
      window.removeEventListener('scroll', update);
      window.removeEventListener('resize', update);
    };
  }, [ids]);
  return (
    <div
      className={`sf-pdp-sticky ${visible ? 'is-visible' : ''}`}
      aria-hidden={!visible}
    >
      <div className="sf-pdp-sticky-inner">
        <span className="sf-pdp-sticky-product">
          {product.images[0] && <img src={product.images[0].url} alt="" />}
          <span>{product.name}</span>
        </span>
        <nav aria-label={product.name}>
          {links.map(([id, label]) => (
            <a
              key={id}
              href={`#${id}`}
              tabIndex={visible ? 0 : -1}
              className={current === id ? 'is-current' : undefined}
              aria-current={current === id ? 'location' : undefined}
            >
              {t(label)}
            </a>
          ))}
        </nav>
        {product.price != null && (
          <strong className="sf-pdp-sticky-price">
            <Money amount={product.price} format={format} />
          </strong>
        )}
        {cart.enabled && product.price != null && (
          <button
            type="button"
            className="sf-pdp-add"
            disabled={!buyable || busy}
            tabIndex={visible ? 0 : -1}
            onClick={async () => {
              setBusy(true);
              await cart.add(product, quantity);
              setBusy(false);
            }}
          >
            <CartIcon />
            <span>{t('storefront.add')}</span>
          </button>
        )}
      </div>
    </div>
  );
}

/** Product recommendations use the existing product carousel block presentation. */
function RecommendationCarousel({
  block,
  similar,
  kind = 'similar',
}: {
  block: PublicBlock;
  similar: NonNullable<ProductPageData['similar']>;
  kind?: 'similar' | 'newest' | 'recently_viewed';
}) {
  const t = useTranslation();
  const carousel: PublicBlock = {
    ...block,
    id: `${block.id}-${kind}`,
    template: 'product_carousel',
    title: t(`storefront.${kind}`),
    css_class: '',
    content: {
      items:
        kind === 'recently_viewed'
          ? similar.items.map(
              ({ id, name, url, image, price, price_from }) => ({
                id,
                name,
                url,
                image,
                price,
                price_from,
              }),
            )
          : similar.items,
      currency_format: similar.currency_format,
      display: {
        ...similar.display,
        per_row:
          block.content.carousel_per_row?.[kind] ?? similar.display.per_row,
        ...(kind === 'recently_viewed'
          ? {
              image_width: 300,
              image_height: 80,
              show_add_to_cart: false,
              show_favorites: false,
              show_availability: false,
            }
          : {}),
        hide_arrows_mobile: block.content.display?.hide_arrows_mobile ?? false,
      },
    },
  };
  return (
    <section
      id={kind}
      className={`sf-pdp-section sf-pdp-similar sf-block${kind === 'recently_viewed' ? ' sf-compact-product-cards' : ''}`}
      style={blockStyle(carousel)}
      aria-labelledby={`sf-pdp-${kind}`}
    >
      <div className="sf-block-head">
        <h2 id={`sf-pdp-${kind}`}>{carousel.title}</h2>
      </div>
      <Collection block={carousel} />
    </section>
  );
}

/** The product page's main content block: the product, its recommendations, description, features and reviews. */
/** The brand's logo; it leads to the brand's page when the brand has one. */
function BrandLogo({
  brand,
}: {
  brand: NonNullable<ProductPageData['brand']>;
}) {
  const image = brand.image ? (
    <img
      className="sf-pdp-brand"
      src={brand.image.url}
      width={brand.image.width}
      height={brand.image.height}
      alt={brand.image_alt}
    />
  ) : (
    <span
      className="sf-pdp-brand sf-pdp-brand-placeholder"
      role="img"
      aria-label={brand.name}
      title={brand.name}
    >
      <NoImage />
    </span>
  );
  return brand.url ? (
    <a className="sf-pdp-brand-link" href={brand.url} aria-label={brand.name}>
      {image}
    </a>
  ) : (
    image
  );
}

export default function ProductPage({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const product = block.content.product;
  const buy = useRef<HTMLDivElement>(null);
  const [sticky, setSticky] = useState(false);
  useEffect(() => {
    const target = buy.current;
    if (!target || typeof IntersectionObserver === 'undefined') return;
    const observer = new IntersectionObserver(([entry]) =>
      setSticky(!entry.isIntersecting && entry.boundingClientRect.top < 0),
    );
    observer.observe(target);
    return () => observer.disconnect();
  }, []);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [openDocument, setOpenDocument] = useState<
    ProductPageData['documents'][number] | null
  >(null);
  // One quantity for the buy box and the sticky bar.
  const [quantity, setQuantity] = useState(product?.quantity.min ?? 1);
  const gate = block.content.age_gate;
  if (gate) return <AgeGate age={gate.age} message={gate.message} />;
  if (!product) return null;
  const buyable = !!product.available && product.price != null;
  const format = product.currency_format;
  // The main content block's settings decide which sections the page shows (all by default).
  const sections = {
    show_bought_together: true,
    show_similar: true,
    show_newest: true,
    show_recently_viewed: true,
    show_description: true,
    show_features: true,
    show_reviews: true,
    show_faqs: true,
    show_documents: true,
    show_price_history: true,
    show_product_bar: true,
    ...block.content.sections,
  };
  // Reviews also stay hidden while they are off for the store or this product.
  const reviews = sections.show_reviews && product.reviews_enabled !== false;
  const faqs = sections.show_faqs ? product.faqs : [];
  const documents = sections.show_documents
    ? [...product.documents, ...(product.brand_documents ?? [])]
    : [];
  const history =
    sections.show_price_history && product.price_history.length > 0;
  const shown = {
    ...product,
    description: sections.show_description ? product.description : '',
    features: sections.show_features ? product.features : [],
    similar: sections.show_similar ? product.similar : null,
    bought_together: sections.show_bought_together
      ? product.bought_together
      : null,
  };
  const sectionContent: Record<string, ReactNode> = {
    show_similar: shown.similar && (
      <RecommendationCarousel block={block} similar={shown.similar} />
    ),
    show_newest: sections.show_newest &&
      product.newest &&
      product.newest.items.length > 0 && (
        <RecommendationCarousel
          block={block}
          similar={product.newest}
          kind="newest"
        />
      ),
    show_recently_viewed: sections.show_recently_viewed &&
      product.recently_viewed &&
      product.recently_viewed.items.length > 0 && (
        <RecommendationCarousel
          block={block}
          similar={product.recently_viewed}
          kind="recently_viewed"
        />
      ),
    show_description: shown.description && (
      <section
        id="description"
        className="sf-pdp-section"
        aria-labelledby="sf-pdp-description"
      >
        <h2 id="sf-pdp-description">{t('storefront.description')}</h2>
        <div
          className="sf-prose sf-pdp-description"
          dangerouslySetInnerHTML={{ __html: shown.description }}
        />
      </section>
    ),
    show_features: shown.features.length > 0 && (
      <section
        id="features"
        className="sf-pdp-section"
        aria-labelledby="sf-pdp-features"
      >
        <h2 id="sf-pdp-features">{t('storefront.specifications')}</h2>
        <div className="sf-pdp-specs">
          {shown.features.map((group, n) => (
            <div key={n}>
              {group.name && <h3>{group.name}</h3>}
              <dl>
                {group.features.map((f) => (
                  <div key={f.id}>
                    <dt>{f.name}</dt>
                    <dd>{f.value}</dd>
                  </div>
                ))}
              </dl>
            </div>
          ))}
        </div>
      </section>
    ),
    show_reviews: reviews && <Reviews product={product} />,
  };
  const sectionOrder =
    block.content.section_order ?? Object.keys(sectionContent);
  return (
    <div className="sf-pdp">
      {sections.show_product_bar && (
        <StickyBar
          product={shown}
          format={format}
          visible={sticky}
          reviews={reviews}
          faqs={faqs.length > 0}
          quantity={quantity}
          buyable={buyable}
        />
      )}
      <div className="sf-pdp-hero">
        <Gallery product={product} />
        <div className="sf-pdp-info">
          {product.category?.url && (
            <a className="sf-pdp-back" href={product.category.url}>
              <Chevron direction="prev" />
              {product.category.name}
            </a>
          )}
          <div className="sf-pdp-title">
            <h1>{product.name}</h1>
            {product.price == null && product.brand && (
              <BrandLogo brand={product.brand} />
            )}
          </div>
          <div className="sf-pdp-badges">
            {reviews && (
              <a href="#reviews" className="sf-pdp-pill">
                <Stars
                  value={
                    product.rating.average === null
                      ? 0
                      : Number(product.rating.average)
                  }
                />
                <span>{product.rating.count}</span>
              </a>
            )}
            {history && (
              <button
                type="button"
                className="sf-pdp-pill"
                onClick={() => setHistoryOpen(true)}
              >
                <ChartIcon />
                <span>{t('storefront.price_history')}</span>
              </button>
            )}
            {product.sku && (
              <span className="sf-pdp-sku">
                {t('storefront.sku')}: {product.sku}
              </span>
            )}
          </div>
          {product.short_description && (
            <div
              className="sf-pdp-short sf-prose"
              dangerouslySetInnerHTML={{ __html: product.short_description }}
            />
          )}
          {shown.description && (
            <a className="sf-pdp-more" href="#description">
              {t('storefront.see_description')}
            </a>
          )}
          <Highlights product={product} linked={sections.show_features} />
          <Variations
            product={product}
            alternativesPerRow={
              block.content.carousel_per_row?.variation_alternatives
            }
          />
          {product.price != null && (
            <div ref={buy}>
              <Price
                product={product}
                format={format}
                quantity={quantity}
                setQuantity={setQuantity}
                buyable={buyable}
              />
            </div>
          )}
          {documents.map((doc) => (
            <a
              key={doc.url}
              className="sf-pdp-card-link sf-pdp-document"
              href={doc.url}
              target="_blank"
              rel="noopener"
              aria-label={t('storefront.open_document', { name: doc.name })}
              aria-haspopup="dialog"
              onClick={(event) => {
                if (
                  event.button !== 0 ||
                  event.ctrlKey ||
                  event.metaKey ||
                  event.shiftKey ||
                  event.altKey
                )
                  return;
                event.preventDefault();
                setOpenDocument(doc);
              }}
            >
              <span className="sf-pdp-document-icon" aria-hidden="true">
                <svg
                  viewBox="0 0 24 24"
                  width="26"
                  height="26"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinejoin="round"
                >
                  <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                  <path d="M14 3v5h5" />
                </svg>
                <small>{doc.type.toUpperCase()}</small>
              </span>
              <span className="sf-pdp-document-name">{doc.name}</span>
              <svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                width="22"
                height="22"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
              >
                <circle cx="12" cy="12" r="9" />
                <path d="M12 11v5M12 8h.01" />
              </svg>
            </a>
          ))}
          {faqs.length > 0 && (
            <a className="sf-pdp-card-link" href="#faqs">
              <span>{t('storefront.faqs')}</span>
              <Chevron direction="next" />
            </a>
          )}
        </div>
      </div>
      {openDocument && (
        <DocumentPreview
          file={openDocument}
          onClose={() => setOpenDocument(null)}
        />
      )}
      {historyOpen && (
        <PriceHistory
          points={product.price_history}
          current={product.price ?? null}
          format={format}
          onClose={() => setHistoryOpen(false)}
        />
      )}
      <BoughtTogether product={shown} />
      <BoughtTogether product={product} reusable />
      {sectionOrder.map((key) => (
        <Fragment key={key}>{sectionContent[key]}</Fragment>
      ))}
      {faqs.length > 0 && (
        <section
          id="faqs"
          className="sf-pdp-section"
          aria-labelledby="sf-pdp-faqs"
        >
          <h2 id="sf-pdp-faqs">{t('storefront.faqs')}</h2>
          <FaqList faqs={faqs} />
        </section>
      )}
    </div>
  );
}
