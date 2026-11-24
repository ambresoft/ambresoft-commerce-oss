import EditorHeader from '../../../components/admin/EditorHeader';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import BlacklistScopeFields, {
  type BlacklistScope,
} from '../../../components/admin/BlacklistScopeFields';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { BlacklistEntry } from '../../../types/customers';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & {
  entries: Page<BlacklistEntry>;
  filters: Record<string, string>;
  paymentMethods: { id: string; name: string }[];
};

function KindIcon({ kind }: { kind: 'email' | 'phone' }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      className="size-4"
    >
      {kind === 'email' ? (
        <>
          <rect x="3" y="5" width="18" height="14" rx="2" />
          <path d="m4 7 8 6 8-6" />
        </>
      ) : (
        <>
          <rect x="7" y="2.5" width="10" height="19" rx="2" />
          <path d="M11 18.5h2" />
        </>
      )}
    </svg>
  );
}

const initials = (name: string) =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('');

export default function CustomerBlacklistIndex({
  entries,
  filters,
  paymentMethods,
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const manage = !!capabilities['customer-blacklist.manage'];
  const apply = useListQuery('/admin/customer-blacklist', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  // `open` mounts the drawer; `shown` drives the slide/fade so the browser animates from the closed position.
  const [open, setOpen] = useState(false);
  const [shown, setShown] = useState(false);
  const valueField = useRef<HTMLInputElement>(null);
  const form = useForm({
    kind: 'email',
    value: '',
    reason: '',
    scope: 'full' as BlacklistScope,
    payment_method_ids: [] as string[],
  });
  useDirtyForm(form);
  const filtered = ['search', 'state', 'kind', 'scope'].some(
    (key) => filters[key],
  );
  const { date: stamp } = useDateDisplay();

  const dismiss = () => {
    setShown(false);
    form.clearErrors();
    // Unmount after the slide-out; reduced-motion users get an instant close.
    const instant = window.matchMedia?.(
      '(prefers-reduced-motion: reduce)',
    )?.matches;
    setTimeout(() => setOpen(false), instant ? 0 : 200);
  };

  useEffect(() => {
    if (!open) return;
    const frame = requestAnimationFrame(() => {
      setShown(true);
      valueField.current?.focus({ preventScroll: true });
    });
    const escape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') dismiss();
    };
    document.addEventListener('keydown', escape);
    return () => {
      cancelAnimationFrame(frame);
      document.removeEventListener('keydown', escape);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const release = (entry: BlacklistEntry) => {
    if (
      !window.confirm(
        t('customers.blacklist_release_confirm', { value: entry.value }),
      )
    )
      return;
    router.delete('/admin/customer-blacklist/' + entry.id, {
      preserveScroll: true,
    });
  };
  const close = dismiss;
  const kindLabel = (kind: 'email' | 'phone') =>
    t(
      kind === 'email'
        ? 'customers.blacklist_email'
        : 'customers.blacklist_phone',
    );

  return (
    <AdminLayout title={t('customers.blacklist_title')} {...shell}>
      <EditorHeader
        heading={t('customers.blacklist_title')}
        iconKey="security"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('customers.blacklist_description')}
            </p>
          </>
        }
        actions={
          <>
            {manage && (
              <button
                type="button"
                className="button-primary gap-2"
                onClick={() => setOpen(true)}
              >
                <Icon name="plus" />
                {t('customers.blacklist_add')}
              </button>
            )}
          </>
        }
      />
      <section
        className="list-card"
        aria-label={t('customers.blacklist_title')}
      >
        <FilterBar
          resetHref="/admin/customer-blacklist"
          active={filtered}
          search={
            <SearchField
              id="blacklist-search"
              value={search}
              placeholder={t('customers.blacklist_search')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('customers.blacklist_state')}
                value={filters.state ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  {
                    value: 'active',
                    label: t('customers.blacklist_state_active'),
                  },
                  {
                    value: 'released',
                    label: t('customers.blacklist_state_released'),
                  },
                ]}
                onChange={(value) => apply({ state: value })}
              />
              <FilterChip
                label={t('customers.blacklist_kind')}
                value={filters.kind ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'email', label: t('customers.blacklist_email') },
                  { value: 'phone', label: t('customers.blacklist_phone') },
                ]}
                onChange={(value) => apply({ kind: value })}
              />
              <FilterChip
                label={t('customers.blacklist_scope_filter')}
                value={filters.scope ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'full', label: t('customers.blacklist_scope_full') },
                  {
                    value: 'payment_methods',
                    label: t('customers.blacklist_scope_payment'),
                  },
                ]}
                onChange={(value) => apply({ scope: value })}
              />
            </>
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('customers.blacklist_value')}</th>
                <th>{t('customers.blacklist_reason')}</th>
                <th>{t('customers.blacklist_customer')}</th>
                <th>{t('customers.blacklist_when')}</th>
                <th>{t('customers.blacklist_state')}</th>
                {manage && (
                  <th className="text-right">{t('common.actions')}</th>
                )}
              </tr>
            </thead>
            <tbody>
              {entries.data.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-slate-500">
                    {t(
                      filtered
                        ? 'customers.blacklist_no_matches'
                        : 'customers.blacklist_empty',
                    )}
                  </td>
                </tr>
              )}
              {entries.data.map((e) => (
                <tr key={e.id} className={e.released ? 'opacity-60' : ''}>
                  <td>
                    <div className="flex items-center gap-3">
                      <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-600">
                        <KindIcon kind={e.kind} />
                      </span>
                      <div className="min-w-0">
                        <p className="truncate font-semibold">{e.value}</p>
                        <p className="text-xs text-slate-500">
                          {kindLabel(e.kind)}
                        </p>
                        {e.scope === 'payment_methods' && (
                          <p className="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">
                            {t('customers.blacklist_disabled_methods')}:{' '}
                            {e.payment_methods.join(', ')}
                          </p>
                        )}
                      </div>
                    </div>
                  </td>
                  <td className="max-w-xs text-sm">
                    <p className="truncate" title={e.reason}>
                      {e.reason}
                    </p>
                  </td>
                  <td className="text-sm">
                    {e.customer ? (
                      <Link
                        className="flex items-center gap-2 hover:underline"
                        href={'/admin/customers/' + e.customer.id}
                        aria-label={e.customer.name}
                      >
                        <span
                          aria-hidden="true"
                          className="flex size-7 items-center justify-center rounded-full bg-primary-tint text-[11px] font-semibold text-emerald-900"
                        >
                          {initials(e.customer.name)}
                        </span>
                        <span className="hidden xl:inline">
                          {e.customer.name}
                        </span>
                      </Link>
                    ) : (
                      <span
                        className="text-slate-400"
                        title={t('customers.blacklist_none')}
                      >
                        —
                      </span>
                    )}
                  </td>
                  <td className="text-sm whitespace-nowrap text-slate-600">
                    {stamp.format(new Date(e.created_at))}
                    {e.actor && <p className="text-xs">{e.actor}</p>}
                  </td>
                  <td>
                    <StatusBadge tone={e.released ? 'neutral' : 'success'}>
                      {t(
                        e.released
                          ? 'customers.blacklist_state_released'
                          : 'customers.blacklist_state_active',
                      )}
                    </StatusBadge>
                  </td>
                  {manage && (
                    <td className="text-right">
                      {!e.released && (
                        <div className="flex justify-end">
                          <IconButton
                            icon="trash"
                            danger
                            label={
                              t('customers.blacklist_release') + ': ' + e.value
                            }
                            onClick={() => release(e)}
                          />
                        </div>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={entries} />
      </section>
      {open && (
        <div className="fixed inset-0 z-50 flex justify-end">
          <button
            type="button"
            aria-label={t('customers.blacklist_close')}
            className={`absolute inset-0 bg-slate-900/15 transition-opacity duration-200 ease-out motion-reduce:transition-none ${shown ? 'opacity-100' : 'opacity-0'}`}
            onClick={close}
          />
          <form
            role="dialog"
            aria-modal="true"
            aria-labelledby="blacklist-drawer-title"
            className={`relative flex h-full w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-xl transition-transform duration-200 ease-out motion-reduce:transition-none ${shown ? 'translate-x-0' : 'translate-x-full'}`}
            onSubmit={(e) => {
              e.preventDefault();
              form.post('/admin/customer-blacklist', {
                preserveScroll: true,
                onSuccess: () => {
                  form.reset();
                  dismiss();
                },
              });
            }}
          >
            <header className="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
              <div>
                <h2
                  id="blacklist-drawer-title"
                  className="text-lg font-semibold"
                >
                  {t('customers.blacklist_add')}
                </h2>
                <p className="mt-0.5 text-sm text-slate-500">
                  {t('customers.blacklist_drawer_subtitle')}
                </p>
              </div>
              <button
                type="button"
                aria-label={t('customers.blacklist_close')}
                className="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50"
                onClick={close}
              >
                <svg
                  aria-hidden="true"
                  viewBox="0 0 20 20"
                  className="size-4"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.75"
                  strokeLinecap="round"
                >
                  <path d="m5 5 10 10M15 5 5 15" />
                </svg>
              </button>
            </header>
            <div className="flex-1 space-y-6 overflow-y-auto px-6 py-6">
              <fieldset>
                <legend className="mb-2 text-sm font-medium">
                  {t('customers.blacklist_kind')}
                </legend>
                <div className="grid grid-cols-2 gap-3">
                  {(['email', 'phone'] as const).map((kind) => (
                    <label
                      key={kind}
                      className={`flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 text-sm font-semibold transition-colors ${
                        form.data.kind === kind
                          ? 'border-primary bg-primary-tint/50 ring-2 ring-primary/20'
                          : 'border-slate-200 hover:bg-slate-50'
                      }`}
                    >
                      <input
                        type="radio"
                        name="kind"
                        className="sr-only"
                        checked={form.data.kind === kind}
                        onChange={() => form.setData('kind', kind)}
                      />
                      <KindIcon kind={kind} />
                      {kindLabel(kind)}
                    </label>
                  ))}
                </div>
                {form.errors.kind && (
                  <p role="alert" className="mt-2 text-sm text-red-700">
                    {form.errors.kind}
                  </p>
                )}
              </fieldset>
              <div className="space-y-2">
                <label htmlFor="bl-value" className="block text-sm font-medium">
                  {t('customers.blacklist_value')}
                </label>
                <input
                  id="bl-value"
                  ref={valueField}
                  className="field"
                  maxLength={254}
                  inputMode={form.data.kind === 'phone' ? 'tel' : 'email'}
                  placeholder={t(
                    form.data.kind === 'email'
                      ? 'customers.blacklist_email_placeholder'
                      : 'customers.blacklist_phone_placeholder',
                  )}
                  value={form.data.value}
                  aria-invalid={form.errors.value ? true : undefined}
                  onChange={(e) => form.setData('value', e.target.value)}
                />
                <p className="text-xs text-slate-500">
                  {t(
                    form.data.kind === 'email'
                      ? 'customers.blacklist_value_email_hint'
                      : 'customers.blacklist_value_phone_hint',
                  )}
                </p>
                {form.errors.value && (
                  <p role="alert" className="text-sm text-red-700">
                    {form.errors.value}
                  </p>
                )}
              </div>
              <BlacklistScopeFields
                scope={form.data.scope}
                methods={form.data.payment_method_ids}
                options={paymentMethods}
                onScope={(scope) => form.setData('scope', scope)}
                onMethods={(ids) => form.setData('payment_method_ids', ids)}
                error={form.errors.payment_method_ids ?? form.errors.scope}
              />
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <label
                    htmlFor="bl-reason"
                    className="block text-sm font-medium"
                  >
                    {t('customers.blacklist_reason')}
                  </label>
                  <span className="text-xs text-slate-500">
                    {t('customers.blacklist_required')}
                  </span>
                </div>
                <textarea
                  id="bl-reason"
                  className="field min-h-36"
                  maxLength={1000}
                  placeholder={t('customers.blacklist_reason_placeholder')}
                  value={form.data.reason}
                  aria-invalid={form.errors.reason ? true : undefined}
                  onChange={(e) => form.setData('reason', e.target.value)}
                />
                <p className="text-xs text-slate-500">
                  {t('customers.blacklist_staff_only')}
                </p>
                {form.errors.reason && (
                  <p role="alert" className="text-sm text-red-700">
                    {form.errors.reason}
                  </p>
                )}
              </div>
            </div>
            <footer className="flex justify-end gap-3 border-t border-slate-200 px-6 py-4">
              <button
                type="button"
                className="button-secondary"
                onClick={close}
              >
                {t('common.cancel')}
              </button>
              <button className="button-primary" disabled={form.processing}>
                {t('customers.blacklist_add_entry')}
              </button>
            </footer>
          </form>
        </div>
      )}
    </AdminLayout>
  );
}
