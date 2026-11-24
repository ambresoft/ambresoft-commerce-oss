import UnifiedCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import EditorActions from '../../../components/admin/EditorActions';
import FormErrors from '../../../components/admin/FormErrors';
import SettingsFields, {
  fieldValues,
} from '../../../components/admin/SettingsFields';
import FormField from '../../../components/auth/FormField';
import { Icon } from '../../../components/admin/list/IconButton';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type {
  CarrierAccountView,
  CarrierView,
  SettingFieldView,
} from '../../../types/shipping';

type Tab = 'settings' | 'accounts';

function settingsData(carrier: CarrierView) {
  return {
    version: carrier.version,
    settings: fieldValues(carrier.settings),
    clear: {} as Record<string, boolean>,
  };
}

function Issues({ issues }: { issues: string[] }) {
  if (issues.length === 0) return null;
  return (
    <ul className="space-y-1 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
      {issues.map((issue) => (
        <li key={issue}>{issue}</li>
      ))}
    </ul>
  );
}

/** Editor of one named account; secrets stay write-only (the server only says whether one is stored). */
function AccountEditor({
  carrier,
  account,
  fields,
  onDone,
}: {
  carrier: CarrierView;
  account: CarrierAccountView | null;
  fields: SettingFieldView[];
  onDone: () => void;
}) {
  const t = useTranslation();
  const form = useForm({
    version: account?.version ?? 1,
    name: account?.name ?? '',
    settings: fieldValues(fields),
    clear: {} as Record<string, boolean>,
  });
  const errors = form.errors as Record<string, string>;
  const base = '/admin/shipping-carriers/' + carrier.id + '/accounts';

  return (
    <form
      className="space-y-5"
      onSubmit={(event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (account) form.put(base + '/' + account.id, options);
        else form.post(base, options);
      }}
    >
      <UnifiedCard
        title={
          account
            ? t('shipping.account_edit_of', { name: account.name })
            : t('shipping.account_create')
        }
        iconKey="general"
        bodyClassName="p-5 space-y-5"
      >
        <FormErrors errors={errors} />
        <FormField
          id="account-name"
          label={t('shipping.account_name')}
          hint={t('shipping.account_name_hint')}
          requiredIndicator
          maxLength={100}
          value={form.data.name}
          error={errors.name}
          onChange={(e) => form.setData('name', e.target.value)}
        />
        <div className="grid gap-5 sm:grid-cols-2">
          <SettingsFields
            fields={fields}
            values={form.data.settings}
            clear={form.data.clear}
            errors={errors}
            prefix="settings."
            onChange={(key, value) =>
              form.setData('settings', { ...form.data.settings, [key]: value })
            }
            onClear={(key, value) =>
              form.setData('clear', { ...form.data.clear, [key]: value })
            }
          />
        </div>
        <div className="flex flex-wrap justify-end gap-3">
          <button type="button" className="button-secondary" onClick={onDone}>
            {t('common.cancel')}
          </button>
          <EditorActionButton disabled={form.processing}>
            {t(form.processing ? 'common.wait' : 'common.save')}
          </EditorActionButton>
        </div>
      </UnifiedCard>
    </form>
  );
}

export default function ShippingCarrierShow({
  carrier,
  ...shell
}: AdminShell & { carrier: CarrierView }) {
  const t = useTranslation();
  const { capabilities, errors: pageErrors } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const { date, time } = useDateDisplay();
  const [tab, setTab] = useState<Tab>(() =>
    typeof window !== 'undefined' &&
    new URLSearchParams(window.location.search).get('tab') === 'accounts'
      ? 'accounts'
      : 'settings',
  );
  const [editing, setEditing] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const form = useForm(settingsData(carrier));
  const errors = form.errors as Record<string, string>;
  const sync = (page: { props: unknown }) => {
    const next = settingsData((page.props as { carrier: CarrierView }).carrier);
    form.setDefaults(next);
    form.setData(next);
  };
  const base = '/admin/shipping-carriers/' + carrier.id;
  const editingAccount = carrier.accounts.find((a) => a.id === editing) ?? null;
  const verify = (account: CarrierAccountView) => {
    setBusy(account.id);
    router.post(
      base + '/accounts/' + account.id + '/verify',
      {},
      { preserveScroll: true, onFinish: () => setBusy(null) },
    );
  };
  const remove = (account: CarrierAccountView) => {
    if (
      !window.confirm(
        t('shipping.account_delete_confirm', { name: account.name }),
      )
    )
      return;
    router.delete(base + '/accounts/' + account.id, {
      data: { version: account.version },
      preserveScroll: true,
    });
  };

  return (
    <AdminLayout title={carrier.name} {...shell}>
      <EditorHeader
        heading={carrier.name}
        iconKey="shipping"
        backHref="/admin/shipping-carriers"
        backLabel={t('shipping.carriers_title')}
        description={
          <>
            {carrier.owner_title && (
              <p className="mt-1 text-sm text-slate-500">
                {t('shipping.provided_by', { name: carrier.owner_title })}
              </p>
            )}
          </>
        }
        actions={
          <>
            {carrier.capabilities.manifests &&
              capabilities['orders.shipments'] && (
                <Link className="button-secondary" href={base + '/manifests'}>
                  {carrier.manifest_label ?? t('shipping.manifests_title')}
                </Link>
              )}
          </>
        }
      />

      <AdminTabs label={carrier.name} className="mb-4">
        {(['settings', 'accounts'] as const).map((item) => (
          <AdminTab
            key={item}
            type="button"
            role="tab"
            aria-selected={tab === item}
            onClick={() => setTab(item)}
            iconKey={item}
          >
            {t(
              item === 'settings'
                ? 'shipping.tab_settings'
                : 'shipping.tab_accounts',
            )}
            {item === 'accounts' && ' (' + carrier.accounts.length + ')'}
          </AdminTab>
        ))}
      </AdminTabs>

      {tab === 'settings' && (
        <form
          role="tabpanel"
          className="space-y-5"
          onSubmit={(event) => {
            event.preventDefault();
            form.put(base, { preserveScroll: true, onSuccess: sync });
          }}
        >
          <EditorActions>
            <EditorActionButton disabled={form.processing}>
              {t(form.processing ? 'common.wait' : 'common.save')}
            </EditorActionButton>
          </EditorActions>
          <FormErrors errors={errors} />
          <Issues issues={carrier.settings_issues} />
          <section>
            <UnifiedCard
              title={t('common.details')}
              iconKey="details"
              bodyClassName="p-5 grid gap-5 sm:grid-cols-2"
            >
              {carrier.settings.length === 0 ? (
                <p className="text-sm text-slate-500">
                  {t('shipping.no_settings')}
                </p>
              ) : (
                <SettingsFields
                  fields={carrier.settings}
                  values={form.data.settings}
                  clear={form.data.clear}
                  errors={errors}
                  prefix="settings."
                  onChange={(key, value) =>
                    form.setData('settings', {
                      ...form.data.settings,
                      [key]: value,
                    })
                  }
                  onClear={(key, value) =>
                    form.setData('clear', { ...form.data.clear, [key]: value })
                  }
                />
              )}
            </UnifiedCard>
          </section>
        </form>
      )}

      {tab === 'accounts' && (
        <div role="tabpanel" className="space-y-5">
          <FormErrors errors={pageErrors ?? {}} />
          <p className="max-w-3xl text-sm text-slate-500">
            {t('shipping.accounts_intro')}
          </p>
          {editing !== null ? (
            <AccountEditor
              key={editing}
              carrier={carrier}
              account={editingAccount}
              fields={
                editingAccount ? editingAccount.fields : carrier.account_fields
              }
              onDone={() => setEditing(null)}
            />
          ) : (
            <button
              type="button"
              className="button-primary gap-2"
              onClick={() => setEditing('new')}
            >
              <Icon name="plus" />
              {t('shipping.account_create')}
            </button>
          )}
          {carrier.accounts.length === 0 ? (
            <p className="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500">
              {t('shipping.accounts_empty')}
            </p>
          ) : (
            <ul className="space-y-3">
              {carrier.accounts.map((account) => (
                <li
                  key={account.id}
                  className="space-y-3 rounded-lg border border-slate-200 bg-white p-5"
                >
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <h3 className="font-semibold">{account.name}</h3>
                      <p className="mt-1 text-sm text-slate-500">
                        {account.issues.length > 0
                          ? t('shipping.account_incomplete_short')
                          : account.verified && account.verified_at
                            ? t('shipping.verified_at', {
                                date: date.format(
                                  new Date(account.verified_at),
                                ),
                                time: time.format(
                                  new Date(account.verified_at),
                                ),
                              })
                            : t('shipping.not_verified')}
                        {' · '}
                        {t('shipping.used_by_methods', {
                          count: account.methods,
                        })}
                      </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                      {carrier.capabilities.verify && (
                        <button
                          type="button"
                          className="button-secondary"
                          disabled={busy !== null || account.issues.length > 0}
                          onClick={() => verify(account)}
                        >
                          {busy === account.id
                            ? t('common.wait')
                            : t('shipping.verify')}
                        </button>
                      )}
                      <button
                        type="button"
                        className="button-secondary"
                        onClick={() => setEditing(account.id)}
                      >
                        {t('common.edit')}
                      </button>
                      {account.methods === 0 && (
                        <button
                          type="button"
                          className="button-secondary text-red-700"
                          onClick={() => remove(account)}
                        >
                          {t('common.delete')}
                        </button>
                      )}
                    </div>
                  </div>
                  <Issues issues={account.issues} />
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </AdminLayout>
  );
}
