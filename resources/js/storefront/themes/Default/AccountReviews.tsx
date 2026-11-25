import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { AccountPageData } from '../../types';
import { useDateFormat } from './AccountParts';
import Dialog from './Dialog';
import { Stars } from './parts';

/** Writes a review for one purchased product, in the same form the product page uses. */
function ReviewDialog({
  product,
  onClose,
}: {
  product: { id: string; name: string };
  onClose: () => void;
}) {
  const t = useTranslation();
  const form = useForm({ rating: 5, title: '', body: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.transform((data) => ({ ...data, return_to: '/account/reviews' }));
    form.post(`/products/${product.id}/reviews`, {
      preserveScroll: true,
      onSuccess: onClose,
    });
  };
  return (
    <Dialog title={product.name} onClose={onClose}>
      <form className="sf-pdp-review-form" onSubmit={submit}>
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
            maxLength={150}
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
            maxLength={5000}
            onChange={(e) => form.setData('body', e.target.value)}
          />
        </label>
        {form.errors.body && <p role="alert">{form.errors.body}</p>}
        <p className="sf-acc-hint">{t('account.review_moderation')}</p>
        <button type="submit" className="sf-pdp-add" disabled={form.processing}>
          {t(form.processing ? 'common.wait' : 'account.review_submit')}
        </button>
      </form>
    </Dialog>
  );
}

/** The customer's reviews, and the purchased products still waiting for one. */
export default function AccountReviews({ data }: { data: AccountPageData }) {
  const t = useTranslation();
  const format = useDateFormat();
  const [writing, setWriting] = useState<{ id: string; name: string } | null>(
    null,
  );
  const reviewable = data.reviewable ?? [];
  const reviews = data.reviews ?? [];
  return (
    <>
      <section className="sf-acc-card">
        <h2>{t('account.to_review')}</h2>
        {reviewable.length === 0 ? (
          <p className="sf-acc-empty">{t('account.nothing_to_review')}</p>
        ) : (
          <ul className="sf-acc-rows">
            {reviewable.map((p) => (
              <li key={p.id}>
                <span>{p.name}</span>
                <button
                  type="button"
                  className="sf-acc-secondary"
                  onClick={() => setWriting(p)}
                >
                  {t('account.review_write')}
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>
      <section className="sf-acc-card">
        <h2>{t('account.my_reviews')}</h2>
        {reviews.length === 0 ? (
          <p className="sf-acc-empty">{t('account.no_reviews')}</p>
        ) : (
          <ul className="sf-acc-rows is-stacked">
            {reviews.map((r) => (
              <li key={r.id}>
                <div className="sf-acc-review-head">
                  <strong>{r.product}</strong>
                  <span className={`sf-acc-state is-${r.state}`}>
                    {t(`account.review_${r.state}`)}
                  </span>
                </div>
                <div className="sf-acc-review-meta">
                  <Stars value={r.rating} size={16} />
                  <span>{format(r.created_at)}</span>
                  {r.verified_purchase && (
                    <span>{t('reviews.verified_badge')}</span>
                  )}
                </div>
                {r.title && <p>{r.title}</p>}
              </li>
            ))}
          </ul>
        )}
      </section>
      {writing && (
        <ReviewDialog product={writing} onClose={() => setWriting(null)} />
      )}
    </>
  );
}
