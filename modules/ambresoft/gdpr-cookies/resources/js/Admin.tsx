import EditorHeader from '../../../../../resources/js/components/admin/EditorHeader';
import EditorActionButton from '../../../../../resources/js/components/admin/EditorActionButton';
import ContentLanguageSelector from '../../../../../resources/js/components/admin/ContentLanguageSelector';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../../../resources/js/layouts/admin/AdminLayout';

import FormErrors from '../../../../../resources/js/components/admin/FormErrors';
import UnderlineTabs from '../../../../../resources/js/components/admin/UnderlineTabs';
import {
  CheckField,
  Choice,
  Field,
  Section,
  SubSection,
} from '../../../../../resources/js/components/admin/form/fields';
import { useDirtyForm } from '../../../../../resources/js/lib/useUnsavedChanges';
import { useTranslation } from '../../../../../resources/js/lib/useTranslation';
import type { AdminShell } from '../../../../../resources/js/types/admin';

const CATEGORIES = ['necessary', 'preferences', 'statistics', 'marketing'];
const OPTIONAL = ['preferences', 'statistics', 'marketing'];
type Texts = { title: string; body: string; about: string };
type CategoryText = { name: string; description: string };
type Cookie = {
  id: number | null;
  category: string;
  name: string;
  provider: string;
  purposes: Record<string, string>;
  expiries: Record<string, string>;
};
type Script = {
  id: number | null;
  category: string;
  name: string;
  code: string;
  enabled: boolean;
};
export type GdprCookiesProps = AdminShell & {
  settings: {
    version: number;
    consent_months: number;
    privacy_url: string;
    reopen_button: boolean;
    initial_categories: Record<
      'preferences' | 'statistics' | 'marketing',
      boolean
    >;
    lock_version: number;
  };
  languages: { code: string; name: string }[];
  texts: Record<string, Texts>;
  categories: Record<string, Record<string, CategoryText>>;
  cookies: Cookie[];
  scripts: Script[] | null;
  defaults: {
    title: string;
    body: string;
    about: string;
    categories: Record<string, CategoryText>;
  };
  consents: { total: number; recent: Record<string, number> };
  lookup: {
    id: string;
    records: {
      version: number;
      preferences: boolean;
      statistics: boolean;
      marketing: boolean;
      action: string;
      created_at: string;
    }[];
  } | null;
  canManage: boolean;
  canScripts: boolean;
};

const base = '/admin/addons/ambresoft/gdpr-cookies';
const ui = (key: string) => 'cms-gdpr::gdpr.' + key;
type Tab = 'general' | 'categories' | 'cookies' | 'scripts' | 'consents';

export default function GdprCookies(props: GdprCookiesProps) {
  const t = useTranslation();
  const [tab, setTab] = useState<Tab>(props.lookup ? 'consents' : 'general');
  const [language, setLanguage] = useState(props.languages[0]?.code ?? 'en');
  const disabled = !props.canManage;
  const form = useForm({
    lock_version: props.settings.lock_version,
    consent_months: String(props.settings.consent_months),
    privacy_url: props.settings.privacy_url,
    reopen_button: props.settings.reopen_button,
    initial_categories: props.settings.initial_categories,
    texts: props.texts,
    categories: props.categories,
    cookies: props.cookies,
  });
  useDirtyForm(form);
  const scripts = useForm({ scripts: props.scripts ?? [] });
  const [lookup, setLookup] = useState(props.lookup?.id ?? '');
  const d = form.data;
  const error = (key: string) => (form.errors as Record<string, string>)[key];
  const setText = (field: keyof Texts, value: string) =>
    form.setData('texts', {
      ...d.texts,
      [language]: { ...d.texts[language], [field]: value },
    });
  const setCategory = (
    category: string,
    field: keyof CategoryText,
    value: string,
  ) =>
    form.setData('categories', {
      ...d.categories,
      [category]: {
        ...d.categories[category],
        [language]: { ...d.categories[category]?.[language], [field]: value },
      },
    });
  const updateCookie = (index: number, patch: Partial<Cookie>) =>
    form.setData(
      'cookies',
      d.cookies.map((c, i) => (i === index ? { ...c, ...patch } : c)),
    );
  const categoryLabel = (c: string) =>
    d.categories[c]?.[language]?.name || props.defaults.categories[c].name;

  const save = (
    <EditorActionButton
      type="submit"
      form="gdpr-settings"

      disabled={form.processing || disabled}
    >
      {t(ui('save'))}
    </EditorActionButton>
  );

  return (
    <AdminLayout
      title={t(ui('title'))}
      navigation={props.navigation}
      breadcrumbs={props.breadcrumbs}
    >
      <EditorHeader
        heading={t(ui('title'))}
        iconKey="cookies"
        actions={
          <>
            {props.canManage && (
              <button
                type="button"
                className="button-secondary"
                onClick={() => {
                  if (window.confirm(t(ui('renew_confirm'))))
                    router.post(base + '/renew', {}, { preserveScroll: true });
                }}
              >
                {t(ui('renew'))}
              </button>
            )}
            {props.canManage && tab !== 'scripts' && tab !== 'consents' && save}
          </>
        }
      />
      <p className="mt-2 mb-6 max-w-3xl text-sm text-slate-600">
        {t(ui('intro'))}
      </p>

      <UnderlineTabs
        label={t(ui('title'))}
        language={
          <ContentLanguageSelector
            languages={props.languages}
            value={language}
            onChange={setLanguage}
            errors={form.errors}
          />
        }
        idPrefix="gdpr"
        active={tab}
        onSelect={setTab}
        tabs={(
          ['general', 'categories', 'cookies', 'scripts', 'consents'] as Tab[]
        ).map((key) => ({
          key,
          label: t(ui('tab_' + key)),
          error: Object.keys(form.errors).some((k) =>
            key === 'general'
              ? k.startsWith('texts') ||
                ['privacy_url', 'consent_months'].includes(k)
              : k.startsWith(key),
          ),
        }))}
      />

      <div
        role="tabpanel"
        id={'gdpr-panel-' + tab}
        aria-labelledby={'gdpr-tab-' + tab}
      >
        <form
          id="gdpr-settings"
          className="space-y-6"
          onSubmit={(e) => {
            e.preventDefault();
            form.transform((data) => ({
              ...data,
              consent_months: Number(data.consent_months),
            }));
            form.put(base, { preserveScroll: true });
          }}
        >
          <FormErrors errors={form.errors} />
          {tab === 'general' && (
            <Section iconKey="cookie" title={t(ui('tab_general'))}>
              {(['title', 'body', 'about'] as const).map((field) => (
                <Field
                  key={field}
                  id={'gdpr-' + field}
                  label={t(
                    ui(
                      field === 'title'
                        ? 'banner_title'
                        : field === 'body'
                          ? 'banner_body'
                          : 'about',
                    ),
                  )}
                  help={t(ui('default_hint')) + ' ' + props.defaults[field]}
                  error={error(`texts.${language}.${field}`)}
                >
                  {field === 'title' ? (
                    <input
                      id={'gdpr-' + field}
                      className="field w-full"
                      value={d.texts[language]?.[field] ?? ''}
                      disabled={disabled}
                      onChange={(e) => setText(field, e.target.value)}
                    />
                  ) : (
                    <textarea
                      id={'gdpr-' + field}
                      className="field min-h-32 w-full"
                      value={d.texts[language]?.[field] ?? ''}
                      disabled={disabled}
                      onChange={(e) => setText(field, e.target.value)}
                    />
                  )}
                </Field>
              ))}
              <Field
                id="gdpr-privacy"
                label={t(ui('privacy_url'))}
                help={t(ui('privacy_url_help'))}
                error={error('privacy_url')}
              >
                <input
                  id="gdpr-privacy"
                  className="field w-full"
                  value={d.privacy_url}
                  disabled={disabled}
                  onChange={(e) => form.setData('privacy_url', e.target.value)}
                />
              </Field>
              <Field
                id="gdpr-months"
                label={t(ui('consent_months'))}
                help={t(ui('consent_months_help'))}
                error={error('consent_months')}
              >
                <input
                  id="gdpr-months"
                  type="number"
                  min={1}
                  max={12}
                  className="field w-28"
                  value={d.consent_months}
                  disabled={disabled}
                  onChange={(e) =>
                    form.setData('consent_months', e.target.value)
                  }
                />
              </Field>
              <CheckField
                id="gdpr-reopen"
                label={t(ui('reopen_button'))}
                help={t(ui('reopen_button_help'))}
                checked={d.reopen_button}
                disabled={disabled}
                onChange={(v) => form.setData('reopen_button', v)}
              />
              <p className="text-sm text-slate-600">{t(ui('google_note'))}</p>
            </Section>
          )}

          {tab === 'categories' && (
            <Section iconKey="features" title={t(ui('tab_categories'))}>
              <SubSection
                title={t(ui('initial_categories'))}
                description={t(ui('initial_categories_help'))}
              >
                <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                  {t(ui('initial_categories_warning'))}
                </p>
                {(['preferences', 'statistics', 'marketing'] as const).map(
                  (category) => (
                    <CheckField
                      key={category}
                      id={'gdpr-initial-' + category}
                      label={categoryLabel(category)}
                      checked={d.initial_categories[category]}
                      disabled={disabled}
                      onChange={(checked) =>
                        form.setData('initial_categories', {
                          ...d.initial_categories,
                          [category]: checked,
                        })
                      }
                    />
                  ),
                )}
              </SubSection>
              {CATEGORIES.map((c) => (
                <SubSection key={c} title={categoryLabel(c)}>
                  <Field
                    id={`gdpr-cat-${c}-name`}
                    label={t(ui('category_name'))}
                    help={
                      t(ui('default_hint')) +
                      ' ' +
                      props.defaults.categories[c].name
                    }
                  >
                    <input
                      id={`gdpr-cat-${c}-name`}
                      className="field w-full"
                      value={d.categories[c]?.[language]?.name ?? ''}
                      disabled={disabled}
                      onChange={(e) => setCategory(c, 'name', e.target.value)}
                    />
                  </Field>
                  <Field
                    id={`gdpr-cat-${c}-description`}
                    label={t(ui('category_description'))}
                    help={
                      t(ui('default_hint')) +
                      ' ' +
                      props.defaults.categories[c].description
                    }
                  >
                    <textarea
                      id={`gdpr-cat-${c}-description`}
                      className="field min-h-24 w-full"
                      value={d.categories[c]?.[language]?.description ?? ''}
                      disabled={disabled}
                      onChange={(e) =>
                        setCategory(c, 'description', e.target.value)
                      }
                    />
                  </Field>
                </SubSection>
              ))}
            </Section>
          )}

          {tab === 'cookies' && (
            <Section
              iconKey="cookie"
              title={t(ui('tab_cookies'))}
              description={t(ui('cookies_help'))}
            >
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="text-slate-600">
                    <tr>
                      {[
                        'cookie_name',
                        'cookie_category',
                        'cookie_provider',
                        'cookie_expiry',
                        'cookie_purpose',
                      ].map((k) => (
                        <th key={k} className="py-2 pr-3 font-medium">
                          {t(ui(k))}
                        </th>
                      ))}
                      <th>
                        <span className="sr-only">{t('common.actions')}</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {d.cookies.map((c, i) => (
                      <tr key={c.id ?? 'new-' + i} className="align-top">
                        <td className="py-1 pr-3">
                          <input
                            aria-label={t(ui('cookie_name'))}
                            className="field w-40"
                            value={c.name}
                            disabled={disabled}
                            onChange={(e) =>
                              updateCookie(i, { name: e.target.value })
                            }
                          />
                          {error(`cookies.${i}.name`) && (
                            <p role="alert" className="text-sm text-red-700">
                              {error(`cookies.${i}.name`)}
                            </p>
                          )}
                        </td>
                        <td className="py-1 pr-3">
                          <Choice
                            id={'gdpr-cookie-category-' + i}
                            className="w-40"
                            value={c.category}
                            disabled={disabled}
                            options={CATEGORIES.map((value) => ({
                              value,
                              label: categoryLabel(value),
                            }))}
                            onChange={(category) =>
                              updateCookie(i, { category })
                            }
                          />
                        </td>
                        <td className="py-1 pr-3">
                          <input
                            aria-label={t(ui('cookie_provider'))}
                            className="field w-32"
                            value={c.provider}
                            disabled={disabled}
                            onChange={(e) =>
                              updateCookie(i, { provider: e.target.value })
                            }
                          />
                        </td>
                        <td className="py-1 pr-3">
                          <input
                            aria-label={t(ui('cookie_expiry'))}
                            className="field w-28"
                            value={c.expiries[language] ?? ''}
                            disabled={disabled}
                            onChange={(e) =>
                              updateCookie(i, {
                                expiries: {
                                  ...c.expiries,
                                  [language]: e.target.value,
                                },
                              })
                            }
                          />
                        </td>
                        <td className="py-1 pr-3">
                          <input
                            aria-label={t(ui('cookie_purpose'))}
                            className="field w-full min-w-56"
                            value={c.purposes[language] ?? ''}
                            disabled={disabled}
                            onChange={(e) =>
                              updateCookie(i, {
                                purposes: {
                                  ...c.purposes,
                                  [language]: e.target.value,
                                },
                              })
                            }
                          />
                        </td>
                        <td className="py-1">
                          {!disabled && (
                            <button
                              type="button"
                              className="button-secondary"
                              onClick={() =>
                                form.setData(
                                  'cookies',
                                  d.cookies.filter((_, n) => n !== i),
                                )
                              }
                            >
                              {t(ui('remove'))}
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!disabled && (
                <button
                  type="button"
                  className="button-secondary"
                  onClick={() =>
                    form.setData('cookies', [
                      ...d.cookies,
                      {
                        id: null,
                        category: 'statistics',
                        name: '',
                        provider: '',
                        purposes: {},
                        expiries: {},
                      },
                    ])
                  }
                >
                  {t(ui('add_cookie'))}
                </button>
              )}
            </Section>
          )}
        </form>

        {tab === 'scripts' && (
          <Section
            iconKey="code"
            title={t(ui('tab_scripts'))}
            description={t(ui('scripts_renew_note'))}
          >
            {!props.canScripts ? (
              <p className="text-sm text-slate-600">
                {t(ui('scripts_locked'))}
              </p>
            ) : (
              <form
                className="space-y-6"
                onSubmit={(e) => {
                  e.preventDefault();
                  scripts.put(base + '/scripts', { preserveScroll: true });
                }}
              >
                <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                  {t(ui('scripts_intro'))}
                </p>
                <FormErrors errors={scripts.errors} />
                {scripts.data.scripts.map((s, i) => {
                  const update = (patch: Partial<Script>) =>
                    scripts.setData(
                      'scripts',
                      scripts.data.scripts.map((x, n) =>
                        n === i ? { ...x, ...patch } : x,
                      ),
                    );
                  return (
                    <SubSection
                      key={s.id ?? 'new-' + i}
                      title={s.name || t(ui('add_script'))}
                    >
                      <Field
                        id={`gdpr-script-${i}-name`}
                        label={t(ui('script_name'))}
                      >
                        <input
                          id={`gdpr-script-${i}-name`}
                          className="field w-full"
                          value={s.name}
                          onChange={(e) => update({ name: e.target.value })}
                        />
                      </Field>
                      <Field
                        id={`gdpr-script-${i}-category`}
                        label={t(ui('script_category'))}
                      >
                        <Choice
                          id={`gdpr-script-${i}-category`}
                          className="w-full"
                          value={s.category}
                          options={OPTIONAL.map((value) => ({
                            value,
                            label: categoryLabel(value),
                          }))}
                          onChange={(category) => update({ category })}
                        />
                      </Field>
                      <Field
                        id={`gdpr-script-${i}-code`}
                        label={t(ui('script_code'))}
                        error={
                          (scripts.errors as Record<string, string>)[
                            `scripts.${i}.code`
                          ]
                        }
                      >
                        <textarea
                          id={`gdpr-script-${i}-code`}
                          className="field min-h-40 w-full font-mono text-xs"
                          spellCheck={false}
                          value={s.code}
                          onChange={(e) => update({ code: e.target.value })}
                        />
                      </Field>
                      <div className="flex flex-wrap items-center justify-between gap-4">
                        <CheckField
                          id={`gdpr-script-${i}-enabled`}
                          label={t(ui('script_enabled'))}
                          checked={s.enabled}
                          onChange={(enabled) => update({ enabled })}
                        />
                        <button
                          type="button"
                          className="button-secondary text-red-700"
                          onClick={() =>
                            scripts.setData(
                              'scripts',
                              scripts.data.scripts.filter((_, n) => n !== i),
                            )
                          }
                        >
                          {t(ui('remove'))}
                        </button>
                      </div>
                    </SubSection>
                  );
                })}
                <div className="flex flex-wrap items-center gap-3">
                  <button
                    type="button"
                    className="button-secondary"
                    onClick={() =>
                      scripts.setData('scripts', [
                        ...scripts.data.scripts,
                        {
                          id: null,
                          category: 'statistics',
                          name: '',
                          code: '',
                          enabled: true,
                        },
                      ])
                    }
                  >
                    {t(ui('add_script'))}
                  </button>
                  <button
                    className="button-primary"
                    disabled={scripts.processing}
                  >
                    {t(ui('save_scripts'))}
                  </button>
                </div>
              </form>
            )}
          </Section>
        )}

        {tab === 'consents' && (
          <Section
            iconKey="security"
            title={t(ui('tab_consents'))}
            description={t(ui('consents_help'))}
          >
            <dl className="grid gap-3 sm:grid-cols-4">
              <div className="rounded-lg border border-slate-200 p-3">
                <dt className="text-xs text-slate-500">
                  {t(ui('consents_total'))}
                </dt>
                <dd className="text-xl font-semibold">
                  {props.consents.total}
                </dd>
              </div>
              {['accept_all', 'reject_all', 'custom'].map((action) => (
                <div
                  key={action}
                  className="rounded-lg border border-slate-200 p-3"
                >
                  <dt className="text-xs text-slate-500">
                    {t(ui('action_' + action))} · {t(ui('consents_recent'))}
                  </dt>
                  <dd className="text-xl font-semibold">
                    {props.consents.recent[action] ?? 0}
                  </dd>
                </div>
              ))}
            </dl>
            <form
              className="border-t border-slate-200 pt-6"
              onSubmit={(e) => {
                e.preventDefault();
                router.get(
                  base,
                  { consent: lookup.trim() },
                  { preserveScroll: true },
                );
              }}
            >
              <Field
                id="gdpr-lookup"
                label={t(ui('lookup'))}
                help={t(ui('lookup_help'))}
              >
                <div className="flex gap-3">
                  <input
                    id="gdpr-lookup"
                    className="field min-w-0 flex-1 font-mono text-xs"
                    value={lookup}
                    onChange={(e) => setLookup(e.target.value)}
                  />
                  <button className="button-secondary">
                    {t(ui('lookup_button'))}
                  </button>
                </div>
              </Field>
            </form>
            {props.lookup &&
              (props.lookup.records.length === 0 ? (
                <p className="text-sm text-slate-600">{t(ui('lookup_none'))}</p>
              ) : (
                <table className="w-full text-left text-sm">
                  <thead className="text-slate-600">
                    <tr>
                      {['date', 'version'].map((k) => (
                        <th key={k} className="py-2 pr-3 font-medium">
                          {t(ui(k))}
                        </th>
                      ))}
                      {OPTIONAL.map((c) => (
                        <th key={c} className="py-2 pr-3 font-medium">
                          {categoryLabel(c)}
                        </th>
                      ))}
                      <th className="py-2 font-medium">
                        {t(ui('tab_consents'))}
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-200">
                    {props.lookup.records.map((r, i) => (
                      <tr key={i}>
                        <td className="py-2 pr-3">
                          {new Date(r.created_at).toLocaleString()}
                        </td>
                        <td className="py-2 pr-3">{r.version}</td>
                        {OPTIONAL.map((c) => (
                          <td key={c} className="py-2 pr-3">
                            {t(
                              ui(
                                r[
                                  c as
                                    'preferences' | 'statistics' | 'marketing'
                                ]
                                  ? 'yes'
                                  : 'no',
                              ),
                            )}
                          </td>
                        ))}
                        <td className="py-2">{t(ui('action_' + r.action))}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ))}
          </Section>
        )}
      </div>
    </AdminLayout>
  );
}
