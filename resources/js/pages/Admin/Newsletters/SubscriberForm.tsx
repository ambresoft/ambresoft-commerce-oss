import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import FormField from '../../../components/auth/FormField';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { Choice, SubscriberEditor } from '../../../types/newsletters';
import type { SharedProps } from '../../../types/page';

function Languages({
  value,
  onChange,
  id,
}: {
  value: string;
  onChange: (v: string) => void;
  id: string;
}) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  return (
    <div className="space-y-2">
      <label className="block text-sm font-medium" htmlFor={id}>
        {t('newsletters.language')}
      </label>
      <Select
        id={id}
        aria-label={t('newsletters.language')}
        value={value}
        onChange={onChange}
        options={[
          { value: '', label: t('newsletters.language_default') },
          ...i18n.locales.map((l) => ({ value: l.code, label: l.name })),
        ]}
      />
    </div>
  );
}

/** Add one subscriber, add many at once (paste addresses) or edit an existing subscriber with its memberships. */
// Literal keys (translation keys are never built at runtime).
const sourceText: Record<string, string> = {
  admin: 'newsletters.source_admin',
  import: 'newsletters.source_import',
  storefront: 'newsletters.source_storefront',
};

export default function SubscriberForm({
  item,
  lists,
  ...shell
}: AdminShell & {
  item: SubscriberEditor | null;
  lists: Choice[];
  defaultLocale: string;
}) {
  const t = useTranslation();
  const [mode, setMode] = useState<'one' | 'many'>('one');
  const edit = useForm({
    version: item?.version ?? 1,
    email: item?.email ?? '',
    status: item?.status ?? 'active',
    locale: item?.locale ?? '',
    memberships: item?.memberships ?? ({} as Record<string, boolean>),
  });
  const add = useForm({
    email: '',
    locale: '',
    confirmed: true,
    list_ids: [] as string[],
  });
  const many = useForm({
    emails: '',
    locale: '',
    confirmed: true,
    list_ids: [] as string[],
  });
  const errors = (
    item ? edit.errors : mode === 'one' ? add.errors : many.errors
  ) as Record<string, string>;
  const setMembership = (
    id: string,
    state: 'none' | 'unconfirmed' | 'confirmed',
  ) => {
    const next = { ...edit.data.memberships };
    if (state === 'none') delete next[id];
    else next[id] = state === 'confirmed';
    edit.setData('memberships', next);
  };
  type Audience = { list_ids: string[]; confirmed: boolean };
  const title = item ? item.email : t('newsletters.add');
  const picker = (
    data: Audience,
    update: (patch: Partial<Audience>) => void,
  ) => (
    <>
      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">
          {t('newsletters.lists_title')}
        </legend>
        {lists.length === 0 ? (
          <p className="text-sm text-slate-500">{t('newsletters.no_lists')}</p>
        ) : (
          <div className="grid gap-1 sm:grid-cols-2">
            {lists.map((l) => (
              <label key={l.id} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={data.list_ids.includes(l.id)}
                  onChange={(e) =>
                    update({
                      list_ids: e.target.checked
                        ? [...data.list_ids, l.id]
                        : data.list_ids.filter((x) => x !== l.id),
                    })
                  }
                />
                {l.name}
              </label>
            ))}
          </div>
        )}
      </fieldset>
      <label className="flex items-start gap-2 text-sm">
        <input
          type="checkbox"
          className="mt-0.5"
          checked={data.confirmed}
          onChange={(e) => update({ confirmed: e.target.checked })}
        />
        <span>
          <span className="font-medium">{t('newsletters.mark_confirmed')}</span>
          <span className="block text-xs text-slate-500">
            {t('newsletters.mark_confirmed_hint')}
          </span>
        </span>
      </label>
    </>
  );

  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        status={
          <>
            {item && (
              <div className="flex items-center gap-2">
                <EditorStatusSelect
                  disabled={edit.processing}
                  id="sub-status"
                  aria-label={t('common.status')}
                  value={edit.data.status}
                  onChange={(v) =>
                    edit.setData('status', v as 'active' | 'unsubscribed')
                  }
                  options={[
                    {
                      value: 'active',
                      label: t('newsletters.subscriber_active'),
                    },
                    {
                      value: 'unsubscribed',
                      label: t('newsletters.subscriber_unsubscribed'),
                    },
                  ]}
                />
              </div>
            )}
          </>
        }
        heading={title}
        iconKey="emails"
        backHref="/admin/newsletter-subscribers"
        backLabel={t('newsletters.subscribers_title')}
        description={
          <>
            {item && (
              <p className="mt-1 text-sm text-slate-500">
                {t('newsletters.source_line', {
                  source: t(sourceText[item.source]),
                })}
              </p>
            )}
          </>
        }
        actions={
          <>
            <EditorActionButton
              disabled={edit.processing || add.processing || many.processing}
              form="admin-newsletters-form-0"
            >
              {t('common.save')}
            </EditorActionButton>
          </>
        }
      />
      {!item && (
        <AdminTabs label={t('newsletters.add')} className="mb-4">
          {(['one', 'many'] as const).map((m) => (
            <AdminTab
              key={m}
              type="button"
              role="tab"
              aria-selected={mode === m}
              onClick={() => setMode(m)}
              iconKey={m}
            >
              {t(m === 'one' ? 'newsletters.add_one' : 'newsletters.add_many')}
            </AdminTab>
          ))}
        </AdminTabs>
      )}
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          if (item)
            edit.put('/admin/newsletter-subscribers/' + item.id, {
              preserveScroll: true,
            });
          else if (mode === 'one') add.post('/admin/newsletter-subscribers');
          else many.post('/admin/newsletter-subscribers/import');
        }}
        id="admin-newsletters-form-0"
      >
        <FormErrors errors={errors} />
        <section>
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
            bodyClassName="p-5 space-y-5"
          >
            {item ? (
              <>
                <FormField
                  id="email"
                  type="email"
                  label={t('common.email')}
                  requiredIndicator
                  maxLength={254}
                  value={edit.data.email}
                  error={errors.email}
                  onChange={(e) => edit.setData('email', e.target.value)}
                />
                <div className="grid gap-5 sm:grid-cols-2">
                  <Languages
                    id="lang"
                    value={edit.data.locale}
                    onChange={(v) => edit.setData('locale', v)}
                  />
                </div>
                <fieldset className="space-y-2">
                  <legend className="text-sm font-medium">
                    {t('newsletters.lists_title')}
                  </legend>
                  {lists.map((l) => {
                    const state =
                      l.id in edit.data.memberships
                        ? edit.data.memberships[l.id]
                          ? 'confirmed'
                          : 'unconfirmed'
                        : 'none';
                    return (
                      <div
                        key={l.id}
                        className="flex flex-wrap items-center justify-between gap-3 text-sm"
                      >
                        <span>{l.name}</span>
                        <Select
                          aria-label={l.name}
                          className="w-48"
                          value={state}
                          onChange={(v) =>
                            setMembership(
                              l.id,
                              v as 'none' | 'unconfirmed' | 'confirmed',
                            )
                          }
                          options={[
                            {
                              value: 'none',
                              label: t('newsletters.not_subscribed'),
                            },
                            {
                              value: 'unconfirmed',
                              label: t('newsletters.subscribed_unconfirmed'),
                            },
                            {
                              value: 'confirmed',
                              label: t('newsletters.subscribed_confirmed'),
                            },
                          ]}
                        />
                      </div>
                    );
                  })}
                </fieldset>
              </>
            ) : mode === 'one' ? (
              <>
                <FormField
                  id="email"
                  type="email"
                  label={t('common.email')}
                  requiredIndicator
                  maxLength={254}
                  value={add.data.email}
                  error={errors.email}
                  onChange={(e) => add.setData('email', e.target.value)}
                />
                <Languages
                  id="lang"
                  value={add.data.locale}
                  onChange={(v) => add.setData('locale', v)}
                />
                {picker(add.data, (patch) =>
                  add.setData((d) => ({ ...d, ...patch })),
                )}
              </>
            ) : (
              <>
                <div className="space-y-2">
                  <label className="block text-sm font-medium" htmlFor="emails">
                    {t('newsletters.emails')}
                  </label>
                  <textarea
                    id="emails"
                    className="field min-h-40 font-mono text-xs"
                    value={many.data.emails}
                    onChange={(e) => many.setData('emails', e.target.value)}
                  />
                  <p className="text-xs text-slate-500">
                    {t('newsletters.emails_hint')}
                  </p>
                </div>
                <Languages
                  id="lang"
                  value={many.data.locale}
                  onChange={(v) => many.setData('locale', v)}
                />
                {picker(many.data, (patch) =>
                  many.setData((d) => ({ ...d, ...patch })),
                )}
              </>
            )}
          </UnifiedCard>
        </section>
      </form>
    </AdminLayout>
  );
}
