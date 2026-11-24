import UnifiedCard from '../../../components/admin/AdminCard';
import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormField from '../../../components/auth/FormField';
import FormErrors from '../../../components/admin/FormErrors';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import Select from '../../../components/admin/Select';
import { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { ReviewDetail, ReviewStatus } from '../../../types/reviews';
import type { SharedProps } from '../../../types/page';
import Stars, { statusTones } from './Stars';

const actionOrder: ReviewStatus[] = ['approved', 'rejected', 'spam', 'pending'];

export default function ReviewEdit({
  review,
  returnTo,
  ...shell
}: AdminShell & { review: ReviewDetail; returnTo: string }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const canEdit = !!capabilities['reviews.edit'];
  const moderate = !!capabilities['reviews.moderate'];
  const [deleting, setDeleting] = useState(false);
  const [moderating, setModerating] = useState(false);
  const { date, time } = useDateDisplay();
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const form = useForm({
    version: review.version,
    rating: String(review.rating),
    title: review.title ?? '',
    body: review.body,
    moderation_note: review.moderation_note,
  });
  useDirtyForm(form);
  const title = t('reviews.review_number', { id: review.id });
  const summary = review.product_rating;
  const setStatus = (status: ReviewStatus) =>
    router.post(
      '/admin/reviews/' + review.id + '/status',
      { version: review.version, status },
      {
        preserveScroll: true,
        onStart: () => setModerating(true),
        onFinish: () => setModerating(false),
      },
    );
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="reviews"
        backHref={returnTo}
        backLabel={t('reviews.back')}
        status={
          <>
            <StatusBadge tone={statusTones[review.status]}>
              {t(`reviews.status_${review.status}`)}
            </StatusBadge>
            {review.verified_purchase && (
              <StatusBadge tone="success">
                {t('reviews.verified_badge')}
              </StatusBadge>
            )}
          </>
        }
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('reviews.submitted_on', { date: when(review.created_at) })}
            </p>
          </>
        }
        actions={
          <>
            {(canEdit || moderate) && (
              <EditorActionButton
                disabled={form.processing}
                form="admin-reviews-form-0"
              >
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            )}
          </>
        }
        menu={[
          capabilities['reviews.delete'] && (
            <button
              type="button"
              className="button-secondary px-3 text-red-700 hover:bg-red-50"
              aria-label={t('reviews.delete')}
              title={t('reviews.delete')}
              onClick={() => setDeleting(true)}
            >
              <Icon name="trash" />
              {t('reviews.delete')}
            </button>
          ),
        ]}
      />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (!canEdit && !moderate) return;
          form.transform((data) => ({
            version: data.version,
            ...(canEdit
              ? { rating: data.rating, title: data.title, body: data.body }
              : {}),
            ...(moderate ? { moderation_note: data.moderation_note } : {}),
            return_to: returnTo,
          }));
          form.patch('/admin/reviews/' + review.id, { preserveScroll: true });
        }}
        id="admin-reviews-form-0"
      >
        <FormErrors errors={form.errors} />
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
          <div className="space-y-6">
            {moderate && review.transitions.length > 0 && (
              <section>
                <AdminCard
                  id="review-moderation"
                  title={t('reviews.moderation')}
                  iconKey="moderation"
                  bodyClassName="p-5 "
                >
                  <p className="mt-1 text-sm text-slate-500">
                    {t('reviews.moderation_hint')}
                  </p>
                  <div className="mt-4 flex flex-wrap gap-2">
                    {actionOrder
                      .filter((status) => review.transitions.includes(status))
                      .map((status) => (
                        <button
                          key={status}
                          type="button"
                          disabled={moderating || form.processing}
                          className={
                            status === 'approved'
                              ? 'button-primary'
                              : status === 'spam'
                                ? 'button-secondary text-red-700 hover:bg-red-50'
                                : 'button-secondary'
                          }
                          onClick={() => setStatus(status)}
                        >
                          {t(`reviews.action_${status}`)}
                        </button>
                      ))}
                  </div>
                </AdminCard>
              </section>
            )}
            <section>
              <AdminCard
                id="review-content"
                title={t('reviews.content')}
                iconKey="content"
                bodyClassName="p-5 space-y-5"
              >
                {canEdit ? (
                  <fieldset className="space-y-5" disabled={form.processing}>
                    <p className="text-sm text-slate-500">
                      {t('reviews.edit_hint')}
                    </p>
                    <label className="block max-w-xs text-sm font-medium">
                      {t('reviews.rating')}
                      <Select
                        className="mt-2"
                        aria-label={t('reviews.rating')}
                        value={form.data.rating}
                        invalid={!!form.errors.rating}
                        options={['5', '4', '3', '2', '1'].map((value) => ({
                          value,
                          label: t('reviews.stars_label', { count: value }),
                        }))}
                        onChange={(value) => form.setData('rating', value)}
                      />
                      {form.errors.rating && (
                        <p className="mt-1 text-sm text-red-700">
                          {form.errors.rating}
                        </p>
                      )}
                    </label>
                    <FormField
                      id="review-title"
                      label={t('reviews.review_title')}
                      value={form.data.title}
                      maxLength={150}
                      error={form.errors.title}
                      onChange={(e) => form.setData('title', e.target.value)}
                    />
                    <div>
                      <label
                        htmlFor="review-body"
                        className="mb-2 block text-sm font-medium"
                      >
                        {t('reviews.body')}
                      </label>
                      <textarea
                        id="review-body"
                        className="field min-h-48"
                        value={form.data.body}
                        maxLength={5000}
                        aria-invalid={!!form.errors.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                      />
                      {form.errors.body && (
                        <p role="alert" className="mt-1 text-sm text-red-700">
                          {form.errors.body}
                        </p>
                      )}
                    </div>
                  </fieldset>
                ) : (
                  <div className="space-y-3">
                    <Stars rating={review.rating} />
                    {review.title && (
                      <p className="font-medium break-words">{review.title}</p>
                    )}
                    <p className="text-sm whitespace-pre-wrap break-words text-slate-700">
                      {review.body}
                    </p>
                  </div>
                )}
                {review.edited_at && (
                  <p className="text-xs text-slate-500">
                    {t('reviews.edited_by', {
                      name: review.editor_name ?? t('reviews.former_staff'),
                      date: when(review.edited_at),
                    })}
                  </p>
                )}
              </AdminCard>
            </section>
            {moderate && (
              <section aria-labelledby="review-note">
                <UnifiedCard
                  title={t('common.details')}
                  iconKey="details"
                  bodyClassName="p-5 "
                >
                  <label
                    id="review-note"
                    htmlFor="review-note-field"
                    className="block text-base font-semibold"
                  >
                    {t('reviews.moderation_note')}
                  </label>
                  <p className="mt-1 mb-3 text-sm text-slate-500">
                    {t('reviews.note_hint')}
                  </p>
                  <textarea
                    id="review-note-field"
                    className="field min-h-28"
                    value={form.data.moderation_note}
                    maxLength={2000}
                    disabled={form.processing}
                    onChange={(e) =>
                      form.setData('moderation_note', e.target.value)
                    }
                  />
                  {form.errors.moderation_note && (
                    <p role="alert" className="mt-1 text-sm text-red-700">
                      {form.errors.moderation_note}
                    </p>
                  )}
                </UnifiedCard>
              </section>
            )}
          </div>
          <aside className="space-y-6">
            <section>
              <AdminCard
                title={t('reviews.product')}
                iconKey="product"
                bodyClassName="p-5 text-sm"
              >
                <p className="mt-3 font-medium break-words">
                  {capabilities['products.view'] ? (
                    <Link
                      className="hover:underline"
                      href={'/admin/products/' + review.product.id + '/edit'}
                    >
                      {review.product.name}
                    </Link>
                  ) : (
                    review.product.name
                  )}
                </p>
                {review.product.sku && (
                  <p className="mt-0.5 text-xs text-slate-500">
                    {review.product.sku}
                  </p>
                )}
                {review.product.trashed && (
                  <p className="mt-1 text-xs text-red-700">
                    {t('reviews.product_trashed')}
                  </p>
                )}
                <div className="mt-4 border-t border-slate-100 pt-4">
                  <p className="font-medium">{t('reviews.product_rating')}</p>
                  {summary.average === null ? (
                    <p className="mt-1 text-slate-500">
                      {t('reviews.no_rating')}
                    </p>
                  ) : (
                    <>
                      <p className="mt-1">
                        <span className="text-xl font-semibold">
                          {summary.average}
                        </span>{' '}
                        <span className="text-slate-500">
                          {t('reviews.approved_count', {
                            count: summary.count,
                          })}
                        </span>
                      </p>
                      <ul className="mt-2 space-y-1">
                        {(['5', '4', '3', '2', '1'] as const).map((stars) => (
                          <li
                            key={stars}
                            className="flex items-center justify-between gap-3 text-xs text-slate-600"
                          >
                            <span>
                              {t('reviews.stars_label', { count: stars })}
                            </span>
                            <span>{summary.distribution[stars]}</span>
                          </li>
                        ))}
                      </ul>
                    </>
                  )}
                  <Link
                    className="text-link mt-3 inline-block"
                    href={'/admin/reviews?product=' + review.product.id}
                  >
                    {t('reviews.product_reviews')}
                  </Link>
                </div>
              </AdminCard>
            </section>
            <section>
              <AdminCard
                title={t('reviews.customer')}
                iconKey="customer"
                bodyClassName="p-5 text-sm"
              >
                <p className="mt-3 font-medium break-words">
                  {capabilities['customers.view'] ? (
                    <Link
                      className="hover:underline"
                      href={'/admin/customers/' + review.customer.id}
                    >
                      {review.customer.name}
                    </Link>
                  ) : (
                    review.customer.name
                  )}
                </p>
                {review.customer.email && (
                  <p className="mt-0.5 text-xs break-all text-slate-500">
                    {review.customer.email}
                  </p>
                )}
                <p className="mt-3 text-slate-600">
                  {review.verified_purchase
                    ? t('reviews.verified_explained')
                    : t('reviews.unverified_explained')}
                </p>
              </AdminCard>
            </section>
            <section>
              <AdminCard
                title={t('reviews.history')}
                iconKey="history"
                bodyClassName="p-5 text-sm"
              >
                <dl className="mt-3 space-y-2">
                  <div>
                    <dt className="text-xs text-slate-500">
                      {t('reviews.submitted')}
                    </dt>
                    <dd>{when(review.created_at)}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-slate-500">
                      {t('reviews.moderated')}
                    </dt>
                    <dd>
                      {review.moderated_at
                        ? t('reviews.moderated_by', {
                            name:
                              review.moderator_name ??
                              t('reviews.former_staff'),
                            date: when(review.moderated_at),
                          })
                        : t('reviews.not_moderated')}
                    </dd>
                  </div>
                </dl>
              </AdminCard>
            </section>
          </aside>
        </div>
      </form>
      {deleting && (
        <ConfirmDeleteDialog
          title={t('reviews.delete_title', { id: review.id })}
          facts={[
            t('reviews.delete_fact_rating'),
            review.status === 'approved'
              ? t('reviews.delete_fact_public')
              : t('reviews.delete_fact_hidden'),
          ]}
          confirmLabel={t('reviews.delete')}
          onConfirm={() =>
            router.delete('/admin/reviews/' + review.id, {
              data: { version: review.version, return_to: returnTo },
              onFinish: () => setDeleting(false),
            })
          }
          onCancel={() => setDeleting(false)}
        />
      )}
    </AdminLayout>
  );
}
