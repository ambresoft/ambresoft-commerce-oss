import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormField from '../../../components/auth/FormField';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import Pagination from '../../../components/admin/Pagination';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { giftCardStatus } from '../../../lib/giftCardStatus';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useUrlTab } from '../../../lib/useUrlTab';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type {
  GiftCardData,
  GiftCardDetail,
  GiftCardEmail,
  GiftCardStatus,
  GiftCardUse,
} from '../../../types/giftCards';

const tabs = ['general', 'history'] as const;
const choices: GiftCardStatus[] = ['pending', 'active', 'cancelled'];
const MESSAGE_LIMIT = 2000;

export default function GiftCardForm({
  giftCard,
  currency,
  locales,
  defaultLocale,
  history,
  emails,
  ...shell
}: AdminShell & {
  giftCard: GiftCardDetail | null;
  currency: { code: string; decimals: number };
  locales: { code: string; name: string }[];
  defaultLocale: string;
  history: Page<GiftCardUse> | null;
  emails: GiftCardEmail[];
}) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const manage = !!capabilities['giftcards.manage'] && !giftCard?.deleted_at;
  const [tab, setTab] = useUrlTab(giftCard ? tabs : (['general'] as const));
  const [deleting, setDeleting] = useState(false);
  const { date, time } = useDateDisplay();
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const form = useForm<GiftCardData>({
    version: giftCard?.version ?? 0,
    recipient_name: giftCard?.recipient_name ?? '',
    sender_name: giftCard?.sender_name ?? '',
    recipient_email: giftCard?.recipient_email ?? '',
    message: giftCard?.message ?? '',
    amount: giftCard?.amount ?? '',
    locale: giftCard?.locale ?? defaultLocale,
    status: giftCard?.status ?? 'active',
    notify: giftCard?.notify ?? true,
  });
  useDirtyForm(form);
  const used = giftCard?.status === 'used';
  const title = giftCard ? giftCard.code : t('giftcards.create');
  const sync = (page: { props: unknown }) => {
    const saved = (page.props as { giftCard: GiftCardDetail }).giftCard;
    const data: GiftCardData = {
      ...form.data,
      version: saved.version,
      recipient_name: saved.recipient_name,
      sender_name: saved.sender_name,
      recipient_email: saved.recipient_email,
      message: saved.message,
      amount: saved.amount,
      locale: saved.locale,
      status: saved.status,
      notify: saved.notify,
    };
    form.setDefaults(data);
    form.setData(data);
  };
  const text = (
    field: 'recipient_name' | 'sender_name' | 'recipient_email' | 'amount',
    help?: string,
  ) => (
    <FormField
      id={field}
      label={
        t(`giftcards.${field}`) +
        (field === 'amount' ? ` (${currency.code})` : '')
      }
      value={form.data[field]}
      onChange={(e) => form.setData(field, e.target.value)}
      error={form.errors[field]}
      tooltip={help ? t(`giftcards.${help}`) : undefined}
      requiredIndicator
      type={field === 'recipient_email' ? 'email' : 'text'}
      inputMode={field === 'amount' ? 'decimal' : undefined}
      maxLength={
        field === 'amount' ? 22 : field === 'recipient_email' ? 254 : 128
      }
      disabled={field === 'amount' && !!giftCard?.amount_locked}
    />
  );

  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={
          giftCard ? (
            <span className="font-mono">{giftCard.code}</span>
          ) : (
            t('giftcards.create')
          )
        }
        iconKey="gift"
        backHref="/admin/gift-cards"
        backLabel={t('giftcards.back')}
        status={
          <>
            {used || !manage ? (
              <StatusBadge tone={giftCardStatus[form.data.status][1]}>
                {t(giftCardStatus[form.data.status][0])}
              </StatusBadge>
            ) : (
              <EditorStatusSelect
                disabled={form.processing}
                id="gift-card-status"
                aria-label={t('giftcards.status')}
                value={form.data.status}
                onChange={(v) => form.setData('status', v as GiftCardStatus)}
                options={choices.map((value) => ({
                  value,
                  label: t(giftCardStatus[value][0]),
                }))}
              />
            )}
          </>
        }
        actions={
          <>
            {giftCard && manage && giftCard.status === 'active' && (
              <button
                type="button"
                className="button-secondary inline-flex items-center gap-2"
                disabled={form.processing}
                onClick={() =>
                  router.post(
                    `/admin/gift-cards/${giftCard.id}/send`,
                    {},
                    { preserveScroll: true },
                  )
                }
              >
                <Icon name="mail" />
                {t('giftcards.send_email')}
              </button>
            )}

            {manage && (
              <EditorActionButton
                disabled={form.processing}
                form="admin-giftcards-form-0"
              >
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            )}
          </>
        }
        menu={[
          giftCard &&
            !giftCard.deleted_at &&
            capabilities['giftcards.delete'] && (
              <button
                type="button"
                aria-label={t('giftcards.delete')}
                title={t('giftcards.delete')}
                className="button-secondary px-3 text-red-700 hover:bg-red-50 disabled:opacity-50"
                disabled={form.processing}
                onClick={() => setDeleting(true)}
              >
                <Icon name="trash" />
                {t('giftcards.delete')}
              </button>
            ),
        ]}
      />
      {deleting && giftCard && (
        <ConfirmDeleteDialog
          title={t('giftcards.delete_title', { count: 1 })}
          subtitle={t('giftcards.delete_subtitle')}
          facts={[
            <>
              {t('giftcards.delete_fact_balance')}
              <strong>{giftCard.balance}</strong>
            </>,
            t('giftcards.delete_fact_unusable'),
            t('giftcards.delete_fact_history'),
          ]}
          onCancel={() => setDeleting(false)}
          onConfirm={() => {
            setDeleting(false);
            router.delete(`/admin/gift-cards/${giftCard.id}`, {
              data: { version: giftCard.version },
            });
          }}
        />
      )}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (!manage) return;
          if (giftCard)
            form.put(`/admin/gift-cards/${giftCard.id}`, { onSuccess: sync });
          else form.post('/admin/gift-cards', { onSuccess: sync });
        }}
        id="admin-giftcards-form-0"
      >
        <AdminTabs className="mb-6">
          {tabs
            .filter((v) => v !== 'history' || giftCard)
            .map((v) => (
              <AdminTab
                key={v}
                type="button"
                role="tab"
                aria-selected={tab === v}
                aria-controls={`gift-card-${v}`}
                onClick={() => setTab(v)}
                iconKey={v}
              >
                {t(`giftcards.${v}`)}
              </AdminTab>
            ))}
        </AdminTabs>
        <FormErrors errors={form.errors} />
        {giftCard?.deleted_at && (
          <p className="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            {t('giftcards.archived')}
          </p>
        )}
        <div id="gift-card-general" role="tabpanel" hidden={tab !== 'general'}>
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
            bodyClassName="p-5 "
          >
            {giftCard && (
              <dl className="mb-8 grid gap-4 rounded-lg bg-slate-50 p-4 text-sm sm:grid-cols-3">
                <div>
                  <dt className="text-slate-500">{t('giftcards.code')}</dt>
                  <dd className="mt-1 font-mono font-medium break-all">
                    {giftCard.code}
                  </dd>
                </div>
                <div>
                  <dt className="text-slate-500">{t('giftcards.balance')}</dt>
                  <dd className="mt-1 font-medium tabular-nums">
                    {giftCard.balance}
                  </dd>
                </div>
                <div>
                  <dt className="text-slate-500">
                    {t('giftcards.created_at')}
                  </dt>
                  <dd className="mt-1">{when(giftCard.created_at)}</dd>
                </div>
              </dl>
            )}
            <fieldset disabled={!manage} className="min-w-0">
              <section className="border-b border-slate-200 pb-8">
                <h2 className="mb-6 text-lg font-semibold">
                  {t('giftcards.details')}
                </h2>
                <div className="grid gap-6 md:grid-cols-2">
                  {text('recipient_name')}
                  {text('sender_name')}
                  {text('recipient_email', 'email_help')}
                  <div className="space-y-2">
                    <label
                      htmlFor="gift-card-locale"
                      className="block text-sm font-medium"
                    >
                      {t('giftcards.locale')}
                    </label>
                    <Select
                      id="gift-card-locale"
                      aria-label={t('giftcards.locale')}
                      value={form.data.locale}
                      onChange={(v) => form.setData('locale', v)}
                      options={locales.map((l) => ({
                        value: l.code,
                        label: l.name,
                      }))}
                    />
                  </div>
                </div>
                <div className="mt-6 space-y-2">
                  <label
                    htmlFor="message"
                    className="block text-sm font-medium"
                  >
                    {t('giftcards.message')}
                  </label>
                  <textarea
                    id="message"
                    className="field min-h-32 w-full"
                    maxLength={MESSAGE_LIMIT}
                    value={form.data.message}
                    onChange={(e) => form.setData('message', e.target.value)}
                    aria-invalid={!!form.errors.message}
                  />
                  <p className="flex justify-between gap-3 text-xs text-slate-500">
                    <span>{t('giftcards.message_help')}</span>
                    <span className="tabular-nums">
                      {form.data.message.length} / {MESSAGE_LIMIT}
                    </span>
                  </p>
                  {form.errors.message && (
                    <p className="text-sm text-red-700">
                      {form.errors.message}
                    </p>
                  )}
                </div>
              </section>
              <section className="pt-8">
                <h2 className="mb-6 text-lg font-semibold">
                  {t('giftcards.value')}
                </h2>
                <div className="grid gap-6 md:grid-cols-2">
                  <div>
                    {text('amount', 'amount_help')}
                    {giftCard?.amount_locked && (
                      <p className="mt-2 text-sm text-slate-500">
                        {t('giftcards.amount_locked')}
                      </p>
                    )}
                  </div>
                  <div className="flex flex-col justify-center gap-2">
                    <label className="flex items-center gap-3 text-sm">
                      <input
                        type="checkbox"
                        checked={form.data.notify}
                        disabled={used}
                        onChange={(e) =>
                          form.setData('notify', e.target.checked)
                        }
                      />
                      <span>{t('giftcards.notify')}</span>
                    </label>
                    <p className="text-sm text-slate-500">
                      {t('giftcards.notify_help')}
                    </p>
                  </div>
                </div>
              </section>
            </fieldset>
          </UnifiedCard>
        </div>
        {giftCard && history && (
          <div
            id="gift-card-history"
            role="tabpanel"
            hidden={tab !== 'history'}
            className="space-y-6"
          >
            <div className="list-card">
              <div className="border-b border-slate-200 p-5">
                <h2 className="text-lg font-semibold">
                  {t('giftcards.history')}
                </h2>
              </div>
              <div className="relative max-w-full overflow-x-auto">
                <table className="data-table">
                  <thead>
                    <tr>
                      {[
                        'event',
                        'order',
                        'history_amount',
                        'balance_after',
                        'staff',
                        'date',
                      ].map((f) => (
                        <th key={f}>{t(`giftcards.${f}`)}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {history.data.length === 0 && (
                      <tr>
                        <td
                          colSpan={6}
                          className="py-12 text-center text-slate-500"
                        >
                          {t('giftcards.history_empty')}
                        </td>
                      </tr>
                    )}
                    {history.data.map((row) => (
                      <tr key={row.id}>
                        <td>
                          {t(
                            row.type === 'issued'
                              ? 'giftcards.event_issued'
                              : 'giftcards.event_redeemed',
                          )}
                        </td>
                        <td>
                          {row.order_id === null ? (
                            '—'
                          ) : capabilities['orders.view'] ? (
                            <Link
                              className="text-link"
                              href={`/admin/orders/${row.order_id}`}
                            >
                              #{row.order_number}
                            </Link>
                          ) : (
                            `#${row.order_number}`
                          )}
                        </td>
                        <td className="tabular-nums">
                          {row.type === 'redeemed' ? '−' : ''}
                          {row.amount}
                        </td>
                        <td className="tabular-nums">{row.balance_after}</td>
                        <td>{row.actor ?? '—'}</td>
                        <td className="whitespace-nowrap">
                          {when(row.created_at)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pagination page={history} />
            </div>
            <div className="list-card">
              <div className="border-b border-slate-200 p-5">
                <h2 className="text-lg font-semibold">
                  {t('giftcards.emails')}
                </h2>
              </div>
              <div className="relative max-w-full overflow-x-auto">
                <table className="data-table">
                  <thead>
                    <tr>
                      {[
                        'email_kind',
                        'sent_to',
                        'email_state',
                        'staff',
                        'date',
                      ].map((f) => (
                        <th key={f}>{t(`giftcards.${f}`)}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {emails.length === 0 && (
                      <tr>
                        <td
                          colSpan={5}
                          className="py-8 text-center text-slate-500"
                        >
                          {t('giftcards.emails_empty')}
                        </td>
                      </tr>
                    )}
                    {emails.map((email) => (
                      <tr key={email.id}>
                        <td>
                          {t(
                            email.kind === 'delivery'
                              ? 'giftcards.email_delivery'
                              : 'giftcards.email_cancelled',
                          )}
                        </td>
                        <td className="break-all">{email.recipient}</td>
                        <td>
                          <StatusBadge
                            tone={
                              email.state === 'sent'
                                ? 'success'
                                : email.state === 'failed'
                                  ? 'danger'
                                  : 'warning'
                            }
                          >
                            {t(
                              email.state === 'sent'
                                ? 'giftcards.email_sent'
                                : email.state === 'failed'
                                  ? 'giftcards.email_failed'
                                  : 'giftcards.email_pending',
                            )}
                          </StatusBadge>
                        </td>
                        <td>{email.actor ?? '—'}</td>
                        <td className="whitespace-nowrap">{when(email.at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}
      </form>
    </AdminLayout>
  );
}
