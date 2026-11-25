import UnifiedCard from '../../../components/admin/AdminCard';
import AdminCard from '../../../components/admin/AdminCard';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import EditorHeader from '../../../components/admin/EditorHeader';
import type { ComponentProps, ReactNode } from 'react';
import FormErrors from '../../../components/admin/FormErrors';
import OrderStatusBadge from '../../../components/admin/OrderStatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { OrderStatusOption } from '../../../types/orders';
import type { SharedProps } from '../../../types/page';

type Kind = 'status' | 'layout' | 'invoice' | 'packing_slip';
type Field = 'subject' | 'body' | 'header' | 'footer' | 'item' | 'total';
type Template = { id: string; version: number } & Partial<
  Record<Field, string>
>;
type Variable = { name: string; label: string; block: boolean };
type Props = AdminShell & {
  kind: Kind;
  status: OrderStatusOption | null;
  templates: Record<string, Template>;
  variables: Variable[];
  defaultLocale: string;
  invoiceStatuses?: string[];
  localInbox?: string | null;
};

const fieldsOf: Record<Kind, Field[]> = {
  status: ['subject', 'body'],
  invoice: ['subject', 'body', 'item', 'total'],
  layout: ['header', 'footer'],
  packing_slip: ['body', 'item'],
};
const labelOf: Record<Field, string> = {
  item: 'orders.invoice_item_template',
  total: 'orders.invoice_total_template',
  subject: 'orders.template_subject',
  body: 'orders.template_body',
  header: 'orders.layout_header',
  footer: 'orders.layout_footer',
};

function xsrf(): string {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : '';
}

export default function OrderEmailEdit({
  kind,
  status,
  templates,
  variables,
  defaultLocale,
  invoiceStatuses = [],
  localInbox = null,
  ...shell
}: Props) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const requested = new URLSearchParams(window.location.search).get('locale');
  const [locale, setLocale] = useState(
    requested && templates[requested] ? requested : defaultLocale,
  );
  const title =
    kind === 'status'
      ? t('orders.template_for')
      : t(
          kind === 'layout'
            ? 'orders.layout_title'
            : kind === 'packing_slip'
              ? 'orders.packing_slip_title'
              : 'orders.invoice_email_title',
        );
  const intro =
    kind === 'packing_slip'
      ? t('orders.packing_slip_intro')
      : kind === 'status'
        ? t(
            status?.initial
              ? 'orders.template_initial_hint'
              : 'orders.template_update_hint',
          )
        : kind === 'layout'
          ? t('orders.layout_intro')
          : t('orders.invoice_email_intro') +
            ' ' +
            (invoiceStatuses.length > 0
              ? t('orders.invoice_statuses', {
                  statuses: invoiceStatuses.join(', '),
                })
              : t('orders.invoice_statuses_none'));
  return (
    <AdminLayout title={status?.name ?? title} {...shell}>
      {templates[locale] && (
        <TemplateEditor
          key={locale + ':' + templates[locale].version}
          kind={kind}
          statusId={status?.id ?? null}
          locale={locale}
          template={templates[locale]}
          variables={variables}
          localInbox={localInbox}
          header={{
            heading: title,
            iconKey: 'emails',
            backHref:
              kind === 'packing_slip'
                ? '/admin/orders/files'
                : '/admin/order-emails',
            backLabel: t(
              kind === 'packing_slip'
                ? 'orders.files_back'
                : 'orders.emails_back',
            ),
            status: status && <OrderStatusBadge status={status} />,
            description: intro,
          }}
          languageSelector={
            <AdminTabs
              language={
                <ContentLanguageSelector
                  languages={i18n.locales.filter((l) => templates[l.code])}
                  value={locale}
                  onChange={setLocale}
                />
              }
            />
          }
        />
      )}
    </AdminLayout>
  );
}

function TemplateEditor({
  kind,
  statusId,
  locale,
  template,
  variables,
  localInbox,
  header,
  languageSelector,
}: {
  kind: Kind;
  statusId: string | null;
  locale: string;
  template: Template;
  variables: Variable[];
  localInbox: string | null;
  header: Omit<ComponentProps<typeof EditorHeader>, 'actions'>;
  languageSelector: ReactNode;
}) {
  const t = useTranslation();
  const pageErrors = (usePage().props.errors ?? {}) as Record<string, string>;
  const fields = fieldsOf[kind];
  const refs = useRef<
    Partial<Record<Field, HTMLInputElement | HTMLTextAreaElement | null>>
  >({});
  const [focused, setFocused] = useState<Field>(
    kind === 'invoice' || kind === 'packing_slip'
      ? 'body'
      : fields[fields.length - 1],
  );
  const [recipient, setRecipient] = useState('');
  const [preview, setPreview] = useState<{
    subject: string;
    html: string;
  } | null>(null);
  const [previewError, setPreviewError] = useState('');
  const [loading, setLoading] = useState(false);
  const form = useForm<Record<string, string | number>>({
    version: template.version,
    ...Object.fromEntries(fields.map((f) => [f, template[f] ?? ''])),
  });
  const errors = form.errors as Record<string, string>;
  const content = Object.fromEntries(
    fields.map((f) => [f, String(form.data[f] ?? '')]),
  );
  const serialized = JSON.stringify(content);

  // Live preview: the server renders the unsaved content with sample data (nothing is saved or sent).
  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      setLoading(true);
      fetch('/admin/order-emails/preview', {
        method: 'POST',
        signal: controller.signal,
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-XSRF-TOKEN': xsrf(),
        },
        body: JSON.stringify({
          kind,
          status_id: statusId,
          locale,
          ...JSON.parse(serialized),
        }),
      })
        .then(async (response) => {
          const data = await response.json();
          if (!response.ok) {
            const first = Object.values(
              (data.errors ?? {}) as Record<string, string[]>,
            )[0];
            setPreviewError(
              first?.[0] ?? data.message ?? String(response.status),
            );
            return;
          }
          setPreviewError('');
          setPreview(data);
        })
        .catch((error: unknown) => {
          if (!(error instanceof DOMException && error.name === 'AbortError'))
            setPreviewError(String(error));
        })
        .finally(() => setLoading(false));
    }, 500);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [kind, statusId, locale, serialized]);

  const insert = (token: string) => {
    const field = focused;
    const element = refs.current[field];
    const value = String(form.data[field] ?? '');
    const start = element?.selectionStart ?? value.length;
    const end = element?.selectionEnd ?? start;
    form.setData(field, value.slice(0, start) + token + value.slice(end));
    window.requestAnimationFrame(() => {
      element?.focus();
      element?.setSelectionRange(start + token.length, start + token.length);
    });
  };
  const url =
    kind === 'status'
      ? '/admin/order-emails/' + statusId + '/templates/' + template.id
      : kind === 'packing_slip'
        ? '/admin/orders/files/packing-slip/' + template.id
        : '/admin/order-emails/' +
          (kind === 'invoice' ? 'order-summary' : kind) +
          '/' +
          template.id;
  return (
    <>
      <EditorHeader
        {...header}
        actions={
          <EditorActionButton
            form="order-email-template"
            disabled={form.processing}
          >
            {t(form.processing ? 'common.wait' : 'common.save')}
          </EditorActionButton>
        }
      />
      {languageSelector}
      <form
        id="order-email-template"
        className="grid gap-5 xl:grid-cols-2"
        onSubmit={(event) => {
          event.preventDefault();
          form.put(url, { preserveScroll: true });
        }}
      >
        <div className="space-y-5">
          <FormErrors errors={errors} />
          <section>
            <UnifiedCard
              title={t('common.details')}
              iconKey="details"
              bodyClassName="p-5 space-y-5"
            >
              {fields.map((field) =>
                field === 'subject' ? (
                  <div key={field} className="space-y-2">
                    <label
                      className="block text-sm font-medium"
                      htmlFor={field}
                    >
                      {t(labelOf[field])}
                    </label>
                    <input
                      id={field}
                      ref={(el) => {
                        refs.current[field] = el;
                      }}
                      className="field"
                      maxLength={250}
                      aria-invalid={!!errors[field]}
                      value={String(form.data[field] ?? '')}
                      onFocus={() => setFocused(field)}
                      onChange={(e) => form.setData(field, e.target.value)}
                    />
                    {errors[field] && (
                      <p role="alert" className="text-sm text-red-700">
                        {errors[field]}
                      </p>
                    )}
                  </div>
                ) : (
                  <div key={field} className="space-y-2">
                    <label
                      className="block text-sm font-medium"
                      htmlFor={field}
                    >
                      {t(
                        kind === 'packing_slip' && field === 'item'
                          ? 'orders.packing_item_template'
                          : labelOf[field],
                      )}
                    </label>
                    <textarea
                      id={field}
                      ref={(el) => {
                        refs.current[field] = el;
                      }}
                      className="field min-h-56 font-mono text-xs leading-5"
                      spellCheck={false}
                      aria-invalid={!!errors[field]}
                      value={String(form.data[field] ?? '')}
                      onFocus={() => setFocused(field)}
                      onChange={(e) => form.setData(field, e.target.value)}
                    />
                    {errors[field] && (
                      <p role="alert" className="text-sm text-red-700">
                        {errors[field]}
                      </p>
                    )}
                  </div>
                ),
              )}
              <p className="text-xs leading-5 text-slate-500">
                {t('orders.html_hint')}
              </p>
            </UnifiedCard>
          </section>
          <section>
            <AdminCard
              title={t('orders.variables')}
              iconKey="variables"
              bodyClassName="p-5 space-y-3"
            >
              <p className="text-xs leading-5 text-slate-500">
                {t('orders.variables_hint')}
              </p>
              <ul className="flex flex-wrap gap-2">
                {variables
                  .filter((v) => focused !== 'subject' || !v.block)
                  .map((v) => (
                    <li key={v.name}>
                      <button
                        type="button"
                        title={v.label}
                        className="rounded border border-slate-200 bg-slate-50 px-2 py-1 font-mono text-xs hover:bg-slate-100"
                        onClick={() => insert('{{' + v.name + '}}')}
                      >
                        {'{{' + v.name + '}}'}
                      </button>
                    </li>
                  ))}
              </ul>
            </AdminCard>
          </section>
        </div>
        <aside className="space-y-4 xl:sticky xl:top-4 xl:self-start">
          <section>
            <UnifiedCard
              title={t('orders.live_preview')}
              iconKey="live_preview"
              action={
                <div className="flex items-center justify-between gap-3">
                  {loading && (
                    <span role="status" className="text-xs text-slate-500">
                      {t('orders.preview_updating')}
                    </span>
                  )}
                </div>
              }
              bodyClassName="p-5 space-y-3"
            >
              {previewError && (
                <p role="alert" className="text-sm text-red-700">
                  {t('orders.preview_error', { message: previewError })}
                </p>
              )}
              {preview && (
                <>
                  {kind !== 'packing_slip' && (
                    <p className="text-sm">
                      <span className="text-slate-500">
                        {t('orders.template_subject')}:{' '}
                      </span>
                      <strong>{preview.subject}</strong>
                    </p>
                  )}
                  <iframe
                    title={t('orders.live_preview')}
                    sandbox=""
                    className="h-[42rem] w-full rounded-md border border-slate-200 bg-white"
                    srcDoc={preview.html}
                  />
                </>
              )}
              <p className="text-xs text-slate-500">
                {t('orders.preview_hint')}
              </p>
            </UnifiedCard>
          </section>
          {kind !== 'packing_slip' && (
            <section>
              <AdminCard
                title={t('orders.test_send')}
                iconKey="test_send"
                bodyClassName="p-5 space-y-3"
              >
                <div className="flex flex-wrap gap-2">
                  <input
                    type="email"
                    className="field grow"
                    aria-label={t('orders.test_recipient')}
                    placeholder={t('orders.test_recipient')}
                    value={recipient}
                    onChange={(e) => setRecipient(e.target.value)}
                  />
                  <button
                    type="button"
                    className="button-secondary"
                    disabled={recipient.trim() === ''}
                    onClick={() =>
                      router.post(
                        '/admin/order-emails/test',
                        {
                          kind,
                          status_id: statusId,
                          locale,
                          recipient,
                          ...content,
                        },
                        { preserveScroll: true, preserveState: true },
                      )
                    }
                  >
                    {t('orders.test_send')}
                  </button>
                </div>
                {pageErrors.recipient && (
                  <p role="alert" className="text-sm text-red-700">
                    {pageErrors.recipient}
                  </p>
                )}
                <p className="text-xs text-slate-500">
                  {t('orders.test_send_hint')}
                </p>
                {localInbox && (
                  <p className="rounded-md bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                    {t('orders.test_local_inbox_note')}{' '}
                    <a
                      className="font-medium underline"
                      href={localInbox}
                      target="_blank"
                      rel="noopener"
                    >
                      {localInbox}
                    </a>
                  </p>
                )}
              </AdminCard>
            </section>
          )}
        </aside>
      </form>
    </>
  );
}
