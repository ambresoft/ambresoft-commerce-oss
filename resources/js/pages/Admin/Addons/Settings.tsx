import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import UnderlineTabs from '../../../components/admin/UnderlineTabs';
import SettingsFields, {
  fieldValues,
} from '../../../components/admin/SettingsFields';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SettingFieldView } from '../../../types/shipping';

type PanelAction = {
  key: string;
  label: string;
  confirm: string;
  destructive: boolean;
  in_form?: boolean;
};

type Rows = { label: string; value: string }[];

type Panel = {
  rows: Rows;
  actions: PanelAction[];
};

type RemoteChoice = {
  value: string;
  label: string;
  description: string;
  guide: string;
  icon?: string;
  fields: SettingFieldView[];
};

type Remote = {
  title: string;
  hint: string;
  selected: string;
  rows: Rows;
  choices: RemoteChoice[];
};

type Secret = { label: string; hint: string; value: string };

type Addon = {
  name: string;
  title: string;
  version: number;
  panel: Panel | null;
  remote?: Remote | null;
  fields: SettingFieldView[];
};

const csrf = () =>
  decodeURIComponent(
    document.cookie
      .split('; ')
      .find((row) => row.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length) ?? '',
  );

const runAction = async (name: string, action: PanelAction) => {
  const response = await fetch(
    `/admin/addons/${name}/settings/actions/${action.key}`,
    {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
    },
  );
  if (!response.ok) throw new Error(String(response.status));
  return response.json();
};

/** Add-ons → the settings an add-on declares; the CMS stores them and the add-on reads them. */
export default function AddonSettings({
  addon,
  ...shell
}: AdminShell & { addon: Addon }) {
  const t = useTranslation();
  return (
    <AdminLayout title={addon.title} {...shell}>
      <EditorHeader
        backHref="/admin/addons"
        backLabel={t('addons.title')}
        heading={addon.title}
        iconKey="core.addons"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('addons.settings')}
            </p>
          </>
        }
      />
      {addon.remote ? (
        <SettingsTabs addon={addon} remote={addon.remote} />
      ) : (
        <>
          {addon.panel && (
            <SettingsPanel name={addon.name} panel={addon.panel} />
          )}
          {/* A saved version remounts the form with the stored values. */}
          {addon.fields.length > 0 && (
            <SettingsForm key={addon.version} addon={addon} />
          )}
        </>
      )}
    </AdminLayout>
  );
}

/** A page with a remote form is too long to read as one column. Panels stay mounted, so a tab never loses what was typed. */
function SettingsTabs({ addon, remote }: { addon: Addon; remote: Remote }) {
  const t = useTranslation();
  const panel = addon.panel;
  const hasStatus =
    panel !== null &&
    (panel.rows.length > 0 || panel.actions.some((a) => !a.in_form));
  const tabs = [
    { key: 'remote', label: remote.title },
    ...(hasStatus ? [{ key: 'status', label: t('addons.tab_status') }] : []),
    ...(addon.fields.length > 0
      ? [{ key: 'settings', label: t('addons.tab_settings') }]
      : []),
  ];
  const [active, setActive] = useState('remote');
  return (
    <div className="w-full">
      <UnderlineTabs
        label={addon.title}
        idPrefix="addon"
        active={active}
        onSelect={setActive}
        tabs={tabs}
      />
      {tabs.map((tab) => (
        <div
          key={tab.key}
          id={'addon-panel-' + tab.key}
          role="tabpanel"
          aria-labelledby={'addon-tab-' + tab.key}
          hidden={active !== tab.key}
          className="[&>*]:max-w-none"
        >
          {tab.key === 'remote' && (
            <RemoteForm
              name={addon.name}
              remote={remote}
              actions={panel?.actions.filter((a) => a.in_form) ?? []}
            />
          )}
          {tab.key === 'status' && panel && (
            <SettingsPanel name={addon.name} panel={panel} />
          )}
          {tab.key === 'settings' && (
            <SettingsForm key={addon.version} addon={addon} />
          )}
        </div>
      ))}
    </div>
  );
}

/**
 * What the add-on reports, its actions, and a value an action returns. That value lives only in this component's state:
 * it comes from the action's response, so reloading or leaving the page loses it for good.
 */
function SettingsPanel({ name, panel }: { name: string; panel: Panel }) {
  const t = useTranslation();
  const [secret, setSecret] = useState<Secret | null>(null);
  const [copied, setCopied] = useState(false);
  const [failed, setFailed] = useState(false);
  const [busy, setBusy] = useState(false);
  const run = async (action: PanelAction) => {
    if (action.confirm !== '' && !window.confirm(action.confirm)) return;
    setBusy(true);
    setFailed(false);
    setCopied(false);
    try {
      setSecret((await runAction(name, action)).secret);
      router.reload({ only: ['addon'] });
    } catch {
      setFailed(true);
    } finally {
      setBusy(false);
    }
  };
  return (
    <section className="mb-6 w-full space-y-5 rounded-lg border border-slate-200 bg-white p-5 sm:p-7">
      <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        {panel.rows.map((row) => (
          <div key={row.label} className="contents">
            <dt className="text-sm text-slate-500">{row.label}</dt>
            <dd className="text-sm font-medium break-words">{row.value}</dd>
          </div>
        ))}
      </dl>
      {secret && (
        <div
          role="status"
          className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-4"
        >
          <p className="text-sm font-medium">{secret.label}</p>
          <p className="text-sm text-slate-600">{secret.hint}</p>
          <div className="flex gap-2">
            <input
              readOnly
              aria-label={secret.label}
              value={secret.value}
              autoComplete="off"
              spellCheck={false}
              className="min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 font-mono text-sm"
              onFocus={(event) => event.target.select()}
            />
            <button
              type="button"
              className="button-secondary"
              onClick={() =>
                void navigator.clipboard
                  ?.writeText(secret.value)
                  .then(() => setCopied(true))
              }
            >
              {t(copied ? 'addons.secret_copied' : 'addons.secret_copy')}
            </button>
          </div>
        </div>
      )}
      {failed && (
        <p role="alert" className="text-sm text-red-700">
          {t('addons.action_failed')}
        </p>
      )}
      <div className="flex flex-wrap justify-end gap-2">
        {panel.actions
          .filter((action) => !action.in_form)
          .map((action) => (
            <ActionButton
              key={action.key}
              action={action}
              busy={busy}
              onRun={run}
            />
          ))}
      </div>
    </section>
  );
}

function ActionButton({
  action,
  busy,
  onRun,
  secondary = false,
}: {
  action: PanelAction;
  busy: boolean;
  onRun: (action: PanelAction) => Promise<void>;
  secondary?: boolean;
}) {
  return (
    <button
      type="button"
      disabled={busy}
      className={
        action.destructive
          ? 'button-secondary text-red-700'
          : secondary
            ? 'button-secondary'
            : 'button-primary'
      }
      onClick={() => void onRun(action)}
    >
      {action.label}
    </button>
  );
}

/**
 * Settings that belong to another system. The values go to the add-on in this request and stay in this component's state
 * until it answers: the page's own form would keep them, and a redirect would flash them into the session.
 */
function RemoteForm({
  name,
  remote,
  actions,
}: {
  name: string;
  remote: Remote;
  actions: PanelAction[];
}) {
  const t = useTranslation();
  const [choice, setChoice] = useState(remote.selected);
  const [values, setValues] = useState<Record<string, Record<string, string>>>(
    () =>
      Object.fromEntries(
        remote.choices.map((c) => [c.value, fieldValues(c.fields)]),
      ),
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const current = remote.choices.find((c) => c.value === choice);
  // A key stays in memory only until the form no longer needs it.
  const forgetSecrets = () =>
    setValues(
      Object.fromEntries(
        remote.choices.map((c) => [c.value, fieldValues(c.fields)]),
      ),
    );
  const submit = async () => {
    setBusy(true);
    setSaved(false);
    setErrors({});
    try {
      const response = await fetch(`/admin/addons/${name}/settings/remote`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ choice, values: values[choice] }),
      });
      if (response.status !== 200 && response.status !== 422) {
        throw new Error(String(response.status));
      }
      const body = await response.json();
      setErrors(body.errors);
      if (response.ok) {
        setSaved(true);
        forgetSecrets();
        router.reload({ only: ['addon'] });
      }
    } catch {
      setErrors({ form: t('addons.action_failed') });
    } finally {
      setBusy(false);
    }
  };
  const run = async (action: PanelAction) => {
    if (action.confirm !== '' && !window.confirm(action.confirm)) return;
    setBusy(true);
    setSaved(false);
    setErrors({});
    try {
      await runAction(name, action);
      forgetSecrets();
      router.reload({ only: ['addon'] });
    } catch {
      setErrors({ form: t('addons.action_failed') });
    } finally {
      setBusy(false);
    }
  };
  return (
    <form
      className="space-y-5 rounded-lg border border-slate-200 bg-white p-5 sm:p-7"
      autoComplete="off"
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
    >
      <div>
        <h2 className="text-lg font-semibold">{remote.title}</h2>
        <p className="mt-1 text-sm text-slate-500">{remote.hint}</p>
      </div>
      {remote.rows.length > 0 && (
        <dl className="flex flex-wrap gap-2">
          {remote.rows.map((row) => (
            <div
              key={row.label}
              className="flex gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs"
            >
              <dt className="text-slate-500">{row.label}:</dt>
              <dd className="font-medium">{row.value}</dd>
            </div>
          ))}
        </dl>
      )}
      <div
        role="radiogroup"
        aria-label={remote.title}
        className="grid grid-cols-2 gap-3 lg:grid-cols-4"
      >
        {remote.choices.map((c) => (
          <label
            key={c.value}
            className="relative flex cursor-pointer flex-col gap-2 rounded-lg border border-slate-200 p-3 has-checked:border-primary has-checked:bg-primary-tint has-focus-visible:ring-2 has-focus-visible:ring-primary"
          >
            <input
              type="radio"
              name="remote-choice"
              className="sr-only"
              checked={choice === c.value}
              onChange={() => {
                setChoice(c.value);
                setErrors({});
                setSaved(false);
              }}
            />
            {c.icon && (
              <span
                aria-hidden="true"
                className="flex size-9 items-center justify-center rounded-md border border-slate-200 bg-[#fff] p-1.5"
              >
                <img src={c.icon} alt="" className="size-full object-contain" />
              </span>
            )}
            <span className="block text-sm font-semibold">{c.label}</span>
            <span className="hidden text-xs text-slate-500 sm:block">
              {c.description}
            </span>
            {remote.selected === c.value && (
              <span className="bg-primary text-on-primary absolute end-2 top-2 rounded-full px-2 py-0.5 text-[0.6875rem] font-medium">
                {t('addons.remote_in_use')}
              </span>
            )}
          </label>
        ))}
      </div>
      {current && (
        <>
          <div className="grid gap-5 md:grid-cols-2">
            <SettingsFields
              fields={current.fields}
              values={values[choice]}
              errors={errors}
              prefix=""
              idPrefix="remote-"
              onChange={(key, value) =>
                setValues({
                  ...values,
                  [choice]: { ...values[choice], [key]: value },
                })
              }
            />
          </div>
          {current.guide && (
            <details className="rounded-md bg-slate-50 p-4 text-sm text-slate-600">
              <summary className="cursor-pointer font-medium">
                {t('addons.remote_guide')}
              </summary>
              <p className="mt-2 whitespace-pre-line">{current.guide}</p>
            </details>
          )}
        </>
      )}
      {errors.form && (
        <p role="alert" className="text-sm text-red-700">
          {errors.form}
        </p>
      )}
      {saved && (
        <p role="status" className="text-sm text-slate-600">
          {t('addons.remote_saved')}
        </p>
      )}
      <div className="flex flex-wrap justify-end gap-2">
        {actions.map((action) => (
          <ActionButton
            key={action.key}
            action={action}
            busy={busy}
            onRun={run}
            secondary
          />
        ))}
        <EditorActionButton disabled={busy || !current}>
          {t('common.save')}
        </EditorActionButton>
      </div>
    </form>
  );
}

function SettingsForm({ addon }: { addon: Addon }) {
  const t = useTranslation();
  const form = useForm({
    version: addon.version,
    settings: fieldValues(addon.fields),
    clear: {} as Record<string, boolean>,
  });
  const errors = form.errors as Record<string, string>;
  return (
    <form
      className="w-full space-y-4 rounded-lg border border-slate-200 bg-white p-5 sm:p-7"
      onSubmit={(event) => {
        event.preventDefault();
        form.put(`/admin/addons/${addon.name}/settings`, {
          preserveScroll: true,
        });
      }}
    >
      <FormErrors errors={errors} />
      <div className="grid gap-5">
        <SettingsFields
          fields={addon.fields}
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
      <div className="flex justify-end">
        <EditorActionButton disabled={form.processing}>
          {t('common.save')}
        </EditorActionButton>
      </div>
    </form>
  );
}
