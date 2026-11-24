import { useEffect, useRef, useState } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type {
  CartLine,
  CartPageData,
  CurrencyFormat,
  PublicBlock,
} from '../../types';
import { useCart } from './cartContext';
import { cartItem, emitStorefrontEvent } from '../../events';
import Dialog from './Dialog';
import {
  InfoTip,
  Money,
  NetPrice,
  NoImage,
  Picture,
  QuantityStepper,
  TrashIcon,
} from './parts';

/** Quantity changes wait this long for the shopper to stop clicking before they are sent. */
const QUANTITY_DELAY = 450;

function BagIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="40"
      height="40"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M5 8h14l-1 12H6L5 8Z" />
      <path d="M9 8V6a3 3 0 0 1 6 0v2" />
    </svg>
  );
}

export function TicketIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="24"
      height="24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a3 3 0 0 0 0 6v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a3 3 0 0 0 0-6V7Z" />
      <path d="M9 5v2M9 11v2M9 17v2" />
    </svg>
  );
}

/** A summary row for an entered coupon or gift card: what it takes off, or why it does not apply. */
function CodeRow({
  label,
  amount,
  problem,
  updating,
  format,
  onRemove,
}: {
  label: string;
  amount: string | null;
  problem: string | null;
  updating: boolean;
  format: CurrencyFormat;
  onRemove: () => void;
}) {
  const t = useTranslation();
  return (
    <div className="sf-cart-code-row">
      <dt>
        {label}
        <button
          type="button"
          className="sf-cart-code-remove"
          aria-label={t('storefront.cart_code_remove', { code: label })}
          onClick={onRemove}
        >
          <TrashIcon />
        </button>
        {problem && (
          <span className="sf-cart-problem" role="alert">
            {problem}
          </span>
        )}
      </dt>
      <dd className={updating ? 'is-updating' : undefined}>
        {amount !== null && (
          <>
            −<Money amount={amount} format={format} inline />
          </>
        )}
      </dd>
    </div>
  );
}

/** The cart page's main content block: the lines on one side and a summary that follows the page on the other. */
export default function Cart({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const api = useCart();
  const [cart, setCart] = useState<CartPageData | undefined>(
    block.content.cart,
  );
  // Quantities chosen but not yet confirmed by the server, per product.
  const [pending, setPending] = useState<Record<string, number>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [removing, setRemoving] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [codeError, setCodeError] = useState('');
  const [codeBusy, setCodeBusy] = useState(false);
  const [clearing, setClearing] = useState<'ask' | 'busy' | null>(null);
  const timers = useRef<Record<string, number>>({});
  useEffect(
    () => () => Object.values(timers.current).forEach(window.clearTimeout),
    [],
  );
  if (!cart) return null;
  const format = cart.currency_format;
  const lines = cart.lines;
  const settle = (id: string, quantity?: number) =>
    setPending((old) => {
      if (quantity !== undefined && old[id] !== quantity) return old;
      const next = { ...old };
      delete next[id];
      return next;
    });
  const choose = (line: CartLine, quantity: number) => {
    setPending((old) => ({ ...old, [line.id]: quantity }));
    setErrors((old) => ({ ...old, [line.id]: '' }));
    window.clearTimeout(timers.current[line.id]);
    timers.current[line.id] = window.setTimeout(async () => {
      const reply = await api.change('PATCH', line.id, quantity);
      if (reply.ok) {
        setCart(reply.cart);
        settle(line.id, quantity);
      } else {
        settle(line.id);
        setErrors((old) => ({
          ...old,
          [line.id]: reply.message || t('storefront.cart_error'),
        }));
      }
    }, QUANTITY_DELAY);
  };
  const remove = async (line: CartLine) => {
    window.clearTimeout(timers.current[line.id]);
    setRemoving(line.id);
    const reply = await api.change('DELETE', line.id);
    setRemoving(null);
    if (reply.ok) {
      emitStorefrontEvent({
        type: 'remove_from_cart',
        currency: cart?.currency_format.code,
        items: [cartItem(line)],
      });
      setCart(reply.cart);
      settle(line.id);
    } else {
      setErrors((old) => ({
        ...old,
        [line.id]: reply.message || t('storefront.cart_error'),
      }));
    }
  };
  const clearAll = async () => {
    Object.values(timers.current).forEach((timer) =>
      window.clearTimeout(timer),
    );
    setClearing('busy');
    const reply = await api.clear();
    setClearing(null);
    if (reply.ok) {
      emitStorefrontEvent({
        type: 'remove_from_cart',
        currency: cart?.currency_format.code,
        items: (cart?.lines ?? []).map((l) => cartItem(l)),
      });
      setCart(reply.cart);
      setPending({});
      setErrors({});
    } else {
      setCodeError(reply.message || t('storefront.cart_error'));
    }
  };
  const sendCode = async (method: 'POST' | 'DELETE', value: string) => {
    setCodeBusy(true);
    setCodeError('');
    const reply = await api.code(method, value);
    setCodeBusy(false);
    if (reply.ok) {
      setCart(reply.cart);
      if (method === 'POST') setCode('');
    } else {
      setCodeError(reply.message || t('storefront.cart_error'));
    }
  };
  const updating = Object.keys(pending).length > 0;
  return (
    <div className="sf-cartpage">
      <h1>
        {t('storefront.cart_heading')}
        {cart.count > 0 && (
          <span>{t('storefront.cart_items_count', { count: cart.count })}</span>
        )}
      </h1>
      {lines.length === 0 ? (
        <div className="sf-cart-empty">
          <BagIcon />
          <p>
            <strong>{t('storefront.cart_empty')}</strong>
            <span>{t('storefront.cart_empty_hint')}</span>
          </p>
          <a className="sf-pdp-add" href="/">
            {t('storefront.cart_continue')}
          </a>
        </div>
      ) : (
        <div className="sf-cart-layout">
          <section className="sf-cart-lines" aria-label={t('storefront.cart')}>
            <ul>
              {lines.map((line) => {
                const quantity = pending[line.id] ?? line.quantity;
                const problem = errors[line.id] || line.problem;
                return (
                  <li
                    key={line.id}
                    className={`sf-cart-line${line.total === null ? ' is-blocked' : ''}${removing === line.id ? ' is-removing' : ''}`}
                  >
                    <a
                      className="sf-cart-picture"
                      href={line.url ?? undefined}
                      tabIndex={-1}
                      aria-hidden="true"
                    >
                      {line.image ? (
                        <Picture image={line.image} alt="" />
                      ) : (
                        <NoImage />
                      )}
                    </a>
                    <div className="sf-cart-info">
                      <h2>
                        {line.url ? (
                          <a href={line.url}>{line.name}</a>
                        ) : (
                          line.name
                        )}
                      </h2>
                      {(line.sku || line.price != null) && (
                        <p className="sf-cart-unit">
                          {line.sku && (
                            <span className="sf-cart-sku">{line.sku}</span>
                          )}
                          {line.price != null && line.base_price && (
                            <del>
                              <Money
                                amount={line.base_price}
                                format={format}
                                inline
                              />
                            </del>
                          )}
                          {line.price != null && (
                            <Money amount={line.price} format={format} inline />
                          )}
                        </p>
                      )}
                      {problem && (
                        <p className="sf-cart-problem" role="alert">
                          {problem}
                          {line.total === null && (
                            <span> {t('storefront.cart_excluded')}</span>
                          )}
                        </p>
                      )}
                      {line.rules.max > 0 && line.price != null ? (
                        <QuantityStepper
                          rules={line.rules}
                          value={quantity}
                          onChange={(value) => choose(line, value)}
                        />
                      ) : (
                        <span className="sf-cart-qty-fixed">×{quantity}</span>
                      )}
                    </div>
                    <div className="sf-cart-side">
                      <button
                        type="button"
                        className="sf-cart-remove"
                        aria-label={t('storefront.cart_remove_item', {
                          name: line.name ?? '',
                        })}
                        title={t('storefront.cart_remove')}
                        disabled={removing === line.id}
                        onClick={() => remove(line)}
                      >
                        <TrashIcon />
                      </button>
                      <strong
                        className={`sf-cart-total${pending[line.id] !== undefined ? ' is-updating' : ''}`}
                      >
                        {line.total !== null ? (
                          <>
                            <Money amount={line.total} format={format} />
                            <NetPrice
                              item={{
                                price_without_tax: line.total_without_tax,
                              }}
                              format={format}
                            />
                          </>
                        ) : (
                          '—'
                        )}
                      </strong>
                    </div>
                  </li>
                );
              })}
            </ul>
            <button
              type="button"
              className="sf-cart-clear"
              onClick={() => setClearing('ask')}
            >
              <TrashIcon />
              {t('storefront.cart_clear')}
            </button>
            {clearing && (
              <Dialog
                title={t('storefront.cart_clear_title')}
                onClose={() => clearing === 'ask' && setClearing(null)}
              >
                <p className="sf-cart-clear-text">
                  {t('storefront.cart_clear_text')}
                </p>
                <div className="sf-cart-clear-actions">
                  <button
                    type="button"
                    className="sf-acc-secondary"
                    disabled={clearing === 'busy'}
                    onClick={() => setClearing(null)}
                  >
                    {t('storefront.cart_clear_cancel')}
                  </button>
                  <button
                    type="button"
                    className="sf-cart-button"
                    disabled={clearing === 'busy'}
                    onClick={clearAll}
                  >
                    {t('storefront.cart_clear_confirm')}
                  </button>
                </div>
              </Dialog>
            )}
          </section>
          <aside className="sf-cart-summary" aria-busy={updating}>
            <h2>{t('storefront.cart_summary')}</h2>
            <dl>
              <div>
                <dt>{t('storefront.cart_subtotal')}</dt>
                <dd>
                  <Money amount={cart.subtotal} format={format} inline />
                </dd>
              </div>
              {cart.subtotal_without_tax && (
                <div className="sf-cart-net">
                  <dd>
                    <NetPrice
                      item={{ price_without_tax: cart.subtotal_without_tax }}
                      format={format}
                    />
                  </dd>
                </div>
              )}
              <div className="sf-cart-shipping">
                <dt>{t('storefront.cart_shipping')}</dt>
                <dd>{t('storefront.cart_shipping_later')}</dd>
              </div>
              {cart.coupon && (
                <CodeRow
                  label={t('storefront.cart_coupon', {
                    code: cart.coupon.code,
                  })}
                  amount={cart.coupon.discount}
                  problem={cart.coupon.problem}
                  updating={updating}
                  format={format}
                  onRemove={() => sendCode('DELETE', 'coupon')}
                />
              )}
              {cart.promotions.map((promotion) => (
                <div key={promotion.id}>
                  <dt>
                    {promotion.name}{' '}
                    {promotion.description && (
                      <InfoTip
                        className="sf-cart-vat"
                        label={t('storefront.cart_promotion_details', {
                          name: promotion.name,
                        })}
                        text={promotion.description}
                      />
                    )}
                  </dt>
                  <dd className={updating ? 'is-updating' : undefined}>
                    −
                    <Money amount={promotion.discount} format={format} inline />
                  </dd>
                </div>
              ))}
              {cart.gift_card && (
                <CodeRow
                  label={t('storefront.cart_gift_card', {
                    code: cart.gift_card.code,
                  })}
                  amount={cart.gift_card.amount}
                  problem={cart.gift_card.problem}
                  updating={updating}
                  format={format}
                  onRemove={() => sendCode('DELETE', 'gift_card')}
                />
              )}
              <div className="is-total">
                <dt>
                  {t('storefront.cart_total')}
                  <InfoTip
                    className="sf-cart-vat"
                    label={t('storefront.cart_vat_info')}
                    text={t('storefront.cart_vat_included')}
                  />
                </dt>
                <dd className={updating ? 'is-updating' : undefined}>
                  <Money amount={cart.total} format={format} />
                </dd>
              </div>
              {cart.total_without_tax && (
                <div className="sf-cart-net">
                  <dd>
                    <NetPrice
                      item={{ price_without_tax: cart.total_without_tax }}
                      format={format}
                    />
                  </dd>
                </div>
              )}
            </dl>
            {cart.codes && (
              <details className="sf-cart-code" open={codeError !== ''}>
                <summary>
                  <TicketIcon />
                  {t('storefront.cart_code_toggle')}
                </summary>
                <form
                  onSubmit={(event) => {
                    event.preventDefault();
                    if (code.trim()) sendCode('POST', code.trim());
                  }}
                >
                  <input
                    value={code}
                    onChange={(event) => setCode(event.target.value)}
                    placeholder={t('storefront.cart_code_label')}
                    aria-label={t('storefront.cart_code_label')}
                    aria-invalid={codeError !== ''}
                    maxLength={40}
                    autoComplete="off"
                  />
                  {codeError && (
                    <p className="sf-cart-problem" role="alert">
                      {codeError}
                    </p>
                  )}
                  <button
                    type="submit"
                    className="sf-cart-button"
                    disabled={codeBusy || !code.trim()}
                  >
                    {t('storefront.cart_code_apply')}
                  </button>
                </form>
              </details>
            )}
            <div className="sf-cart-next">
              {lines.some((line) => line.total !== null) ? (
                <a className="sf-cart-button" href="/checkout">
                  {t('storefront.checkout_proceed')}
                </a>
              ) : (
                <a className="sf-cart-button" href="/">
                  {t('storefront.cart_continue')}
                </a>
              )}
            </div>
          </aside>
        </div>
      )}
    </div>
  );
}
