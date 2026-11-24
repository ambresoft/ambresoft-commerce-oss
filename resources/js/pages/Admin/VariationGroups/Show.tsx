import AdminCard from '../../../components/admin/AdminCard';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { useUrlTab } from '../../../lib/useUrlTab';
import Switch from '../../../components/admin/Switch';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import { SearchField } from '../../../components/admin/list/FilterBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge, {
  type Tone,
} from '../../../components/admin/list/StatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type {
  TransferTarget,
  VariationCandidate,
  VariationGroupDetail,
  VariationImpact,
  VariationMember,
} from '../../../types/variations';

const statusTone: Record<string, Tone> = {
  active: 'success',
  disabled: 'neutral',
  hidden: 'warning',
};

type Props = AdminShell & {
  group: VariationGroupDetail;
  candidates: VariationCandidate[];
  targets: TransferTarget[];
  availableFeatures: { id: string; name: string }[];
  impactPreview: VariationImpact;
  candidateSearch: string;
};

export default function VariationGroupShow(props: Props) {
  // A new version (after any change) remounts local form state.
  return <Group key={props.group.version} {...props} />;
}

function Card({
  id,
  title,
  children,
  action,
  iconKey,
}: {
  id: string;
  title: string;
  iconKey: string;
  children: ReactNode;
  action?: ReactNode;
}) {
  return (
    <AdminCard
      id={id}
      title={title}
      iconKey={iconKey}
      action={action}
      bodyClassName="p-0"
    >
      {children}
    </AdminCard>
  );
}

function Group({
  group,
  candidates,
  targets,
  availableFeatures,
  impactPreview,
  candidateSearch,
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities, errors, i18n } = usePage<
    SharedProps & { errors?: Record<string, string> }
  >().props;
  const [contentLocale, setContentLocale] = useState(
    i18n.locales.some((l) => l.code === i18n.locale)
      ? i18n.locale
      : (i18n.locales[0]?.code ?? ''),
  );
  const canEdit = !!capabilities['products.update'];
  const canCreate = !!capabilities['products.create'];
  const base = '/admin/variation-groups/' + group.id;
  const rename = useForm({
    version: group.version,
    name: group.name,
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        group.translations?.[l.code] ?? { name: '' },
      ]),
    ),
  });
  useDirtyForm(rename);
  const listing = useForm({
    version: group.version,
    single_carousel: !!group.single_carousel,
    single_listing: !!group.single_listing,
    single_search: !!group.single_search,
    view_product: !!group.view_product,
    color_swatches: !!group.color_swatches,
    clickable_colors: !!group.clickable_colors,
    card_features: group.card_features ?? [],
    card_labels:
      group.card_labels ?? ({} as Record<string, Record<string, string>>),
  });
  useDirtyForm(listing);
  const [order, setOrder] = useState(group.features.map((f) => f.id));
  const [proposed, setProposed] = useState<string[]>(
    impactPreview?.features ?? group.features.map((f) => f.id),
  );
  const [search, setSearch] = useState(candidateSearch);
  const productTabs = canEdit
    ? (['members', 'add', 'listing'] as const)
    : (['members', 'listing'] as const);
  const [productTab, setProductTab] = useUrlTab(productTabs);
  const [chosen, setChosen] = useState<string[]>([]);
  const [source, setSource] = useState(group.members[0]?.id ?? '');
  const [combination, setCombination] = useState<Record<string, string>>(() =>
    Object.fromEntries(
      group.features.map((f) => [
        f.id,
        group.members[0]?.values[f.id]?.id ?? '',
      ]),
    ),
  );
  const [targetFor, setTargetFor] = useState<Record<string, string>>({});
  const post = (url: string, data: Record<string, unknown>) =>
    router.post(url, data as never, { preserveScroll: true });

  // Reserved = any current member's combination, including Disabled/Hidden products.
  const reservedBy = group.members.find((m) =>
    group.features.every((f) => m.values[f.id]?.id === combination[f.id]),
  );
  const pickSource = (member: VariationMember) => {
    setSource(member.id);
    setCombination(
      Object.fromEntries(
        group.features.map((f) => [f.id, member.values[f.id]?.id ?? '']),
      ),
    );
  };
  const move = (index: number, by: number) => {
    const next = [...order];
    [next[index], next[index + by]] = [next[index + by], next[index]];
    setOrder(next);
  };
  const featureName = (id: string) =>
    group.features.find((f) => f.id === id)?.name ??
    availableFeatures.find((f) => f.id === id)?.name ??
    '#' + id;
  const errorList = Object.entries(errors ?? {});

  return (
    <AdminLayout title={group.name} {...shell}>
      <EditorHeader
        heading={group.name}
        iconKey="variations"
        backHref="/admin/variation-groups"
        backLabel={t('variations.back')}
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              #{group.id} · {group.category.join(' › ') || '—'}
              {group.type ? ' · ' + t('products.' + group.type) : ''}
            </p>
          </>
        }
        actions={<></>}
        menu={[
          canEdit && (
            <button
              type="button"
              className="button-secondary gap-2 text-red-700"
              onClick={() => {
                if (
                  window.confirm(
                    t('variations.confirm_disband', {
                      name: group.name,
                      count: group.members.length,
                    }),
                  )
                )
                  router.delete(base, { data: { version: group.version } });
              }}
            >
              <Icon name="trash" />
              {t('variations.disband')}
            </button>
          ),
        ]}
      />

      {errorList.length > 0 && (
        <div className="mb-5">
          <FormErrors errors={Object.fromEntries(errorList)} />
        </div>
      )}
      {group.problems.length > 0 && (
        <div
          role="alert"
          className="mb-5 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
        >
          <p className="font-medium">{t('variations.group_problems')}</p>
          <ul className="mt-1 list-inside list-disc">
            {group.problems.map((p) => (
              <li key={p}>{p}</li>
            ))}
          </ul>
        </div>
      )}

      <AdminTabs
        label={t('variations.members')}
        language={
          <ContentLanguageSelector
            languages={i18n.locales}
            value={contentLocale}
            onChange={setContentLocale}
            errors={errors}
          />
        }
      >
        {productTabs.map((tab) => (
          <AdminTab
            key={tab}
            type="button"
            role="tab"
            id={'variation-products-tab-' + tab}
            aria-selected={productTab === tab}
            aria-controls={'variation-products-panel-' + tab}
            tabIndex={productTab === tab ? 0 : -1}
            onClick={() => setProductTab(tab)}
            iconKey={tab}
          >
            {t(
              tab === 'members'
                ? 'variations.members'
                : tab === 'add'
                  ? 'variations.add_products'
                  : 'variations.single_listing',
            )}
          </AdminTab>
        ))}
      </AdminTabs>

      <div hidden={productTab === 'listing'}>
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
          <div className="min-w-0 space-y-6">
            <div
              id="variation-products-panel-members"
              role="tabpanel"
              aria-labelledby="variation-products-tab-members"
              hidden={productTab !== 'members'}
              className="space-y-6"
            >
              <Card
                id="members-heading"
                title={t('variations.members')}
                iconKey="products"
              >
                <div className="overflow-x-auto">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>{t('common.name')}</th>
                        <th>{t('products.sku')}</th>
                        <th>{t('common.status')}</th>
                        {group.features.map((f) => (
                          <th key={f.id}>{f.name}</th>
                        ))}
                        <th className="text-right">{t('common.actions')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {group.members.map((m) => (
                        <tr key={m.id}>
                          <td className="min-w-48">
                            <Link
                              className="font-semibold text-slate-900 hover:underline"
                              href={'/admin/products/' + m.id + '/edit'}
                            >
                              {m.name}
                            </Link>
                            <p className="text-xs text-slate-500">#{m.id}</p>
                            {m.problems.map((p) => (
                              <p
                                key={p}
                                className="mt-0.5 text-xs text-red-700"
                              >
                                {p}
                              </p>
                            ))}
                          </td>
                          <td className="text-slate-500">{m.sku ?? '—'}</td>
                          <td>
                            <StatusBadge
                              tone={statusTone[m.status] ?? 'neutral'}
                            >
                              {t('products.' + m.status)}
                            </StatusBadge>
                          </td>
                          {group.features.map((f) => (
                            <td key={f.id} className="whitespace-nowrap">
                              {m.values[f.id]?.label ?? '—'}
                            </td>
                          ))}
                          <td>
                            {canEdit && (
                              <div className="flex flex-wrap items-center justify-end gap-2">
                                {targets.length > 0 && (
                                  <>
                                    <div className="w-40">
                                      <Select
                                        aria-label={t(
                                          'variations.transfer_target',
                                          { product: m.name },
                                        )}
                                        value={targetFor[m.id] ?? ''}
                                        onChange={(value) =>
                                          setTargetFor({
                                            ...targetFor,
                                            [m.id]: value,
                                          })
                                        }
                                        options={[
                                          {
                                            value: '',
                                            label: t('variations.transfer_to'),
                                          },
                                          ...targets.map((g) => ({
                                            value: g.id,
                                            label: g.name + ' (#' + g.id + ')',
                                          })),
                                        ]}
                                      />
                                    </div>
                                    <button
                                      type="button"
                                      className="button-secondary"
                                      disabled={!targetFor[m.id]}
                                      onClick={() => {
                                        const target = targets.find(
                                          (g) => g.id === targetFor[m.id],
                                        );
                                        if (
                                          target &&
                                          window.confirm(
                                            t('variations.confirm_transfer', {
                                              product: m.name,
                                              from: group.name,
                                              to: target.name,
                                            }),
                                          )
                                        )
                                          post(
                                            base +
                                              '/members/' +
                                              m.id +
                                              '/transfer',
                                            {
                                              version: group.version,
                                              product_version: m.version,
                                              target: target.id,
                                              target_version: target.version,
                                            },
                                          );
                                      }}
                                    >
                                      {t('variations.transfer')}
                                    </button>
                                  </>
                                )}
                                {canCreate && (
                                  <IconButton
                                    icon="copy"
                                    label={t('variations.variation_from', {
                                      product: m.name,
                                    })}
                                    onClick={() => {
                                      pickSource(m);
                                      document
                                        .getElementById('variation-heading')
                                        ?.scrollIntoView({
                                          behavior: 'smooth',
                                        });
                                    }}
                                  />
                                )}
                                <IconButton
                                  icon="ban"
                                  danger
                                  label={t('variations.remove_member', {
                                    product: m.name,
                                  })}
                                  onClick={() => {
                                    if (
                                      window.confirm(
                                        t('variations.confirm_remove', {
                                          product: m.name,
                                          group: group.name,
                                        }),
                                      )
                                    )
                                      router.delete(base + '/members/' + m.id, {
                                        data: {
                                          version: group.version,
                                          product_version: m.version,
                                        },
                                        preserveScroll: true,
                                      });
                                  }}
                                />
                              </div>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                {group.pending.length > 0 && (
                  <div className="border-t border-slate-200 px-5 py-4 text-sm">
                    <p className="font-medium">
                      {t('variations.pending_title')}
                    </p>
                    <p className="text-slate-500">
                      {t('variations.pending_help')}
                    </p>
                    <ul className="mt-2 space-y-1">
                      {group.pending.map((p) => (
                        <li key={p.id}>
                          <Link
                            className="text-link"
                            href={'/admin/products/' + p.id + '/edit'}
                          >
                            {p.name}
                          </Link>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </Card>

              {canCreate && group.members.length > 0 && (
                <Card
                  id="variation-heading"
                  title={t('variations.create_variation')}
                  iconKey="variations"
                >
                  <form
                    className="space-y-4 p-5"
                    onSubmit={(e) => {
                      e.preventDefault();
                      const member = group.members.find((m) => m.id === source);
                      if (member && !reservedBy)
                        post(base + '/variations', {
                          version: group.version,
                          source: member.id,
                          source_version: member.version,
                          options: combination,
                        });
                    }}
                  >
                    <p className="text-sm text-slate-500">
                      {t('variations.create_variation_help')}
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <div>
                        <label
                          className="mb-1 block text-sm font-medium"
                          htmlFor="variation-source"
                        >
                          {t('variations.source')}
                        </label>
                        <Select
                          id="variation-source"
                          value={source}
                          onChange={(id) => {
                            const member = group.members.find(
                              (m) => m.id === id,
                            );
                            if (member) pickSource(member);
                          }}
                          options={group.members.map((m) => ({
                            value: m.id,
                            label: m.name + (m.sku ? ' (' + m.sku + ')' : ''),
                          }))}
                        />
                      </div>
                      {group.features.map((f) => (
                        <div key={f.id}>
                          <label
                            className="mb-1 block text-sm font-medium"
                            htmlFor={'variation-' + f.id}
                          >
                            {f.name}
                          </label>
                          <Select
                            id={'variation-' + f.id}
                            value={combination[f.id] ?? ''}
                            onChange={(value) =>
                              setCombination({ ...combination, [f.id]: value })
                            }
                            options={f.options
                              .filter((o) => o.status === 'active')
                              .map((o) => ({ value: o.id, label: o.label }))}
                          />
                        </div>
                      ))}
                    </div>
                    {reservedBy ? (
                      <p role="alert" className="text-sm text-red-700">
                        {t('variations.reserved_by', {
                          product: reservedBy.name,
                        })}
                      </p>
                    ) : (
                      <p className="text-sm text-emerald-700">
                        {t('variations.combination_free')}
                      </p>
                    )}
                    <p className="text-xs text-slate-500">
                      {t('variations.reserved_note')}
                    </p>
                    <button
                      className="button-primary"
                      disabled={!!reservedBy || !source}
                    >
                      {t('variations.create_variation_submit')}
                    </button>
                  </form>
                </Card>
              )}
            </div>
            {canEdit && (
              <div
                id="variation-products-panel-add"
                role="tabpanel"
                aria-labelledby="variation-products-tab-add"
                hidden={productTab !== 'add'}
              >
                <Card
                  id="add-heading"
                  title={t('variations.add_products')}
                  iconKey="products"
                >
                  <div className="space-y-4 p-5">
                    <p className="text-sm text-slate-500">
                      {t('variations.add_help')}
                    </p>
                    <SearchField
                      id="candidate-search"
                      value={search}
                      placeholder={t('products.search_placeholder')}
                      onChange={setSearch}
                      onSubmit={() =>
                        router.reload({
                          data: { candidate_search: search },
                          only: ['candidates', 'candidateSearch'],
                        })
                      }
                    />
                    <ul className="max-h-80 overflow-y-auto divide-y divide-slate-100 rounded-lg border border-slate-200">
                      {candidates.map((c) => (
                        <li
                          key={c.id}
                          className="flex items-start gap-3 px-4 py-3"
                        >
                          <input
                            type="checkbox"
                            className="mt-1 size-4"
                            aria-label={t('variations.select_candidate', {
                              product: c.name,
                            })}
                            checked={chosen.includes(c.id)}
                            onChange={(e) =>
                              setChosen(
                                e.target.checked
                                  ? [...chosen, c.id]
                                  : chosen.filter((id) => id !== c.id),
                              )
                            }
                          />
                          <div className="min-w-0 flex-1">
                            <Link
                              className="font-semibold hover:underline"
                              href={'/admin/products/' + c.id + '/edit'}
                            >
                              {c.name}
                            </Link>
                            <p className="text-xs text-slate-500">
                              {[
                                c.sku,
                                t('products.' + c.status),
                                c.values.join(' / '),
                              ]
                                .filter(Boolean)
                                .join(' · ')}
                            </p>
                            {c.problems.map((p) => (
                              <p
                                key={p}
                                className="mt-0.5 text-xs text-red-700"
                              >
                                {p}
                              </p>
                            ))}
                          </div>
                        </li>
                      ))}
                      {candidates.length === 0 && (
                        <li className="px-4 py-6 text-center text-sm text-slate-500">
                          {t('variations.no_candidates')}
                        </li>
                      )}
                    </ul>
                    <button
                      type="button"
                      className="button-primary"
                      disabled={chosen.length === 0}
                      onClick={() =>
                        post(base + '/members', {
                          version: group.version,
                          products: candidates
                            .filter((c) => chosen.includes(c.id))
                            .map((c) => ({ id: c.id, version: c.version })),
                        })
                      }
                    >
                      {t('variations.add_selected', { count: chosen.length })}
                    </button>
                  </div>
                </Card>
              </div>
            )}
          </div>

          <aside className="space-y-6">
            <Card
              id="readiness-heading"
              title={t('variations.readiness')}
              iconKey="check"
            >
              <dl className="grid grid-cols-2 gap-4 p-5">
                <div>
                  <dt className="text-sm text-slate-500">
                    {t('variations.total_members')}
                  </dt>
                  <dd className="text-2xl font-semibold">
                    {group.readiness.total}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">
                    {t('variations.available_members')}
                  </dt>
                  <dd className="text-2xl font-semibold">
                    {group.readiness.available}
                  </dd>
                </div>
              </dl>
              <p className="px-5 pb-5 text-sm text-slate-600">
                {t(
                  group.readiness.eligible
                    ? 'variations.switcher_ready'
                    : 'variations.switcher_not_ready',
                )}
              </p>
            </Card>

            {canEdit && (
              <Card
                id="name-heading"
                title={t('variations.name')}
                iconKey="content"
              >
                <form
                  className="space-y-3 p-5"
                  onSubmit={(e) => {
                    e.preventDefault();
                    rename.post(base + '/name', { preserveScroll: true });
                  }}
                >
                  <label
                    htmlFor="rename"
                    className="block text-sm text-slate-500"
                  >
                    {t('variations.name_hint')}
                  </label>
                  <input
                    id="rename"
                    className="field"
                    maxLength={200}
                    value={rename.data.name}
                    onChange={(e) => rename.setData('name', e.target.value)}
                  />
                  <p className="text-sm text-slate-500">
                    {t('variations.storefront_name_help')}
                  </p>
                  {i18n.locales
                    .filter((locale) => locale.code === contentLocale)
                    .map((locale) => (
                      <label
                        className="block space-y-1 text-sm"
                        key={locale.code}
                      >
                        <span>
                          {t('variations.storefront_name')} ({locale.code})
                        </span>
                        <input
                          className="field"
                          maxLength={200}
                          value={rename.data.translations[locale.code].name}
                          onChange={(e) =>
                            rename.setData('translations', {
                              ...rename.data.translations,
                              [locale.code]: { name: e.target.value },
                            })
                          }
                        />
                      </label>
                    ))}
                  <button
                    className="button-secondary"
                    disabled={rename.processing}
                  >
                    {t('common.save')}
                  </button>
                </form>
              </Card>
            )}

            <Card
              id="features-heading"
              title={t('variations.defining_features')}
              iconKey="features"
            >
              <div className="space-y-4 p-5">
                <p className="text-sm text-slate-500">
                  {t('variations.order_help')}
                </p>
                <ol className="space-y-2">
                  {order.map((id, index) => (
                    <li
                      key={id}
                      className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2"
                    >
                      <span className="text-sm font-medium">
                        {index + 1}. {featureName(id)}
                      </span>
                      {canEdit && (
                        <span className="flex gap-1">
                          <button
                            type="button"
                            className="icon-button size-8"
                            aria-label={t('variations.move_up', {
                              feature: featureName(id),
                            })}
                            disabled={index === 0}
                            onClick={() => move(index, -1)}
                          >
                            ↑
                          </button>
                          <button
                            type="button"
                            className="icon-button size-8"
                            aria-label={t('variations.move_down', {
                              feature: featureName(id),
                            })}
                            disabled={index === order.length - 1}
                            onClick={() => move(index, 1)}
                          >
                            ↓
                          </button>
                        </span>
                      )}
                    </li>
                  ))}
                </ol>
                {canEdit && (
                  <button
                    type="button"
                    className="button-secondary"
                    disabled={
                      order.join() === group.features.map((f) => f.id).join()
                    }
                    onClick={() =>
                      post(base + '/order', {
                        version: group.version,
                        features: order,
                      })
                    }
                  >
                    {t('variations.save_order')}
                  </button>
                )}
                {canEdit && (
                  <form
                    className="space-y-3 border-t border-slate-100 pt-4"
                    onSubmit={(e) => {
                      e.preventDefault();
                      post(base + '/features', {
                        version: group.version,
                        features: proposed,
                        ...(impactPreview
                          ? {
                              confirmed: true,
                              impact_token: impactPreview.token,
                            }
                          : {}),
                      });
                    }}
                  >
                    <p className="font-medium">
                      {t('variations.change_features')}
                    </p>
                    <p className="text-sm text-slate-500">
                      {t('variations.change_features_help')}
                    </p>
                    {availableFeatures.map((f) => (
                      <label
                        key={f.id}
                        className="flex items-center gap-2 text-sm"
                      >
                        <input
                          type="checkbox"
                          className="size-4"
                          disabled={!!impactPreview}
                          checked={proposed.includes(f.id)}
                          onChange={(e) =>
                            setProposed(
                              e.target.checked
                                ? [...proposed, f.id]
                                : proposed.filter((id) => id !== f.id),
                            )
                          }
                        />
                        {f.name}
                      </label>
                    ))}
                    {impactPreview && (
                      <div
                        role="status"
                        className="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
                      >
                        <p className="font-medium">
                          {t('variations.impact_title', {
                            count: impactPreview.impact.members,
                          })}
                        </p>
                        {impactPreview.impact.added.length > 0 && (
                          <p>
                            {t('variations.impact_added', {
                              features: impactPreview.impact.added.join(', '),
                            })}
                          </p>
                        )}
                        {impactPreview.impact.removed.length > 0 && (
                          <p>
                            {t('variations.impact_removed', {
                              features: impactPreview.impact.removed.join(', '),
                            })}
                          </p>
                        )}
                        <ul className="list-inside list-disc">
                          {impactPreview.impact.combinations.map((c) => (
                            <li key={c.product}>
                              {c.product}: {c.values}
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}
                    <div className="flex flex-wrap gap-2">
                      <button
                        className="button-primary"
                        disabled={proposed.length === 0}
                      >
                        {t(
                          impactPreview
                            ? 'variations.confirm_change'
                            : 'variations.preview_change',
                        )}
                      </button>
                      {impactPreview && (
                        <button
                          type="button"
                          className="button-secondary"
                          onClick={() => router.reload()}
                        >
                          {t('common.cancel')}
                        </button>
                      )}
                    </div>
                  </form>
                )}
              </div>
            </Card>
          </aside>
        </div>
      </div>
      <div
        id="variation-products-panel-listing"
        role="tabpanel"
        aria-labelledby="variation-products-tab-listing"
        hidden={productTab !== 'listing'}
      >
        <form
          className="list-card space-y-6 p-5 sm:p-6"
          aria-label={t('variations.single_listing')}
          onSubmit={(event) => {
            event.preventDefault();
            listing.post(base + '/listing', { preserveScroll: true });
          }}
        >
          <div className="max-w-3xl">
            <h2 className="text-lg font-semibold">
              {t('variations.single_listing')}
            </h2>
            <p className="mt-1 text-sm leading-6 text-slate-500">
              {t('variations.single_listing_help')}
            </p>
          </div>
          <fieldset className="min-w-0 space-y-3">
            <legend className="mb-3 text-sm font-semibold">
              {t('variations.display_locations')}
            </legend>
            <div className="grid gap-3 sm:grid-cols-3">
              {(
                ['single_carousel', 'single_listing', 'single_search'] as const
              ).map((field) => (
                <div
                  key={field}
                  className="flex items-center justify-between gap-4 rounded-lg border border-slate-200 px-4 py-4"
                >
                  <span className="text-sm font-medium">
                    {t('variations.' + field + '_surface')}
                  </span>
                  <Switch
                    checked={listing.data[field]}
                    disabled={!canEdit || listing.processing}
                    label={t('variations.' + field + '_surface')}
                    onChange={(value) => listing.setData(field, value)}
                  />
                </div>
              ))}
            </div>
          </fieldset>
          <div className="grid gap-6 pt-2 lg:grid-cols-2">
            <section
              className="min-w-0 rounded-lg bg-slate-50 p-5"
              aria-labelledby="listing-action-heading"
            >
              <h3 id="listing-action-heading" className="text-sm font-semibold">
                {t('variations.card_action')}
              </h3>
              <p className="mt-1 text-xs leading-5 text-slate-500">
                {t('variations.filtered_cards_help')}
              </p>
              <div className="mt-4 flex items-start justify-between gap-4">
                <div className="min-w-0">
                  <p className="text-sm font-medium">
                    {t('variations.view_product')}
                  </p>
                  <p className="mt-2 text-sm leading-6 text-slate-500">
                    {t('variations.view_product_help')}
                  </p>
                </div>
                <Switch
                  checked={listing.data.view_product}
                  disabled={!canEdit || listing.processing}
                  label={t('variations.view_product')}
                  onChange={(value) => listing.setData('view_product', value)}
                />
              </div>
              <div className="mt-6">
                <label
                  htmlFor="listing-color-display"
                  className="mb-2 block text-sm font-medium"
                >
                  {t('variations.color_display')}
                </label>
                <Select
                  id="listing-color-display"
                  value={listing.data.color_swatches ? 'swatches' : 'text'}
                  disabled={!canEdit || listing.processing}
                  onChange={(value) =>
                    listing.setData('color_swatches', value === 'swatches')
                  }
                  options={[
                    { value: 'text', label: t('variations.color_text') },
                    {
                      value: 'swatches',
                      label: t('variations.color_swatches'),
                    },
                  ]}
                />
                <p className="mt-2 text-sm leading-6 text-slate-500">
                  {t('variations.color_display_help')}
                </p>
                <div className="mt-4 flex items-start justify-between gap-4">
                  <div>
                    <p className="text-sm font-medium">
                      {t('variations.clickable_colors')}
                    </p>
                    <p className="mt-1 text-sm leading-6 text-slate-500">
                      {t('variations.clickable_colors_help')}
                    </p>
                  </div>
                  <Switch
                    checked={listing.data.clickable_colors}
                    disabled={
                      !canEdit ||
                      listing.processing ||
                      !listing.data.color_swatches
                    }
                    label={t('variations.clickable_colors')}
                    onChange={(value) =>
                      listing.setData('clickable_colors', value)
                    }
                  />
                </div>
              </div>
            </section>
            <fieldset className="min-w-0 rounded-lg bg-slate-50 p-5">
              <legend className="sr-only">
                {t('variations.card_features')}
              </legend>
              <p className="text-sm font-semibold">
                {t('variations.card_features')}
              </p>
              <p className="mt-1 text-xs leading-5 text-slate-500">
                {t('variations.filtered_cards_help')}
              </p>
              <p className="mt-3 text-sm leading-6 text-slate-500">
                {t('variations.card_features_help')}
              </p>
              <div className="mt-4 flex flex-wrap gap-2">
                {group.features.map((feature) => (
                  <label
                    key={feature.id}
                    className={
                      'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm ' +
                      (listing.data.card_features.includes(feature.id)
                        ? 'border-primary bg-primary-tint font-medium text-emerald-900'
                        : 'border-slate-200 bg-white text-slate-600')
                    }
                  >
                    <input
                      type="checkbox"
                      disabled={!canEdit || listing.processing}
                      checked={listing.data.card_features.includes(feature.id)}
                      onChange={(event) =>
                        listing.setData(
                          'card_features',
                          event.target.checked
                            ? [...listing.data.card_features, feature.id]
                            : listing.data.card_features.filter(
                                (id) => id !== feature.id,
                              ),
                        )
                      }
                    />
                    {feature.name}
                  </label>
                ))}
              </div>
              {group.features
                .filter((feature) =>
                  listing.data.card_features.includes(feature.id),
                )
                .map((feature) => (
                  <div key={feature.id} className="mt-4 space-y-2">
                    <p className="text-sm font-medium">{feature.name}</p>
                    <div className="grid gap-3 sm:grid-cols-2">
                      {i18n.locales
                        .filter((locale) => locale.code === contentLocale)
                        .map((locale) => (
                          <label
                            key={locale.code}
                            className="block text-xs text-slate-500"
                          >
                            {t('variations.card_label')} ({locale.code})
                            <input
                              type="text"
                              className="field mt-1 w-full"
                              maxLength={100}
                              aria-label={
                                t('variations.card_label') +
                                ' — ' +
                                feature.name +
                                ' (' +
                                locale.code +
                                ')'
                              }
                              disabled={!canEdit || listing.processing}
                              placeholder={feature.name}
                              value={
                                listing.data.card_labels[feature.id]?.[
                                  locale.code
                                ] ?? ''
                              }
                              onChange={(event) =>
                                listing.setData('card_labels', {
                                  ...listing.data.card_labels,
                                  [feature.id]: {
                                    ...listing.data.card_labels[feature.id],
                                    [locale.code]: event.target.value,
                                  },
                                })
                              }
                            />
                          </label>
                        ))}
                    </div>
                  </div>
                ))}
              <p className="mt-3 text-xs leading-5 text-slate-500">
                {t('variations.card_label_help')}
              </p>
            </fieldset>
          </div>
          <FormErrors errors={listing.errors} />
          {canEdit && (
            <div className="flex justify-end pt-2">
              <EditorActionButton
                type="submit"

                disabled={listing.processing || !listing.isDirty}
              >
                {t('common.save')}
              </EditorActionButton>
            </div>
          )}
        </form>
      </div>
    </AdminLayout>
  );
}
