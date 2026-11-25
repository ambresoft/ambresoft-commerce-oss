import { Icon } from '../../../../../resources/js/components/admin/list/IconButton';
import EditorStatusSelect from '../../../../../resources/js/components/admin/EditorStatusSelect';
import EditorActionButton from '../../../../../resources/js/components/admin/EditorActionButton';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '../../../../../resources/js/layouts/admin/AdminLayout';
import ConfirmedAction from '../../../../../resources/js/components/admin/ConfirmedAction';
import EditorHeader from '../../../../../resources/js/components/admin/EditorHeader';
import FormErrors from '../../../../../resources/js/components/admin/FormErrors';
import { useState } from 'react';
import UnderlineTabs from '../../../../../resources/js/components/admin/UnderlineTabs';
import {
  CheckField,
  Choice,
  Field,
  Section,
} from '../../../../../resources/js/components/admin/form/fields';
import { useDirtyForm } from '../../../../../resources/js/lib/useUnsavedChanges';
import { useTranslation } from '../../../../../resources/js/lib/useTranslation';
import { useDateDisplay } from '../../../../../resources/js/lib/useDateDisplay';
import type { AdminShell } from '../../../../../resources/js/types/admin';

type Mapping = { attribute: string; feature: string; fallback: string };
type Feed = {
  id: string | null;
  name: string;
  channel: string;
  cron_password: string;
  status: string;
  scope: string;
  categories: string[];
  locale_id: number | null;
  id_source: string;
  description_source: string;
  description_limit: number | null;
  title_for_empty_description: boolean;
  empty_description: string;
  apply_promotions: boolean;
  use_list_price: boolean;
  discount_label: boolean;
  show_categories: boolean;
  additional_images: boolean;
  in_stock_only: boolean;
  min_price: string | null;
  show_weight: boolean;
  attributes: Mapping[];
};
type Summary = {
  id: string;
  name: string;
  channel: string;
  status: string;
  product_count: number | null;
  generated_at: string | null;
  last_error: string | null;
  url: string;
};
export type ProductFeedsProps = AdminShell & {
  canManage: boolean;
  feeds?: Summary[];
  feed?: Feed;
  urls?: { feed: string; cron: string } | null;
  categories?: { id: string; name: string; parentId: string | null }[];
  languages?: { id: number; name: string }[];
  attributes?: string[];
};

const base = '/admin/addons/ambresoft/product-feeds';
const ui = (key: string) => 'cms-product-feeds::ui.' + key;
type FormTab = 'general' | 'products' | 'prices' | 'mapping';

export default function ProductFeeds(props: ProductFeedsProps) {
  return props.feed ? (
    <FeedForm {...props} feed={props.feed} />
  ) : (
    <FeedList {...props} />
  );
}

function FeedList({
  navigation,
  breadcrumbs,
  feeds = [],
  canManage,
}: ProductFeedsProps) {
  const t = useTranslation();
  const { date, time } = useDateDisplay();
  return (
    <AdminLayout
      title={t(ui('title'))}
      navigation={navigation}
      breadcrumbs={breadcrumbs}
    >
      <EditorHeader
        heading={t(ui('title'))}
        iconKey="code"
        description={<p className="text-sm text-slate-600">{t(ui('intro'))}</p>}
        actions={
          <>
            {canManage && (
              <Link href={base + '/create'} className="button-primary">
                {t(ui('create'))}
              </Link>
            )}
          </>
        }
      />
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        {feeds.length === 0 ? (
          <p className="p-6 text-sm text-slate-600">{t(ui('empty'))}</p>
        ) : (
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 text-slate-600">
              <tr>
                {[
                  'name',
                  'channel',
                  'status',
                  'products_count',
                  'generated_at',
                  'feed_url',
                ].map((key) => (
                  <th key={key} className="px-4 py-3 font-medium">
                    {t(ui(key))}
                  </th>
                ))}
                <th className="px-4 py-3">
                  <span className="sr-only">{t('common.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {feeds.map((feed) => (
                <tr key={feed.id}>
                  <th scope="row" className="px-4 py-3 font-medium">
                    <Link
                      href={base + '/' + feed.id + '/edit'}
                      className="link"
                    >
                      {feed.name}
                    </Link>
                  </th>
                  <td className="px-4 py-3">
                    {t(ui('channel_' + feed.channel))}
                  </td>
                  <td className="px-4 py-3">
                    {t(ui('status_' + feed.status))}
                  </td>
                  <td className="px-4 py-3">{feed.product_count ?? '–'}</td>
                  <td className="px-4 py-3">
                    {feed.generated_at
                      ? date.format(new Date(feed.generated_at)) +
                        ' ' +
                        time.format(new Date(feed.generated_at))
                      : t(ui('never'))}
                    {feed.last_error && (
                      <p
                        role="status"
                        className="mt-1 max-w-xs text-xs text-red-700"
                      >
                        {feed.last_error}
                      </p>
                    )}
                  </td>
                  <td className="px-4 py-3 font-mono text-xs break-all">
                    {feed.url}
                  </td>
                  <td className="px-4 py-3">
                    {canManage && (
                      <div className="flex items-center justify-end gap-2">
                        <button
                          type="button"
                          className="button-secondary shrink-0 gap-2 whitespace-nowrap"
                          onClick={() =>
                            router.post(
                              base + '/' + feed.id + '/generate',
                              {},
                              { preserveScroll: true },
                            )
                          }
                        >
                          <Icon name="restore" className="size-4 shrink-0" />
                          {t(ui('generate'))}
                        </button>
                        <ConfirmedAction
                          url={base + '/' + feed.id}
                          label={t(ui('delete'))}
                          confirmation={t(ui('delete_confirm'))}
                          icon="trash"
                        />
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </AdminLayout>
  );
}

/** Categories in tree order with their depth, for an indented checklist. */
function ordered(categories: NonNullable<ProductFeedsProps['categories']>) {
  const children = new Map<string | null, typeof categories>();
  for (const c of categories) {
    children.set(c.parentId, [...(children.get(c.parentId) ?? []), c]);
  }
  const ids = new Set(categories.map((c) => c.id));
  const out: { id: string; name: string; depth: number }[] = [];
  const walk = (parent: string | null, depth: number) => {
    for (const c of children.get(parent) ?? []) {
      out.push({ id: c.id, name: c.name, depth });
      walk(c.id, depth + 1);
    }
  };
  walk(null, 0);
  // A category whose parent is not public still belongs in the list.
  for (const c of categories) {
    if (c.parentId !== null && !ids.has(c.parentId)) walk(c.parentId, 0);
  }
  return out;
}

function FeedForm({
  navigation,
  breadcrumbs,
  feed,
  urls,
  categories = [],
  languages = [],
  attributes = [],
  canManage,
}: ProductFeedsProps & { feed: Feed }) {
  const t = useTranslation();
  const form = useForm({
    ...feed,
    description_limit:
      feed.description_limit === null ? '' : String(feed.description_limit),
    min_price: feed.min_price ?? '',
  });
  useDirtyForm(form);
  const d = form.data;
  const disabled = !canManage;
  const error = (key: string) => (form.errors as Record<string, string>)[key];
  const check = (key: keyof Feed, help?: string) => (
    <CheckField
      id={'feed-' + key}
      label={t(ui(key))}
      help={help}
      checked={d[key] as boolean}
      disabled={disabled}
      onChange={(value) => form.setData(key, value as never)}
    />
  );
  const [tab, setTab] = useState<FormTab>('general');
  const tabErrors: Record<FormTab, string[]> = {
    general: [
      'name',
      'channel',
      'cron_password',
      'status',
      'scope',
      'categories',
    ],
    products: [
      'id_source',
      'description_source',
      'description_limit',
      'empty_description',
      'locale_id',
    ],
    prices: ['min_price'],
    mapping: ['attributes'],
  };
  const text = (
    key: 'name' | 'cron_password' | 'description_limit' | 'min_price',
    props: {
      help?: string;
      required?: boolean;
      inputMode?: 'numeric' | 'decimal';
    } = {},
  ) => (
    <Field
      id={'feed-' + key}
      label={t(ui(key))}
      help={props.help}
      error={error(key)}
      required={props.required}
    >
      <input
        id={'feed-' + key}
        className="field w-full"
        value={d[key] as string}
        inputMode={props.inputMode}
        disabled={disabled}
        aria-invalid={error(key) ? true : undefined}
        onChange={(e) => form.setData(key, e.target.value)}
      />
    </Field>
  );
  const tree = ordered(categories);
  return (
    <AdminLayout
      title={feed.id === null ? t(ui('new')) : feed.name}
      navigation={navigation}
      breadcrumbs={breadcrumbs}
    >
      <form
        className="space-y-6"
        onSubmit={(event) => {
          event.preventDefault();
          form.transform((data) => ({
            ...data,
            description_limit:
              data.description_limit === ''
                ? null
                : Number(data.description_limit),
            min_price: data.min_price === '' ? null : data.min_price,
          }));
          if (feed.id === null) form.post(base, { preserveScroll: true });
          else form.put(base + '/' + feed.id, { preserveScroll: true });
        }}
      >
        <EditorHeader
          heading={feed.id === null ? t(ui('new')) : feed.name}
          iconKey="code"
          status={
            <EditorStatusSelect
              aria-label={t(ui('status'))}
              aria-required
              value={d.status}
              disabled={disabled}
              invalid={!!form.errors.status}
              onChange={(value) => form.setData('status', value)}
              options={['active', 'disabled'].map((value) => ({
                value,
                label: t(ui('status_' + value)),
              }))}
            />
          }
          backHref={base}
          backLabel={t(ui('back'))}
          actions={
            <>
              {canManage && feed.id !== null && (
                <button
                  type="button"
                  className="button-secondary shrink-0 gap-2 whitespace-nowrap"
                  disabled={form.isDirty}
                  onClick={() =>
                    router.post(
                      base + '/' + feed.id + '/generate',
                      {},
                      { preserveScroll: true },
                    )
                  }
                >
                  <Icon name="restore" className="size-4 shrink-0" />
                  {t(ui('generate'))}
                </button>
              )}
              {canManage && (
                <EditorActionButton disabled={form.processing}>
                  {t(ui('save'))}
                </EditorActionButton>
              )}
            </>
          }
        />
        <UnderlineTabs
          label={feed.id === null ? t(ui('new')) : feed.name}
          idPrefix="feed"
          active={tab}
          onSelect={setTab}
          tabs={(['general', 'products', 'prices', 'mapping'] as FormTab[]).map(
            (key) => ({
              key,
              label: t(ui(key)),
              error: Object.keys(form.errors).some((k) =>
                tabErrors[key].some((f) => k === f || k.startsWith(f + '.')),
              ),
            }),
          )}
        />
        <FormErrors errors={form.errors} />

        <div
          role="tabpanel"
          id={'feed-panel-' + tab}
          aria-labelledby={'feed-tab-' + tab}
        >
          {tab === 'general' && (
            <Section iconKey="content" title={t(ui('general'))}>
              {text('name', { required: true })}
              <Field
                id="feed-channel"
                label={t(ui('channel'))}
                help={t(ui('channel_help'))}
              >
                <Choice
                  id="feed-channel"
                  className="w-full"
                  value={d.channel}
                  disabled={disabled}
                  options={['google', 'meta'].map((value) => ({
                    value,
                    label: t(ui('channel_' + value)),
                  }))}
                  onChange={(value) => form.setData('channel', value)}
                />
              </Field>
              {text('cron_password', {
                help: t(ui('cron_password_help')),
                required: true,
              })}
              <Field
                id="feed-scope"
                label={t(ui('scope'))}
                help={t(ui('scope_help'))}
                error={error('categories')}
              >
                <Choice
                  id="feed-scope"
                  className="w-full"
                  value={d.scope}
                  disabled={disabled}
                  options={['all', 'categories'].map((value) => ({
                    value,
                    label: t(ui('scope_' + value)),
                  }))}
                  onChange={(value) => form.setData('scope', value)}
                />
                {d.scope === 'categories' && (
                  <fieldset className="mt-3 max-h-72 overflow-y-auto rounded-lg border border-slate-200 p-3">
                    <legend className="sr-only">{t(ui('categories'))}</legend>
                    {tree.map((c) => (
                      <label
                        key={c.id}
                        className="flex items-center gap-2 py-1 text-sm"
                        style={{ paddingLeft: c.depth * 20 }}
                      >
                        <input
                          type="checkbox"
                          className="size-4 accent-[var(--color-primary)]"
                          checked={d.categories.includes(c.id)}
                          disabled={disabled}
                          onChange={(e) =>
                            form.setData(
                              'categories',
                              e.target.checked
                                ? [...d.categories, c.id]
                                : d.categories.filter((id) => id !== c.id),
                            )
                          }
                        />
                        {c.name}
                      </label>
                    ))}
                  </fieldset>
                )}
              </Field>

              {urls ? (
                <>
                  <Field
                    id="feed-url"
                    label={t(ui('feed_url'))}
                    help={t(ui('feed_url_help'))}
                  >
                    <input
                      id="feed-url"
                      className="field w-full font-mono text-xs"
                      readOnly
                      value={urls.feed}
                    />
                  </Field>
                  {canManage && (
                    <Field
                      id="feed-cron"
                      label={t(ui('cron'))}
                      help={t(ui('cron_help'))}
                    >
                      <input
                        id="feed-cron"
                        className="field w-full font-mono text-xs"
                        readOnly
                        value={
                          'curl -fsS "' +
                          urls.cron +
                          '?password=' +
                          encodeURIComponent(feed.cron_password) +
                          '"'
                        }
                      />
                    </Field>
                  )}
                </>
              ) : (
                <p className="text-sm text-slate-600">{t(ui('save_first'))}</p>
              )}
            </Section>
          )}

          {tab === 'products' && (
            <Section iconKey="products" title={t(ui('products'))}>
              <Field
                id="feed-id_source"
                label={t(ui('id_source'))}
                help={t(ui('id_help'))}
              >
                <Choice
                  id="feed-id_source"
                  className="w-full"
                  value={d.id_source}
                  disabled={disabled}
                  options={[
                    { value: 'id', label: t(ui('id_product')) },
                    { value: 'sku', label: t(ui('id_sku')) },
                  ]}
                  onChange={(value) => form.setData('id_source', value)}
                />
              </Field>
              <Field
                id="feed-description_source"
                label={t(ui('description_source'))}
              >
                <Choice
                  id="feed-description_source"
                  className="w-full"
                  value={d.description_source}
                  disabled={disabled}
                  options={['full', 'short'].map((value) => ({
                    value,
                    label: t(ui('description_' + value)),
                  }))}
                  onChange={(value) =>
                    form.setData('description_source', value)
                  }
                />
              </Field>
              {text('description_limit', {
                help: t(ui('description_limit_help')),
                inputMode: 'numeric',
              })}
              {check('title_for_empty_description')}
              <Field
                id="feed-empty_description"
                label={t(ui('empty_description'))}
                help={t(ui('empty_description_help'))}
                error={error('empty_description')}
              >
                <textarea
                  id="feed-empty_description"
                  className="field min-h-28 w-full"
                  value={d.empty_description}
                  disabled={disabled}
                  onChange={(e) =>
                    form.setData('empty_description', e.target.value)
                  }
                />
              </Field>
              <Field
                id="feed-locale_id"
                label={t(ui('language'))}
                error={error('locale_id')}
              >
                <Choice
                  id="feed-locale_id"
                  className="w-full"
                  value={d.locale_id === null ? '' : String(d.locale_id)}
                  disabled={disabled}
                  options={[
                    { value: '', label: t(ui('language_default')) },
                    ...languages.map((l) => ({
                      value: String(l.id),
                      label: l.name,
                    })),
                  ]}
                  onChange={(value) =>
                    form.setData(
                      'locale_id',
                      value === '' ? null : Number(value),
                    )
                  }
                />
              </Field>
              {check('show_categories', t(ui('show_categories_help')))}
              {check('additional_images', t(ui('additional_images_help')))}
              {check('in_stock_only')}
              {check('show_weight', t(ui('show_weight_help')))}
            </Section>
          )}

          {tab === 'prices' && (
            <Section
              iconKey="currency"
              title={t(ui('prices'))}
              description={t(ui('prices_help'))}
            >
              {check('apply_promotions', t(ui('apply_promotions_help')))}
              {check('use_list_price', t(ui('use_list_price_help')))}
              {check('discount_label', t(ui('discount_label_help')))}
              {text('min_price', {
                help: t(ui('min_price_help')),
                inputMode: 'decimal',
              })}
            </Section>
          )}

          {tab === 'mapping' && (
            <Section
              iconKey="similar_products"
              title={t(ui('mapping'))}
              description={t(ui('mapping_help'))}
            >
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="text-slate-600">
                    <tr>
                      {['attribute', 'feature', 'fallback'].map((key) => (
                        <th key={key} className="py-2 pr-3 font-medium">
                          {t(ui(key))}
                        </th>
                      ))}
                      <th>
                        <span className="sr-only">{t('common.actions')}</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {d.attributes.map((row, index) => {
                      const update = (patch: Partial<Mapping>) =>
                        form.setData(
                          'attributes',
                          d.attributes.map((r, i) =>
                            i === index ? { ...r, ...patch } : r,
                          ),
                        );
                      return (
                        <tr key={index} className="align-top">
                          <td className="py-1 pr-3">
                            <Choice
                              id={'feed-attribute-' + index}
                              className="w-56"
                              value={row.attribute}
                              disabled={disabled}
                              options={attributes.map((value) => ({
                                value,
                                label: 'g:' + value,
                                disabled:
                                  value !== row.attribute &&
                                  d.attributes.some(
                                    (r) => r.attribute === value,
                                  ),
                              }))}
                              onChange={(value) => update({ attribute: value })}
                            />
                            {error('attributes.' + index + '.attribute') && (
                              <p role="alert" className="text-sm text-red-700">
                                {error('attributes.' + index + '.attribute')}
                              </p>
                            )}
                          </td>
                          <td className="py-1 pr-3">
                            <input
                              aria-label={t(ui('feature'))}
                              className="field w-full"
                              value={row.feature}
                              disabled={disabled}
                              onChange={(e) =>
                                update({ feature: e.target.value })
                              }
                            />
                          </td>
                          <td className="py-1 pr-3">
                            <input
                              aria-label={t(ui('fallback'))}
                              className="field w-full"
                              value={row.fallback}
                              disabled={disabled}
                              aria-invalid={
                                error('attributes.' + index + '.fallback')
                                  ? true
                                  : undefined
                              }
                              onChange={(e) =>
                                update({ fallback: e.target.value })
                              }
                            />
                            {error('attributes.' + index + '.fallback') && (
                              <p role="alert" className="text-sm text-red-700">
                                {error('attributes.' + index + '.fallback')}
                              </p>
                            )}
                          </td>
                          <td className="py-1">
                            {canManage && (
                              <button
                                type="button"
                                className="button-secondary"
                                onClick={() =>
                                  form.setData(
                                    'attributes',
                                    d.attributes.filter((_, i) => i !== index),
                                  )
                                }
                              >
                                {t(ui('remove'))}
                              </button>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              {canManage && d.attributes.length < attributes.length && (
                <button
                  type="button"
                  className="button-secondary"
                  onClick={() => {
                    const free = attributes.find(
                      (a) => !d.attributes.some((r) => r.attribute === a),
                    );
                    if (free)
                      form.setData('attributes', [
                        ...d.attributes,
                        { attribute: free, feature: '', fallback: '' },
                      ]);
                  }}
                >
                  {t(ui('add_attribute'))}
                </button>
              )}
            </Section>
          )}
        </div>
      </form>
    </AdminLayout>
  );
}
