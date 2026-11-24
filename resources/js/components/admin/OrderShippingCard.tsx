import { Icon } from './list/IconButton';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FormErrors from './FormErrors';
import Select from './Select';
import SettingsFields, { fieldValues } from './SettingsFields';
import StatusBadge, { type Tone } from './list/StatusBadge';
import { useDateDisplay } from '../../lib/useDateDisplay';
import { useTranslation } from '../../lib/useTranslation';
import type {
  OrderShipping,
  ShipmentForm,
  ShipmentView,
} from '../../types/shipping';

// Literal keys (translation keys are never built at runtime).
const stateText: Record<ShipmentView['state'], [string, Tone]> = {
  creating: ['shipping.state_creating', 'warning'],
  created: ['shipping.state_created', 'success'],
  failed: ['shipping.state_failed', 'danger'],
  cancelled: ['shipping.state_cancelled', 'neutral'],
};
const trackingText: Record<
  NonNullable<ShipmentView['tracking_state']>,
  string
> = {
  created: 'shipping.tracking_created',
  in_transit: 'shipping.tracking_in_transit',
  delivered: 'shipping.tracking_delivered',
  returning: 'shipping.tracking_returning',
  returned: 'shipping.tracking_returned',
  exception: 'shipping.tracking_exception',
  unknown: 'shipping.tracking_unknown',
};

function Shipment({
  orderId,
  shipment,
}: {
  orderId: string;
  shipment: ShipmentView;
}) {
  const t = useTranslation();
  const { date, time } = useDateDisplay();
  const [format, setFormat] = useState(shipment.label_formats[0]?.value ?? '');
  const [busy, setBusy] = useState(false);
  const [events, setEvents] = useState(false);
  const [stateKey, stateTone] = stateText[shipment.state];
  const base = '/admin/orders/' + orderId + '/shipments/' + shipment.id;
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const act = (
    method: 'post' | 'delete',
    url: string,
    confirmation?: string,
  ) => {
    if (confirmation && !window.confirm(confirmation)) return;
    setBusy(true);
    router[method](
      url,
      {},
      { preserveScroll: true, onFinish: () => setBusy(false) },
    );
  };

  return (
    <li className="space-y-2 rounded-md border border-slate-200 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-medium tabular-nums">
          {shipment.tracking_number ?? shipment.provider_name}
        </span>
        <StatusBadge tone={stateTone}>{t(stateKey)}</StatusBadge>
      </div>
      <p className="text-xs text-slate-500">
        {shipment.provider_name}
        {shipment.account_name && ' · ' + shipment.account_name}
        {shipment.created_by && ' · ' + shipment.created_by}
        {shipment.created_at && ' · ' + when(shipment.created_at)}
      </p>
      {shipment.extra_numbers.length > 0 && (
        <p className="text-xs text-slate-500">
          {t('shipping.extra_numbers')}: {shipment.extra_numbers.join(', ')}
        </p>
      )}
      {shipment.state === 'created' && (
        <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
          {shipment.pickup_date && (
            <>
              <dt className="text-slate-500">{t('shipping.pickup_date')}</dt>
              <dd>
                {date.format(new Date(shipment.pickup_date + 'T00:00:00'))}
              </dd>
            </>
          )}
          {shipment.weight_kg && (
            <>
              <dt className="text-slate-500">{t('shipping.weight_kg')}</dt>
              <dd className="tabular-nums">{shipment.weight_kg}</dd>
            </>
          )}
          <dt className="text-slate-500">{t('shipping.packages')}</dt>
          <dd className="tabular-nums">{shipment.packages}</dd>
          {shipment.cod_amount && (
            <>
              <dt className="text-slate-500">{t('shipping.cod_amount')}</dt>
              <dd className="tabular-nums">{shipment.cod_amount}</dd>
            </>
          )}
          <dt className="text-slate-500">{t('shipping.label')}</dt>
          <dd>
            {shipment.label_printed_at
              ? when(shipment.label_printed_at)
              : t('shipping.label_not_printed')}
          </dd>
          {shipment.manifest_number && (
            <>
              <dt className="text-slate-500">{t('shipping.manifest')}</dt>
              <dd className="tabular-nums">{shipment.manifest_number}</dd>
            </>
          )}
        </dl>
      )}
      {shipment.tracking_state && (
        <div className="space-y-1 rounded-md bg-slate-50 p-2 text-xs">
          <p>
            <span className="font-medium">
              {t(trackingText[shipment.tracking_state])}
            </span>
            {shipment.tracking_summary && ' · ' + shipment.tracking_summary}
          </p>
          {shipment.tracking_checked_at && (
            <p className="text-slate-500">
              {t('shipping.tracking_checked', {
                at: when(shipment.tracking_checked_at),
              })}
            </p>
          )}
          {shipment.tracking_events.length > 0 && (
            <>
              <button
                type="button"
                className="text-link"
                aria-expanded={events}
                onClick={() => setEvents(!events)}
              >
                {t(events ? 'shipping.events_hide' : 'shipping.events_show')}
              </button>
              {events && (
                <ol className="space-y-1 border-l border-slate-200 pl-3">
                  {shipment.tracking_events.map((e, index) => (
                    <li key={index}>
                      <span className="text-slate-500">
                        {e.at ? when(e.at) : '—'}
                      </span>{' '}
                      {e.action}
                      {e.location && ' · ' + e.location}
                      {e.notes && (
                        <span className="block text-slate-500">{e.notes}</span>
                      )}
                    </li>
                  ))}
                </ol>
              )}
            </>
          )}
        </div>
      )}
      {shipment.error && (
        <p role="status" className="text-xs text-amber-800">
          {shipment.error}
        </p>
      )}
      {shipment.state === 'creating' && shipment.stale && (
        <p className="text-xs text-amber-800">
          {t('shipping.shipment_stale_hint')}
        </p>
      )}
      <div className="flex flex-wrap items-center gap-2">
        {shipment.actions.label && (
          <>
            {shipment.label_formats.length > 1 && (
              <Select
                aria-label={t('shipping.label_format')}
                className="w-36"
                value={format}
                onChange={setFormat}
                options={shipment.label_formats}
              />
            )}
            <a
              className="button-secondary"
              href={
                base + '/label?' + new URLSearchParams({ format }).toString()
              }
              target="_blank"
              rel="noopener"
            >
              {t('shipping.print_label')}
            </a>
          </>
        )}
        {shipment.actions.track && (
          <button
            type="button"
            className="button-secondary"
            disabled={busy}
            onClick={() => act('post', base + '/track')}
          >
            {t('shipping.track')}
          </button>
        )}
        {shipment.actions.cancel && (
          <button
            type="button"
            className="button-secondary text-red-700"
            disabled={busy}
            onClick={() =>
              act(
                'delete',
                base,
                t('shipping.cancel_confirm', {
                  number: shipment.tracking_number ?? '',
                }),
              )
            }
          >
            {t('shipping.cancel_shipment')}
          </button>
        )}
        {shipment.actions.resolve && (
          <button
            type="button"
            className="button-secondary"
            disabled={busy || !shipment.stale}
            onClick={() =>
              act('post', base + '/resolve', t('shipping.resolve_confirm'))
            }
          >
            {t('shipping.resolve')}
          </button>
        )}
      </div>
    </li>
  );
}

/** Shipping method, charge and carrier shipments of one order, with the staff shipment actions. */
export default function OrderShippingCard({
  orderId,
  shipping,
  shipmentForm,
}: {
  orderId: string;
  shipping: OrderShipping;
  shipmentForm: ShipmentForm | null;
}) {
  const t = useTranslation();
  const [open, setOpen] = useState(false);
  const fields = shipmentForm?.fields ?? [];
  const form = useForm({ fields: fieldValues(fields), additional: false });
  const errors = form.errors as Record<string, string>;
  const hasActive = shipping.shipments.some((s) => s.state === 'created');
  const details = shipping.details;

  return (
    <section
      className="rounded-lg border border-slate-200 bg-white"
      aria-labelledby="shipping-heading"
    >
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3">
        <h2
          id="shipping-heading"
          className="flex items-center gap-2.5 text-base font-semibold"
        >
          <span
            aria-hidden="true"
            className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-tint p-1.5 text-emerald-700"
          >
            <Icon name="truck" />
          </span>
          {t('shipping.order_title')}
        </h2>
      </div>
      <div className="space-y-4 p-5 text-sm">
        <dl className="space-y-2">
          <div className="flex justify-between gap-3">
            <dt className="text-slate-500">{t('orders.shipping_method')}</dt>
            <dd className="text-right font-medium">
              {shipping.method?.name ?? t('orders.shipping_method_none')}
            </dd>
          </div>
          {shipping.method && (
            <div className="flex justify-between gap-3">
              <dt className="text-slate-500">{t('orders.shipping_charge')}</dt>
              <dd className="tabular-nums">{shipping.charge}</dd>
            </div>
          )}
          {details?.chargeable_weight && (
            <div className="flex justify-between gap-3">
              <dt className="text-slate-500">
                {t('shipping.chargeable_weight')}
              </dt>
              <dd className="tabular-nums">
                {details.chargeable_weight} {details.weight_unit}
              </dd>
            </div>
          )}
          {details?.provider_name && (
            <div className="flex justify-between gap-3">
              <dt className="text-slate-500">
                {t('shipping.carrier_account')}
              </dt>
              <dd className="text-right">
                {details.provider_name}
                {details.account_name && ' · ' + details.account_name}
              </dd>
            </div>
          )}
        </dl>
        {details?.overridden && (
          <p className="text-xs text-slate-500">
            {details.calculated_amount
              ? t('shipping.charge_overridden_from', {
                  amount: details.calculated_amount,
                })
              : t('shipping.charge_overridden')}
          </p>
        )}
        {shipping.create_blocker && (
          <p className="text-xs text-amber-800">{shipping.create_blocker}</p>
        )}

        {(shipping.shipments.length > 0 || shipping.can_create) && (
          <div>
            <h3 className="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">
              {t('shipping.shipments_title')}
            </h3>
            {shipping.shipments.length === 0 ? (
              <p className="text-slate-500">{t('shipping.no_shipments')}</p>
            ) : (
              <ul className="space-y-2">
                {shipping.shipments.map((s) => (
                  <Shipment key={s.id} orderId={orderId} shipment={s} />
                ))}
              </ul>
            )}
          </div>
        )}

        {shipping.can_create && shipmentForm && !open && (
          <button
            type="button"
            className="button-primary w-full"
            onClick={() => setOpen(true)}
          >
            {t(
              hasActive
                ? 'shipping.create_additional'
                : 'shipping.create_shipment',
            )}
          </button>
        )}
        {shipping.can_create && shipmentForm && open && (
          <form
            className="space-y-3 border-t border-slate-200 pt-4"
            onSubmit={(event) => {
              event.preventDefault();
              // The key comes from the latest page props: a refused attempt gets a new one, a double submit does not.
              form.transform((data) => ({
                ...data,
                request_key: shipmentForm.request_key,
              }));
              form.post('/admin/orders/' + orderId + '/shipments', {
                preserveScroll: true,
                onSuccess: () => {
                  setOpen(false);
                  form.reset();
                },
              });
            }}
          >
            <h3 className="font-medium">{t('shipping.create_shipment')}</h3>
            <p className="text-xs text-slate-500">
              {t('shipping.create_shipment_hint')}
            </p>
            <FormErrors errors={errors} />
            <SettingsFields
              fields={fields}
              values={form.data.fields}
              errors={errors}
              prefix=""
              idPrefix="shipment-"
              onChange={(key, value) =>
                form.setData('fields', { ...form.data.fields, [key]: value })
              }
            />
            {hasActive && (
              <label className="flex items-start gap-2 rounded-md bg-amber-50 p-3 text-amber-900">
                <input
                  type="checkbox"
                  className="mt-0.5"
                  checked={form.data.additional}
                  onChange={(e) => form.setData('additional', e.target.checked)}
                />
                {t('shipping.additional_confirm')}
              </label>
            )}
            <div className="flex gap-2">
              <button
                type="button"
                className="button-secondary"
                onClick={() => setOpen(false)}
              >
                {t('common.cancel')}
              </button>
              <button
                className="button-primary grow"
                disabled={form.processing}
              >
                {t(form.processing ? 'common.wait' : 'shipping.create_submit')}
              </button>
            </div>
          </form>
        )}
      </div>
    </section>
  );
}
