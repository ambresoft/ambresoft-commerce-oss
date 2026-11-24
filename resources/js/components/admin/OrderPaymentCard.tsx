import { Icon } from './list/IconButton';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FormErrors from './FormErrors';
import StatusBadge, { type Tone } from './list/StatusBadge';
import { useDateDisplay } from '../../lib/useDateDisplay';
import { useTranslation } from '../../lib/useTranslation';
import type {
  OrderPayment,
  OrderPaymentRecord,
  OrderPaymentStatus,
} from '../../types/payments';

// Literal keys (translation keys are never built at runtime).
const statusText: Record<OrderPaymentStatus, [string, Tone]> = {
  unpaid: ['payments.status_unpaid', 'neutral'],
  pending: ['payments.status_pending', 'warning'],
  partially_paid: ['payments.status_partially_paid', 'warning'],
  paid: ['payments.status_paid', 'success'],
};
const stateText: Record<OrderPaymentRecord['state'], [string, Tone]> = {
  created: ['payments.state_created', 'neutral'],
  processing: ['payments.state_processing', 'warning'],
  pending: ['payments.state_pending', 'warning'],
  captured: ['payments.state_captured', 'success'],
  failed: ['payments.state_failed', 'danger'],
  cancelled: ['payments.state_cancelled', 'neutral'],
};

/** Payment status, recorded payments and staff payment actions of one order (independent of the order status). */
export default function OrderPaymentCard({
  orderId,
  payment,
}: {
  orderId: string;
  payment: OrderPayment;
}) {
  const t = useTranslation();
  const { date, time } = useDateDisplay();
  const [copied, setCopied] = useState(false);
  const record = useForm({ amount: payment.outstanding_raw, note: '' });
  const giftCard = useForm({ code: '' });
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const [statusKey, statusTone] = statusText[payment.status];

  return (
    <section
      className="rounded-lg border border-slate-200 bg-white"
      aria-labelledby="payment-heading"
    >
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3">
        <h2
          id="payment-heading"
          className="flex items-center gap-2.5 text-base font-semibold"
        >
          <span
            aria-hidden="true"
            className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-tint p-1.5 text-emerald-700"
          >
            <Icon name="card" />
          </span>
          {t('payments.payment_title')}
        </h2>
        <StatusBadge tone={statusTone}>{t(statusKey)}</StatusBadge>
      </div>
      <div className="space-y-4 p-5 text-sm">
        <dl className="space-y-2">
          <div className="flex justify-between gap-3">
            <dt className="text-slate-500">{t('payments.method')}</dt>
            <dd className="text-right font-medium">
              {payment.method?.name ?? t('payments.method_none')}
            </dd>
          </div>
          <div className="flex justify-between gap-3">
            <dt className="text-slate-500">{t('payments.paid_amount')}</dt>
            <dd className="tabular-nums">{payment.paid}</dd>
          </div>
          <div className="flex justify-between gap-3">
            <dt className="text-slate-500">{t('payments.outstanding')}</dt>
            <dd className="font-medium tabular-nums">{payment.outstanding}</dd>
          </div>
        </dl>
        {payment.method?.kind === 'offline' && payment.status !== 'paid' && (
          <p className="text-xs text-slate-500">
            {t('payments.method_offline')}
          </p>
        )}
        {payment.method?.kind === 'redirect' &&
          !payment.method.online &&
          payment.status !== 'paid' && (
            <p className="text-xs text-amber-800">
              {t('payments.method_unavailable')}
            </p>
          )}

        {payment.link && (
          <div className="space-y-2 rounded-md bg-slate-50 p-3">
            <p className="font-medium">{t('payments.link_title')}</p>
            <p className="text-xs text-slate-500">
              {t('payments.link_hint', { method: payment.method?.name ?? '' })}
            </p>
            <input
              className="field font-mono text-xs"
              readOnly
              aria-label={t('payments.link_title')}
              value={payment.link}
              onFocus={(e) => e.target.select()}
            />
            <button
              type="button"
              className="button-secondary w-full"
              onClick={() => {
                void navigator.clipboard
                  ?.writeText(payment.link ?? '')
                  .then(() => setCopied(true));
              }}
            >
              {t('payments.copy')}
            </button>
            {copied && (
              <p role="status" className="text-xs text-emerald-800">
                {t('payments.copied')}
              </p>
            )}
          </div>
        )}

        <div>
          <h3 className="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">
            {t('payments.records_title')}
          </h3>
          {payment.records.length === 0 ? (
            <p className="text-slate-500">{t('payments.no_records')}</p>
          ) : (
            <ul className="space-y-2">
              {payment.records.map((r) => {
                const [stateKey, stateTone] = stateText[r.state];
                return (
                  <li
                    key={r.id}
                    className="rounded-md border border-slate-200 p-3"
                  >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-medium tabular-nums">
                        {r.amount}
                      </span>
                      <StatusBadge tone={stateTone}>{t(stateKey)}</StatusBadge>
                    </div>
                    <p className="mt-1 text-xs text-slate-500">
                      {t(
                        r.kind === 'manual'
                          ? 'payments.kind_manual'
                          : r.kind === 'gift_card'
                            ? 'payments.kind_gift_card'
                            : 'payments.kind_online',
                      )}
                      {r.method && ' · ' + r.method}
                      {r.recorded_by && ' · ' + r.recorded_by}
                      {' · ' + when(r.completed_at ?? r.created_at)}
                    </p>
                    {(r.capture_reference ?? r.provider_reference) && (
                      <p className="mt-1 font-mono text-xs break-all text-slate-500">
                        {t('payments.reference')}:{' '}
                        {r.capture_reference ?? r.provider_reference}
                      </p>
                    )}
                    {r.capture_reference &&
                      r.provider_reference &&
                      r.provider_reference !== r.capture_reference && (
                        <p className="mt-1 font-mono text-xs break-all text-slate-500">
                          {t('payments.provider_reference')}:{' '}
                          {r.provider_reference}
                        </p>
                      )}
                    {r.detail && (
                      <p className="mt-1 font-mono text-xs text-slate-500">
                        {r.detail}
                      </p>
                    )}
                    {r.note && (
                      <p className="mt-1 text-xs whitespace-pre-line">
                        {r.note}
                      </p>
                    )}
                    {r.can_refresh && (
                      <button
                        type="button"
                        className="text-link mt-2 text-xs"
                        onClick={() =>
                          router.post(
                            '/admin/orders/' +
                              orderId +
                              '/payments/' +
                              r.id +
                              '/refresh',
                            {},
                            { preserveScroll: true },
                          )
                        }
                      >
                        {t('payments.check')}
                      </button>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </div>

        {payment.can_gift_card && (
          <form
            className="space-y-3 border-t border-slate-200 pt-4"
            onSubmit={(event) => {
              event.preventDefault();
              giftCard.post('/admin/orders/' + orderId + '/gift-card', {
                preserveScroll: true,
                onSuccess: () => giftCard.reset('code'),
              });
            }}
          >
            <div>
              <p className="font-medium">{t('payments.gift_card_title')}</p>
              <p className="text-xs text-slate-500">
                {t('payments.gift_card_hint')}
              </p>
            </div>
            <FormErrors errors={giftCard.errors} />
            <label className="block text-xs text-slate-500">
              {t('payments.gift_card_code')}
              <input
                className="field mt-1 font-mono uppercase"
                autoComplete="off"
                maxLength={40}
                placeholder="GC-XXXX-XXXX-XXXX"
                value={giftCard.data.code}
                onChange={(e) => giftCard.setData('code', e.target.value)}
              />
            </label>
            <button
              className="button-secondary w-full"
              disabled={giftCard.processing || giftCard.data.code.trim() === ''}
            >
              {t('payments.gift_card_apply')}
            </button>
          </form>
        )}

        {payment.can_record && (
          <form
            className="space-y-3 border-t border-slate-200 pt-4"
            onSubmit={(event) => {
              event.preventDefault();
              record.post('/admin/orders/' + orderId + '/payments', {
                preserveScroll: true,
                onSuccess: () => record.reset('note'),
              });
            }}
          >
            <div>
              <p className="font-medium">{t('payments.record_title')}</p>
              <p className="text-xs text-slate-500">
                {t('payments.record_hint')}
              </p>
            </div>
            <FormErrors errors={record.errors} />
            <label className="block text-xs text-slate-500">
              {t('payments.amount')}
              <input
                className="field mt-1 tabular-nums"
                inputMode="decimal"
                value={record.data.amount}
                onChange={(e) => record.setData('amount', e.target.value)}
              />
            </label>
            <label className="block text-xs text-slate-500">
              {t('payments.note')}
              <textarea
                className="field mt-1 min-h-14"
                maxLength={1000}
                value={record.data.note}
                onChange={(e) => record.setData('note', e.target.value)}
              />
            </label>
            <button
              className="button-secondary w-full"
              disabled={record.processing}
            >
              {t('payments.record')}
            </button>
          </form>
        )}
      </div>
    </section>
  );
}
