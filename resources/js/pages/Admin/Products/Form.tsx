import type { ReactNode } from 'react';
import DetailCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import AttachmentUploader, {
  removeAttachmentUpload,
} from '../../../components/admin/AttachmentUploader';
import ProductPackageAssignment, {
  type PackageAssignment,
} from '../../../components/admin/ProductPackageAssignment';
import TargetPicker from '../Promotions/TargetPicker';
import ProductCompanionPicker, {
  type CompanionRow,
} from '../../../components/admin/ProductCompanionPicker';
import FacebookTypeField from '../../../components/admin/FacebookTypeField';
import FaqPicker from '../../../components/admin/FaqPicker';
import type { FaqDraft, FaqOption } from '../../../types/faqs';
import DateInput from '../../../components/admin/DateInput';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton, {
  EditorActionLink,
} from '../../../components/admin/EditorActionButton';
import ProductSalesFields from '../../../components/admin/ProductSalesFields';
import ProductFeatureFields from '../../../components/admin/ProductFeatureFields';
import FeatureImpact from '../../../components/admin/FeatureImpact';
import type { FeatureEditor, ImpactPreview } from '../../../types/features';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ExtraCategories from './ExtraCategories';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormField from '../../../components/auth/FormField';
import RequiredMark from '../../../components/admin/RequiredMark';
import FormErrors from '../../../components/admin/FormErrors';
import RichText from '../../../components/admin/RichText';
import { useTranslation } from '../../../lib/useTranslation';
import { useUrlTab } from '../../../lib/useUrlTab';
import type { AdminShell } from '../../../types/admin';
import type { CustomerGroupOption as SaleCustomerGroupOption } from '../../../types/customers';
import type { ProductCurrency } from '../../../types/currencies';
import type { SharedProps } from '../../../types/page';
import PriceHistoryChart, {
  type PricePoint,
} from '../../../components/admin/PriceHistoryChart';
import type {
  FilePurpose,
  ProductEditor,
  ProductFile,
  ProductOption,
  ProductText,
} from '../../../types/products';
import Select from '../../../components/admin/Select';
import BrandSelect from '../../../components/admin/BrandSelect';
import CustomerGroupPicker, {
  type CustomerGroupOption,
} from '../../../components/admin/CustomerGroupPicker';
import ProductVariations from '../../../components/admin/ProductVariations';
import { ImageUploader } from '../../../components/admin/ImageUploader';
import VariationMembership, {
  type ProductVariation,
  type VariationNotice,
} from '../../../components/admin/VariationMembership';

type AvailabilityOption = {
  id: string;
  name: string;
  default_in_stock: boolean;
  default_out_of_stock: boolean;
};
type Props = AdminShell & {
  options?: { stickers: ProductOption[]; brands?: ProductOption[] };
  packageAssignment?: PackageAssignment;
  companions?: CompanionRow[];
  companionOptions?: CompanionRow[];
  faqOptions?: FaqOption[];
  facebookTypes?: Record<string, string[]>;
  product: ProductEditor;
  saleCustomerGroups?: SaleCustomerGroupOption[];
  availabilityStatuses?: AvailabilityOption[];
  featureEditor?: FeatureEditor;
  featureImpact?: ImpactPreview;
  defaultLocale: string;
  returnTo: string;
  categories: ProductOption[];
  extraCategoryOptions?: { id: string; name: string }[];
  units: { weight: string; dimension: string };
  currency: ProductCurrency;
  conversions: { code: string; formatted: string }[];
  customerGroups: CustomerGroupOption[];
  priceHistory: PricePoint[];
  variation?: ProductVariation;
  variationNotice?: VariationNotice;
};
const emptyText: ProductText = {
  name: '',
  description: '',
  short_description: '',
  seo_title: '',
  seo_description: '',
  meta_keywords: '',
  slug: '',
};
const tabs = [
  'general',
  'pricing',
  'features',
  'variations',
  'shipping',
  'categories',
  'media',
  'seo',
  'attachments',
  'additional',
  'similar_products',
  'bought_together',
  'faqs',
  'stickers',
  'sales',
  'price_history',
] as const;
type Tab = (typeof tabs)[number];
const tabOf = (key: string): Tab =>
  key.startsWith('companion')
    ? 'bought_together'
    : key === 'facebook_type' ||
        key === 'exclude_from_sitemap' ||
        key === 'reviews_enabled'
      ? 'additional'
      : key === 'similar_products'
        ? 'similar_products'
        : key.startsWith('sticker')
          ? 'stickers'
          : key.startsWith('sales')
            ? 'sales'
            : key.startsWith('faq_')
              ? 'faqs'
              : key.startsWith('variation_groups')
                ? 'variations'
                : key.startsWith('features')
                  ? 'features'
                  : /slug|seo_|meta_keywords/.test(key)
                    ? 'seo'
                    : /^(files|file_)/.test(key)
                      ? 'attachments'
                      : /images|image_/.test(key)
                        ? 'media'
                        : key === 'category_id'
                          ? 'categories'
                          : /^(free_shipping|shipping_cost|box_)/.test(key)
                            ? 'shipping'
                            : /^(price|list_price|quantity|in_stock_status_id|out_of_stock_status_id|min_qty|max_qty|qty_step|out_of_stock_actions|available_from|tax_ids(\..+)?|customer_groups(\..+)?)$/.test(
                                  key,
                                )
                              ? 'pricing'
                              : 'general';
function ProductTabCard({
  tab,
  title,
  children,
}: {
  tab: string;
  title: string;
  children: ReactNode;
}) {
  return ['bought_together', 'features', 'variations'].includes(tab) ? (
    <>{children}</>
  ) : (
    <DetailCard title={title} iconKey={tab}>
      <div className="space-y-6">{children}</div>
    </DetailCard>
  );
}

export default function ProductForm(props: Props) {
  return (
    <Editor key={props.product.id + ':' + props.product.version} {...props} />
  );
}

function Editor({
  product,
  defaultLocale,
  returnTo,
  categories,
  extraCategoryOptions = [],
  units,
  currency,
  conversions,
  customerGroups,
  faqOptions = [],
  facebookTypes = {},
  options = { stickers: [] },
  packageAssignment,
  companions = [],
  companionOptions = [],
  saleCustomerGroups,
  availabilityStatuses = [],
  priceHistory,
  featureEditor,
  featureImpact,
  variation,
  variationNotice,
  ...shell
}: Props) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const general = product.general_out_of_stock_action;
  const [tab, setTab] = useUrlTab(
    tabs.filter(
      (item) =>
        (item !== 'faqs' || capabilities['faqs.view']) &&
        (item !== 'stickers' || capabilities['stickers.view']),
    ),
  );
  const [confirmedFeatureToken, setConfirmedFeatureToken] = useState('');
  const featureConfirmed =
    !!featureImpact && confirmedFeatureToken === featureImpact.token;
  const setFeatureConfirmed = (value: boolean) =>
    setConfirmedFeatureToken(value ? (featureImpact?.token ?? '') : '');
  const [locale, setLocale] = useState(defaultLocale);
  const categoryTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
  const submitting = useRef(false);
  const [duplicating, setDuplicating] = useState(false);
  const [trashing, setTrashing] = useState(false);
  const form = useForm({
    features: Object.fromEntries(
      Object.entries(featureEditor?.values ?? {}).filter(
        ([id]) =>
          !featureEditor?.definitions.find((f) => f.id === id)?.readonly,
      ),
    ),
    feature_catalog_version: featureEditor?.catalog_version,
    similar_products: product.similar_products ?? true,
    facebook_type: product.facebook_type ?? 'product',
    exclude_from_sitemap: product.exclude_from_sitemap ?? false,
    reviews_enabled: product.reviews_enabled ?? true,
    // Always submitted: multipart encoding drops an empty list, which must still clear the companions.
    companions_submitted: true,
    companion_ids: product.companion_ids ?? [],
    stickers_submitted: !!capabilities['stickers.manage'],
    sticker_ids: capabilities['stickers.manage']
      ? (product.sticker_ids ?? [])
      : undefined,
    faqs_submitted: !!capabilities['faqs.view'],
    faq_ids: capabilities['faqs.view'] ? (product.faq_ids ?? []) : undefined,
    faq_drafts: capabilities['faqs.view'] ? ([] as FaqDraft[]) : undefined,
    version: product.version,
    return_to: returnTo,
    sku: product.sku ?? '',
    ean: product.ean ?? '',
    sales_submitted: true,
    sales: product.sales ?? [],
    mpn: product.mpn ?? '',
    isbn: product.isbn ?? '',
    upc: product.upc ?? '',
    returnable: product.returnable,
    type: product.type,
    status: product.status,
    price: product.price ?? '',
    list_price: product.list_price ?? '',
    quantity: String(product.quantity ?? 0),
    min_qty: product.min_qty === null ? '' : String(product.min_qty),
    max_qty: product.max_qty === null ? '' : String(product.max_qty),
    qty_step: product.qty_step === null ? '' : String(product.qty_step),
    in_stock_status_id: product.in_stock_status_id ?? '',
    out_of_stock_status_id: product.out_of_stock_status_id ?? '',
    out_of_stock_actions: product.out_of_stock_actions ?? '',
    // Always submitted: multipart encoding drops an empty list, which must still clear the assignments.
    taxes_submitted: true,
    tax_ids: product.tax_ids ?? [],
    category_id: product.category_id,
    // Always submitted: multipart encoding drops an empty list, which must still clear the extras.
    extra_categories_submitted: true,
    extra_category_ids: product.extra_category_ids ?? [],
    brand_id: product.brand_id ?? '',
    weight: product.weight ?? '',
    length: product.length ?? '',
    width: product.width ?? '',
    height: product.height ?? '',
    free_shipping: product.free_shipping ?? false,
    shipping_cost: product.shipping_cost ?? '',
    available_from: product.available_from ?? '',
    box_min_items:
      product.box_min_items === null ? '' : String(product.box_min_items),
    box_max_items:
      product.box_max_items === null ? '' : String(product.box_max_items),
    box_length: product.box_length ?? '',
    box_width: product.box_width ?? '',
    box_height: product.box_height ?? '',
    // Always submitted: multipart encoding drops an empty list, which must still clear the restriction.
    customer_groups_submitted: true,
    customer_groups: product.customer_groups ?? [],
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        { ...emptyText, ...product.translations[l.code] },
      ]),
    ),
    images: product.images,
    primary_image: product.primary_image,
    files: product.files,
    image_uploads: [] as File[],
    file_uploads: [] as File[],
    file_upload_purposes: [] as FilePurpose[],
  });
  useDirtyForm(form);
  const errors = form.errors as Record<string, string>;
  const packaging =
    Number(form.data.box_min_items || 0) > 0 ||
    Number(form.data.box_max_items || 0) > 0;
  useEffect(() => {
    const unload = (event: BeforeUnloadEvent) => {
      if (form.isDirty && !submitting.current) event.preventDefault();
    };
    window.addEventListener('beforeunload', unload);
    const stop = router.on('before', (event) => {
      if (
        form.isDirty &&
        !submitting.current &&
        event.detail.visit.url.pathname !== window.location.pathname &&
        !window.confirm(t('products.unsaved'))
      )
        return false;
    });
    return () => {
      window.removeEventListener('beforeunload', unload);
      stop();
    };
  }, [form.isDirty, t]);
  const text = form.data.translations[locale] ?? emptyText;
  const setText = (field: keyof ProductText, value: string | boolean) =>
    form.setData('translations', {
      ...form.data.translations,
      [locale]: { ...text, [field]: value },
    });
  const tabError = (candidate: Tab) =>
    Object.keys(errors).some((key) => tabOf(key) === candidate);
  const move = <T,>(items: T[], index: number, by: number): T[] => {
    const result = [...items];
    const target = index + by;
    if (target >= 0 && target < result.length)
      [result[index], result[target]] = [result[target], result[index]];
    return result;
  };
  const purposeOf = (file: ProductFile) => file.purpose ?? 'download';
  // New uploads carry their purpose by index; a replacement keeps its file's purpose.
  const addUploads = (files: File[], purpose: FilePurpose) =>
    form.setData({
      ...form.data,
      file_uploads: [...form.data.file_uploads, ...files],
      file_upload_purposes: [
        ...form.data.file_upload_purposes,
        ...files.map(() => purpose),
      ],
    });
  /** Swaps a file with its nearest neighbour of the same purpose. */
  const moveFile = (index: number, by: -1 | 1) => {
    const purpose = purposeOf(form.data.files[index]);
    let target = index + by;
    while (
      target >= 0 &&
      target < form.data.files.length &&
      purposeOf(form.data.files[target]) !== purpose
    )
      target += by;
    if (target >= 0 && target < form.data.files.length)
      form.setData('files', move(form.data.files, index, target - index));
  };
  const fileSection = (purpose: FilePurpose) => (
    <AttachmentUploader
      key={purpose}
      title={t(
        purpose === 'download'
          ? 'products.upload_files'
          : 'products.upload_attachments',
      )}
      help={t(
        purpose === 'download'
          ? 'products.downloads_hint'
          : 'products.attachments_hint',
      )}
      items={form.data.files
        .map((file, index) => ({ file, index }))
        .filter(({ file }) => purposeOf(file) === purpose)}
      pending={form.data.file_uploads
        .map((file, index) => ({ file, index }))
        .filter(
          ({ index }) =>
            form.data.file_upload_purposes[index] === purpose &&
            !form.data.files.some((file) => file.replacement === index),
        )}
      required={
        purpose === 'download' &&
        form.data.status === 'active' &&
        !form.data.files.some((file) => purposeOf(file) === 'download')
      }
      disabled={form.processing}
      progress={
        form.processing && form.data.file_uploads.length
          ? form.progress?.percentage
          : undefined
      }
      fieldError={(index) => errors['files.' + index + '.display_name']}
      onAdd={(files) => addUploads(files, purpose)}
      onName={(index, name) =>
        form.setData(
          'files',
          form.data.files.map((file, n) =>
            n === index ? { ...file, display_name: name } : file,
          ),
        )
      }
      onMove={moveFile}
      onRemovePending={(index) =>
        form.setData(removeAttachmentUpload(form.data, index))
      }
      onRemove={(index) => {
        const replacement = form.data.files[index].replacement;
        const data = {
          ...form.data,
          files: form.data.files.filter((_, n) => n !== index),
        };
        form.setData(
          replacement == null
            ? data
            : removeAttachmentUpload(data, replacement),
        );
      }}
      onReplace={(index, file) => {
        const existing = form.data.files[index].replacement;
        const upload = existing ?? form.data.file_uploads.length;
        form.setData({
          ...form.data,
          file_uploads:
            existing == null
              ? [...form.data.file_uploads, file]
              : form.data.file_uploads.map((old, n) =>
                  n === existing ? file : old,
                ),
          file_upload_purposes:
            existing == null
              ? [...form.data.file_upload_purposes, purpose]
              : form.data.file_upload_purposes,
          files: form.data.files.map((old, n) =>
            n === index ? { ...old, replacement: upload } : old,
          ),
        });
      }}
    />
  );
  const error = (key: string) =>
    errors[key] && (
      <p role="alert" className="mt-1 text-sm text-red-700">
        {errors[key]}
      </p>
    );
  const title =
    product.translations[i18n.locale]?.name ||
    product.translations[defaultLocale]?.name ||
    t('products.untitled', { id: product.id });
  return (
    <AdminLayout title={title} {...shell}>
      {trashing && (
        <ConfirmDeleteDialog
          title={t('products.confirm_trash')}
          subtitle={t('products.trash_note')}
          confirmLabel={t('products.trash')}
          facts={[]}
          onCancel={() => setTrashing(false)}
          onConfirm={() => {
            setTrashing(false);
            submitting.current = true;
            router.post(
              '/admin/products/' + product.id + '/action',
              {
                version: product.version,
                action: 'trash',
                return_to: returnTo,
              },
              {
                onFinish: () => {
                  submitting.current = false;
                },
              },
            );
          }}
        />
      )}
      <form
        onSubmit={(event) => {
          event.preventDefault();
          submitting.current = true;
          form.transform((data) => ({
            ...data,
            feature_confirmed: featureConfirmed,
            feature_impact_token: featureImpact?.token ?? null,
          }));
          form.post('/admin/products/' + product.id, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
              submitting.current = false;
            },
          });
        }}
      >
        <EditorHeader
          heading={t('products.edit')}
          title={title}
          image={
            form.data.primary_image
              ? '/admin/product-media/' + form.data.primary_image
              : null
          }
          backHref={returnTo}
          backLabel={t('products.back')}
          status={
            <EditorStatusSelect
              id="product-status"
              aria-label={t('common.status')}
              aria-required
              disabled={!capabilities['products.status']}
              value={form.data.status}
              onChange={(value) => form.setData('status', value)}
              options={['active', 'disabled', 'hidden'].map((status) => ({
                value: status,
                label: t('products.' + status),
              }))}
            />
          }
          metadata={
            <>
              <span>
                {t('products.sku')}: {form.data.sku || '—'}
              </span>
              <span>
                {t('products.type')}: {t('products.' + form.data.type)}
              </span>
            </>
          }
          actions={
            <>
              {product.storefront_url && (
                <EditorActionLink
                  href={product.storefront_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  title={t('products.view_on_storefront')}
                >
                  {t('common.preview')}
                </EditorActionLink>
              )}
              {capabilities['products.create'] && (
                <EditorActionButton
                  type="button"
                  action="duplicate"
                  disabled={form.processing || duplicating || form.isDirty}
                  title={
                    form.isDirty
                      ? t('products.save_before_duplicate')
                      : undefined
                  }
                  onClick={() => {
                    setDuplicating(true);
                    router.post(
                      '/admin/products/' + product.id + '/duplicate',
                      { version: product.version, return_to: returnTo },
                      { onFinish: () => setDuplicating(false) },
                    );
                  }}
                >
                  {t(duplicating ? 'common.wait' : 'products.duplicate')}
                </EditorActionButton>
              )}
              <EditorActionButton disabled={form.processing || duplicating}>
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            </>
          }
          menu={
            capabilities['products.delete'] && (
              <button
                type="button"
                className="text-red-700"
                disabled={form.processing || duplicating}
                onClick={() => setTrashing(true)}
              >
                <Icon name="trash" />
                {t('products.trash')}
              </button>
            )
          }
        />
        {product.incomplete && (
          <p className="text-sm text-amber-700">{t('products.incomplete')}</p>
        )}
        <VariationMembership variation={variation} notice={variationNotice} />

        <AdminTabs
          label={t('products.edit')}
          language={
            <ContentLanguageSelector
              languages={i18n.locales}
              value={locale}
              onChange={setLocale}
              errors={errors}
              missing={i18n.locales
                .filter((l) => !form.data.translations[l.code]?.name)
                .map((l) => l.code)}
            />
          }
        >
          {tabs
            .filter(
              (item) =>
                (item !== 'faqs' || capabilities['faqs.view']) &&
                (item !== 'stickers' || capabilities['stickers.view']),
            )
            .map((item) => (
              <AdminTab
                key={item}
                id={'tab-' + item}
                role="tab"
                type="button"
                aria-selected={tab === item}
                aria-controls={'panel-' + item}
                onClick={() => setTab(item)}
                iconKey={item}
                priority={item === 'features' || item === 'variations'}
                overflow={item === 'sales' || item === 'price_history'}
              >
                {t(
                  item === 'faqs'
                    ? 'faqs.title'
                    : item === 'stickers'
                      ? 'stickers.title'
                      : 'products.' + item,
                )}
                {tabError(item) && (
                  <span
                    className="ml-1 text-red-400"
                    aria-label={t('common.errors')}
                  >
                    ●
                  </span>
                )}
              </AdminTab>
            ))}
        </AdminTabs>

        <p className="mt-4 text-xs text-slate-500">
          {t('common.required_fields')}
        </p>
        <FormErrors errors={errors} />
        <FeatureImpact
          preview={featureImpact ?? null}
          confirmed={featureConfirmed}
          onConfirm={setFeatureConfirmed}
        />
        <div className="mt-4">
          <section
            role="tabpanel"
            id={'panel-' + tab}
            aria-labelledby={'tab-' + tab}
            className="space-y-6"
          >
            <ProductTabCard
              tab={tab}
              title={t(
                tab === 'faqs'
                  ? 'faqs.title'
                  : tab === 'stickers'
                    ? 'stickers.title'
                    : 'products.' + tab,
              )}
            >
              {tab === 'stickers' && capabilities['stickers.view'] && (
                <div className="space-y-5">
                  <p className="mb-5 text-sm text-slate-500">
                    {t('stickers.product_help')}
                  </p>
                  <fieldset disabled={!capabilities['stickers.manage']}>
                    <TargetPicker
                      label={t('stickers.manual')}
                      kind="stickers"
                      values={
                        form.data.sticker_ids ?? product.sticker_ids ?? []
                      }
                      options={options.stickers}
                      path={`/admin/products/${product.id}/edit`}
                      onChange={(ids) => form.setData('sticker_ids', ids)}
                    />
                  </fieldset>
                  <Link
                    className="text-link mt-5 inline-block text-sm"
                    href="/admin/stickers"
                  >
                    {t('stickers.manage')}
                  </Link>
                </div>
              )}
              {tab === 'faqs' && capabilities['faqs.view'] && (
                <FaqPicker
                  locale={locale}
                  ids={form.data.faq_ids ?? []}
                  drafts={form.data.faq_drafts ?? []}
                  options={faqOptions}
                  errors={errors}
                  source={{
                    owner: 'product',
                    object_id: product.id,
                    source_name: form.data.translations[locale]?.name ?? '',
                    source_text:
                      form.data.translations[locale]?.description ?? '',
                  }}
                  onIds={(ids) => form.setData('faq_ids', ids)}
                  onDrafts={(drafts) => form.setData('faq_drafts', drafts)}
                />
              )}
              {tab === 'variations' && (
                <ProductVariations variation={variation} />
              )}
              {tab === 'similar_products' && (
                <div className="space-y-5">
                  <label className="flex items-center gap-3 text-sm font-medium">
                    <input
                      type="checkbox"
                      checked={form.data.similar_products}
                      onChange={(e) =>
                        form.setData('similar_products', e.target.checked)
                      }
                    />
                    {t('products.similar_enabled')}
                  </label>
                  <p className="mt-3 text-sm text-slate-500">
                    {t('products.similar_help')}
                  </p>
                  {error('similar_products')}
                </div>
              )}
              {tab === 'bought_together' && (
                <div className="space-y-6">
                  {packageAssignment && (
                    <ProductPackageAssignment
                      key={packageAssignment.id}
                      product={product.id}
                      value={packageAssignment}
                    />
                  )}
                  <DetailCard
                    id="product-manual-companions"
                    icon="box"
                    title={t('packages.manual_products')}
                  >
                    <p className="mb-5 text-sm leading-6 text-slate-500">
                      {t('products.companions_help')}
                    </p>
                    <ProductCompanionPicker
                      productId={product.id}
                      values={form.data.companion_ids}
                      rows={companions}
                      options={companionOptions}
                      max={3}
                      disabled={form.processing}
                      onChange={(ids) => form.setData('companion_ids', ids)}
                    />
                    {error('companion_ids')}
                  </DetailCard>
                </div>
              )}
              {tab === 'sales' && (
                <ProductSalesFields
                  locale={locale}
                  rows={form.data.sales}
                  groups={
                    saleCustomerGroups ??
                    customerGroups.map((g) => ({ ...g, default: false }))
                  }
                  currency={currency.code}
                  errors={errors}
                  onChange={(rows) => form.setData('sales', rows)}
                />
              )}
              {tab === 'features' && featureEditor && (
                <ProductFeatureFields
                  locale={locale}
                  editor={featureEditor}
                  errors={errors}
                  values={form.data.features}
                  onChange={(values) => form.setData('features', values)}
                  categoryChanged={
                    form.data.category_id !== product.category_id
                  }
                />
              )}
              {tab === 'general' && (
                <>
                  <FormField
                    id={'name-' + locale}
                    label={t('common.name') + ' · ' + locale}
                    requiredIndicator={locale === defaultLocale}
                    value={text.name}
                    error={errors['translations.' + locale + '.name']}
                    onChange={(e) => setText('name', e.target.value)}
                  />
                  <div className="grid gap-5 sm:grid-cols-2">
                    <FormField
                      id="sku"
                      label={t('products.sku')}
                      requiredIndicator
                      value={form.data.sku}
                      error={errors.sku}
                      onChange={(e) => form.setData('sku', e.target.value)}
                    />
                    <div>
                      <FormField
                        id="ean"
                        label={t('products.ean')}
                        tooltip={t('products.ean_help')}
                        tooltipLabel={t('products.identifier_help_label', {
                          name: t('products.ean'),
                        })}
                        value={form.data.ean}
                        error={errors.ean}
                        inputMode="numeric"
                        maxLength={13}
                        onChange={(e) => form.setData('ean', e.target.value)}
                      />
                    </div>
                  </div>

                  <div className="grid gap-5 sm:grid-cols-3">
                    <label
                      className="min-w-0 text-sm font-medium"
                      htmlFor="product-brand"
                    >
                      {t('brands.brand')}
                      <BrandSelect
                        id="product-brand"
                        value={form.data.brand_id}
                        options={options.brands ?? []}
                        path={`/admin/products/${product.id}/edit`}
                        onChange={(id) => form.setData('brand_id', id)}
                        invalid={!!errors.brand_id}
                      />
                      {error('brand_id')}
                    </label>
                    <FormField
                      id="mpn"
                      label={t('products.mpn')}
                      value={form.data.mpn}
                      error={errors.mpn}
                      maxLength={200}
                      onChange={(e) => form.setData('mpn', e.target.value)}
                    />
                    <label className="text-sm font-medium">
                      {t('products.type')}
                      <RequiredMark />
                      <Select
                        className="mt-2"
                        aria-required
                        aria-label={t('products.type')}
                        value={form.data.type}
                        onChange={(value) => form.setData('type', value)}
                        options={['physical', 'digital'].map((type) => ({
                          value: type,
                          label: t('products.' + type),
                        }))}
                      />
                      {error('type')}
                    </label>
                  </div>
                  <div className="grid gap-5 sm:grid-cols-2">
                    <div>
                      <FormField
                        id="isbn"
                        label={t('products.isbn')}
                        tooltip={t('products.isbn_help')}
                        tooltipLabel={t('products.identifier_help_label', {
                          name: t('products.isbn'),
                        })}
                        value={form.data.isbn}
                        error={errors.isbn}
                        maxLength={32}
                        onChange={(e) => form.setData('isbn', e.target.value)}
                      />
                    </div>
                    <div>
                      <FormField
                        id="upc"
                        label={t('products.upc')}
                        tooltip={t('products.upc_help')}
                        tooltipLabel={t('products.identifier_help_label', {
                          name: t('products.upc'),
                        })}
                        value={form.data.upc}
                        error={errors.upc}
                        inputMode="numeric"
                        maxLength={12}
                        onChange={(e) => form.setData('upc', e.target.value)}
                      />
                    </div>
                  </div>
                  <div>
                    <label
                      className="mb-2 block text-sm font-medium"
                      htmlFor={'short-description-' + locale}
                    >
                      {t('products.short_description')}
                    </label>
                    <RichText
                      key={'short-' + locale}
                      id={'short-description-' + locale}
                      label={t('products.short_description')}
                      disabled={form.processing}
                      value={text.short_description}
                      onChange={(value) => setText('short_description', value)}
                    />
                    <p className="mt-1 text-xs text-slate-500">
                      {t('products.short_description_help')}
                    </p>
                    {error('translations.' + locale + '.short_description')}
                  </div>
                  <div>
                    <label
                      className="mb-2 block text-sm font-medium"
                      htmlFor={'description-' + locale}
                    >
                      {t('products.description')}
                    </label>
                    <RichText
                      key={locale}
                      id={'description-' + locale}
                      label={t('products.description')}
                      disabled={form.processing}
                      value={text.description}
                      onChange={(value) => setText('description', value)}
                    />
                    {error('translations.' + locale + '.description')}
                  </div>
                  {form.data.type === 'physical' && (
                    <div className="grid gap-5 sm:grid-cols-2">
                      {(['weight', 'length', 'width', 'height'] as const).map(
                        (field) => (
                          <FormField
                            key={field}
                            id={field}
                            label={
                              t('products.' + field) +
                              ' (' +
                              (field === 'weight'
                                ? units.weight
                                : units.dimension) +
                              ')'
                            }
                            inputMode="decimal"
                            value={form.data[field]}
                            error={errors[field]}
                            onChange={(e) =>
                              form.setData(field, e.target.value)
                            }
                          />
                        ),
                      )}
                    </div>
                  )}
                  <div className="self-center">
                    <label className="flex items-center gap-2 text-sm font-medium">
                      <input
                        type="checkbox"
                        checked={form.data.returnable}
                        onChange={(e) =>
                          form.setData('returnable', e.target.checked)
                        }
                      />
                      {t('products.returnable')}
                    </label>
                    {error('returnable')}
                  </div>
                </>
              )}
              {tab === 'shipping' && (
                <>
                  {form.data.type !== 'physical' && (
                    <p className="rounded-md bg-slate-50 p-3 text-sm text-slate-600">
                      {t('products.shipping_digital')}
                    </p>
                  )}
                  <div>
                    <label className="flex items-center gap-2 text-sm font-medium">
                      <input
                        type="checkbox"
                        checked={form.data.free_shipping}
                        aria-describedby="free-shipping-hint"
                        onChange={(e) =>
                          form.setData('free_shipping', e.target.checked)
                        }
                      />
                      {t('products.free_shipping')}
                    </label>
                    <p
                      id="free-shipping-hint"
                      className="mt-1 text-xs leading-5 text-slate-500"
                    >
                      {t('products.free_shipping_hint')}
                    </p>
                    {error('free_shipping')}
                  </div>
                  <div className="grid gap-5 sm:grid-cols-2">
                    <FormField
                      id="shipping_cost"
                      label={t('products.shipping_cost_currency', {
                        code: currency.code,
                      })}
                      hint={t('products.shipping_cost_hint')}
                      inputMode="decimal"
                      disabled={form.data.free_shipping}
                      value={form.data.shipping_cost}
                      error={errors.shipping_cost}
                      onChange={(e) =>
                        form.setData('shipping_cost', e.target.value)
                      }
                    />
                  </div>
                  <div className="space-y-5 border-t border-slate-200 pt-6">
                    <h3 className="text-base font-semibold">
                      {t('products.section_packaging')}
                    </h3>
                    <p className="text-xs leading-5 text-slate-500">
                      {t('products.packaging_hint')}
                    </p>
                    <div className="grid gap-5 sm:grid-cols-2">
                      {(['box_min_items', 'box_max_items'] as const).map(
                        (field) => (
                          <FormField
                            key={field}
                            id={field}
                            label={t('products.' + field)}
                            type="number"
                            min={0}
                            step={1}
                            inputMode="numeric"
                            value={form.data[field]}
                            error={errors[field]}
                            onChange={(e) =>
                              form.setData(field, e.target.value)
                            }
                          />
                        ),
                      )}
                    </div>
                    <div className="grid gap-5 sm:grid-cols-3">
                      {(['box_length', 'box_width', 'box_height'] as const).map(
                        (field) => (
                          <FormField
                            key={field}
                            id={field}
                            label={
                              t('products.' + field) +
                              ' (' +
                              units.dimension +
                              ')'
                            }
                            requiredIndicator={packaging}
                            inputMode="decimal"
                            value={form.data[field]}
                            error={errors[field]}
                            onChange={(e) =>
                              form.setData(field, e.target.value)
                            }
                          />
                        ),
                      )}
                    </div>
                  </div>
                </>
              )}
              {tab === 'pricing' && (
                <>
                  <h3 className="text-base font-semibold">
                    {t('products.section_pricing')}
                  </h3>
                  <div className="grid gap-5 sm:grid-cols-2">
                    <FormField
                      id="price"
                      label={t('products.price_currency', {
                        code: currency.code,
                      })}
                      requiredIndicator
                      hint={t('products.price_hint', {
                        name: currency.name,
                        code: currency.code,
                        decimals: currency.decimals,
                      })}
                      inputMode="decimal"
                      value={form.data.price}
                      error={errors.price}
                      onChange={(e) => form.setData('price', e.target.value)}
                    />
                    <FormField
                      id="list_price"
                      label={t('products.list_price_currency', {
                        code: currency.code,
                      })}
                      hint={t('products.list_price_hint')}
                      inputMode="decimal"
                      value={form.data.list_price}
                      error={errors.list_price}
                      onChange={(e) =>
                        form.setData('list_price', e.target.value)
                      }
                    />
                  </div>
                  {conversions.length > 0 && (
                    <section aria-labelledby="price-conversions">
                      <h3
                        id="price-conversions"
                        className="text-sm font-medium text-slate-800"
                      >
                        {t('products.conversions')}
                      </h3>
                      <p className="mt-1 text-xs text-slate-500">
                        {t('products.conversions_hint')}
                      </p>
                      <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                        {conversions.map((c) => (
                          <div
                            key={c.code}
                            className="flex justify-between gap-3 rounded-md border border-slate-200 px-3 py-2"
                          >
                            <dt className="font-mono text-slate-500">
                              {c.code}
                            </dt>
                            <dd className="font-mono tabular-nums">
                              {c.formatted}
                            </dd>
                          </div>
                        ))}
                      </dl>
                    </section>
                  )}
                  <section
                    aria-labelledby="product-taxes-heading"
                    className="space-y-3 border-t border-slate-200 pt-6"
                  >
                    <h3
                      id="product-taxes-heading"
                      className="text-base font-semibold"
                    >
                      {t('products.section_taxes')}
                    </h3>
                    <p className="text-xs leading-5 text-slate-500">
                      {t('products.taxes_hint')}
                    </p>
                    {(product.taxes ?? []).length === 0 ? (
                      <p className="text-sm text-slate-500">
                        {t('products.taxes_none')}
                      </p>
                    ) : (
                      <ul className="grid gap-2 sm:grid-cols-2">
                        {(product.taxes ?? []).map((tax) => (
                          <li key={tax.id}>
                            <label className="flex items-start gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                              <input
                                type="checkbox"
                                className="mt-0.5 size-4"
                                checked={form.data.tax_ids.includes(tax.id)}
                                onChange={(e) =>
                                  form.setData(
                                    'tax_ids',
                                    e.target.checked
                                      ? [...form.data.tax_ids, tax.id]
                                      : form.data.tax_ids.filter(
                                          (id) => id !== tax.id,
                                        ),
                                  )
                                }
                              />
                              <span>
                                <span className="font-medium">{tax.name}</span>
                                <span className="block text-xs text-slate-500">
                                  {t(
                                    tax.price_includes_tax
                                      ? 'taxes.price_included'
                                      : 'taxes.price_added',
                                  )}
                                  {!tax.active && ' · ' + t('taxes.disabled')}
                                </span>
                              </span>
                            </label>
                          </li>
                        ))}
                      </ul>
                    )}
                    {errors.tax_ids && (
                      <p role="alert" className="text-sm text-red-700">
                        {errors.tax_ids}
                      </p>
                    )}
                  </section>
                  <div className="space-y-5 border-t border-slate-200 pt-6">
                    <h3 className="text-base font-semibold">
                      {t('products.section_inventory')}
                    </h3>
                    <div className="grid gap-5 sm:grid-cols-2">
                      {(['in_stock', 'out_of_stock'] as const).map((kind) => {
                        const field = `${kind}_status_id` as const;
                        const defaultLabel =
                          availabilityStatuses.find(
                            (status) => status[`default_${kind}`],
                          )?.name ?? '';
                        return (
                          <div key={kind}>
                            <label
                              htmlFor={field}
                              className="mb-2 block text-sm font-medium"
                            >
                              {t(`availability.${kind}`)}
                            </label>
                            <Select
                              id={field}
                              value={form.data[field]}
                              invalid={!!errors[field]}
                              disabled={form.processing}
                              onChange={(value) => form.setData(field, value)}
                              options={[
                                {
                                  value: '',
                                  label: t('availability.inherit', {
                                    name: defaultLabel,
                                  }),
                                },
                                ...availabilityStatuses.map((status) => ({
                                  value: status.id,
                                  label: status.name,
                                })),
                              ]}
                            />
                            {error(field)}
                          </div>
                        );
                      })}
                    </div>
                    <p className="text-xs leading-5 text-slate-500">
                      {t('availability.product_help')}
                    </p>
                    {product.availability && (
                      <p className="text-xs text-slate-500">
                        {t('availability.saved_label', {
                          name: product.availability.name,
                        })}
                      </p>
                    )}
                    {capabilities['availability-statuses.manage'] && (
                      <Link
                        className="text-link text-sm"
                        href="/admin/availability-statuses"
                      >
                        {t('availability.manage')}
                      </Link>
                    )}
                    <div className="grid gap-5 sm:grid-cols-2">
                      <FormField
                        id="quantity"
                        label={t('products.quantity')}
                        requiredIndicator
                        hint={t('products.quantity_hint')}
                        type="number"
                        min={0}
                        step={1}
                        inputMode="numeric"
                        value={form.data.quantity}
                        error={errors.quantity}
                        onChange={(e) =>
                          form.setData('quantity', e.target.value)
                        }
                      />
                      <div className="space-y-2">
                        <label
                          htmlFor="out_of_stock_actions"
                          className="block text-sm font-medium text-slate-800"
                        >
                          {t('products.out_of_stock_actions')}
                        </label>
                        <Select
                          id="out_of_stock_actions"
                          value={form.data.out_of_stock_actions}
                          invalid={!!errors.out_of_stock_actions}
                          onChange={(value) =>
                            form.setData('out_of_stock_actions', value)
                          }
                          options={[
                            {
                              value: '',
                              label: t(
                                'products.out_of_stock_inherit_current',
                                {
                                  value: t(`products.out_of_stock_${general}`),
                                },
                              ),
                            },
                            ...['N', 'B'].map((value) => ({
                              value,
                              label: t(`products.out_of_stock_${value}`),
                            })),
                          ]}
                        />
                        <p className="text-xs leading-5 text-slate-500">
                          {form.data.out_of_stock_actions === ''
                            ? t('products.out_of_stock_following', {
                                value: t(`products.out_of_stock_${general}`),
                              })
                            : t('products.out_of_stock_custom_note', {
                                value: t(`products.out_of_stock_${general}`),
                              })}
                        </p>
                        {form.data.out_of_stock_actions !== '' && (
                          <button
                            type="button"
                            className="text-xs font-medium text-slate-700 underline"
                            onClick={() =>
                              form.setData('out_of_stock_actions', '')
                            }
                          >
                            {t('products.out_of_stock_reset')}
                          </button>
                        )}
                        <p className="text-xs leading-5 text-slate-500">
                          {t('products.out_of_stock_hint')}
                          {capabilities['settings.manage'] && (
                            <>
                              {' '}
                              <Link
                                className="font-medium underline"
                                href="/admin/settings/general"
                              >
                                {t('products.out_of_stock_change_general')}
                              </Link>
                            </>
                          )}
                        </p>
                        {errors.out_of_stock_actions && (
                          <p role="alert" className="text-sm text-red-700">
                            {errors.out_of_stock_actions}
                          </p>
                        )}
                      </div>
                    </div>
                    <div className="grid gap-5 sm:grid-cols-3">
                      <FormField
                        id="min_qty"
                        label={t('products.min_qty')}
                        hint={t('products.limits_hint')}
                        type="number"
                        min={1}
                        step={1}
                        inputMode="numeric"
                        value={form.data.min_qty}
                        error={errors.min_qty}
                        onChange={(e) =>
                          form.setData('min_qty', e.target.value)
                        }
                      />
                      <FormField
                        id="max_qty"
                        label={t('products.max_qty')}
                        hint={t('products.limits_hint')}
                        type="number"
                        min={1}
                        step={1}
                        inputMode="numeric"
                        value={form.data.max_qty}
                        error={errors.max_qty}
                        onChange={(e) =>
                          form.setData('max_qty', e.target.value)
                        }
                      />
                      <FormField
                        id="qty_step"
                        label={t('products.qty_step')}
                        hint={t('products.qty_step_hint')}
                        type="number"
                        min={1}
                        step={1}
                        inputMode="numeric"
                        value={form.data.qty_step}
                        error={errors.qty_step}
                        onChange={(e) =>
                          form.setData('qty_step', e.target.value)
                        }
                      />
                    </div>
                  </div>
                  <div className="space-y-5 border-t border-slate-200 pt-6">
                    <h3 className="text-base font-semibold">
                      {t('products.section_availability')}
                    </h3>
                    <div className="grid gap-5 sm:grid-cols-2">
                      <div className="space-y-2">
                        <label
                          htmlFor="available_from"
                          className="block text-sm font-medium text-slate-800"
                        >
                          {t('products.available_from')}
                        </label>
                        <DateInput
                          id="available_from"
                          className="field"
                          value={form.data.available_from}
                          onChange={(value) =>
                            form.setData('available_from', value)
                          }
                          aria-invalid={!!errors.available_from}
                        />
                        <p className="text-xs leading-5 text-slate-500">
                          {t('products.available_from_hint')}
                        </p>
                        {errors.available_from && (
                          <p role="alert" className="text-sm text-red-700">
                            {errors.available_from}
                          </p>
                        )}
                      </div>
                    </div>
                    <CustomerGroupPicker
                      id="customer-groups"
                      label={t('products.customer_groups')}
                      help={t('products.customer_groups_help')}
                      allLabel={t('products.customer_groups_all')}
                      groups={customerGroups}
                      value={form.data.customer_groups}
                      onChange={(value) =>
                        form.setData('customer_groups', value)
                      }
                      error={
                        errors.customer_groups ??
                        Object.entries(errors).find(([key]) =>
                          key.startsWith('customer_groups.'),
                        )?.[1]
                      }
                    />
                  </div>
                </>
              )}
              {tab === 'categories' && (
                <>
                  <label className="block text-sm font-medium">
                    {t('products.main_category')}
                    <RequiredMark />
                    <Select
                      className="mt-2"
                      aria-required
                      aria-label={t('products.main_category')}
                      value={form.data.category_id ?? ''}
                      onChange={(value) => form.setData('category_id', value)}
                      options={[
                        { value: '', label: '—' },
                        ...categories.map((c) => ({
                          value: String(c.id),
                          label: c.name,
                        })),
                      ]}
                      search={{
                        label: t('common.search'),
                        emptyMessage: t('categories.parent_none'),
                        // Leaf categories matching the text load as you type.
                        onChange: (query) => {
                          clearTimeout(categoryTimer.current);
                          categoryTimer.current = setTimeout(
                            () =>
                              router.reload({
                                data: { category_search: query },
                                only: ['categories'],
                                preserveUrl: true,
                              }),
                            300,
                          );
                        },
                      }}
                    />
                    {error('category_id')}
                  </label>
                  <p className="text-sm text-slate-500">
                    {t('products.leaf_required')}{' '}
                    {t('products.main_category_help')}
                  </p>
                  <ExtraCategories
                    value={form.data.extra_category_ids}
                    options={extraCategoryOptions}
                    mainId={form.data.category_id ?? ''}
                    disabled={form.processing}
                    error={
                      errors.extra_category_ids ??
                      Object.entries(errors).find(([key]) =>
                        key.startsWith('extra_category_ids.'),
                      )?.[1]
                    }
                    onChange={(ids) => form.setData('extra_category_ids', ids)}
                  />
                </>
              )}
              {tab === 'seo' && (
                <>
                  {(
                    [
                      'seo_title',
                      'seo_description',
                      'meta_keywords',
                      'slug',
                    ] as const
                  ).map((field) => (
                    <FormField
                      key={field}
                      id={field + '-' + locale}
                      label={t('products.' + field)}
                      urlPrefix={field === 'slug' ? '/…/' : undefined}
                      requiredIndicator={
                        field === 'slug' &&
                        locale === defaultLocale &&
                        form.data.status === 'active'
                      }
                      value={text[field]}
                      error={errors['translations.' + locale + '.' + field]}
                      onChange={(e) => setText(field, e.target.value)}
                    />
                  ))}
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={text.regenerate_slug ?? false}
                      onChange={(e) =>
                        setText('regenerate_slug', e.target.checked)
                      }
                    />
                    {t('products.regenerate')}
                  </label>
                </>
              )}
              {tab === 'additional' && (
                <>
                  <h2 className="text-base font-semibold">
                    {t('common.xml_sitemap')}
                  </h2>
                  <div>
                    <label className="flex items-center gap-3 text-sm font-medium">
                      <input
                        type="checkbox"
                        checked={form.data.exclude_from_sitemap}
                        onChange={(event) =>
                          form.setData(
                            'exclude_from_sitemap',
                            event.target.checked,
                          )
                        }
                        aria-describedby="product-sitemap-hint"
                        aria-invalid={!!errors.exclude_from_sitemap}
                      />
                      {t('products.exclude_from_sitemap')}
                    </label>
                    <p
                      id="product-sitemap-hint"
                      className="mt-2 text-sm text-slate-500"
                    >
                      {t('products.exclude_from_sitemap_hint')}
                    </p>
                    {error('exclude_from_sitemap')}
                  </div>
                  <h2 className="text-base font-semibold">
                    {t('reviews.title')}
                  </h2>
                  <div>
                    <label className="flex items-center gap-3 text-sm font-medium">
                      <input
                        type="checkbox"
                        checked={form.data.reviews_enabled}
                        onChange={(event) =>
                          form.setData('reviews_enabled', event.target.checked)
                        }
                        aria-describedby="product-reviews-hint"
                        aria-invalid={!!errors.reviews_enabled}
                      />
                      {t('products.reviews_enabled')}
                    </label>
                    <p
                      id="product-reviews-hint"
                      className="mt-2 text-sm text-slate-500"
                    >
                      {t('products.reviews_enabled_hint')}
                    </p>
                    {error('reviews_enabled')}
                  </div>
                  <h2 className="text-base font-semibold">
                    {t('pages.section_facebook')}
                  </h2>
                  <FacebookTypeField
                    value={form.data.facebook_type}
                    types={facebookTypes}
                    error={errors.facebook_type}
                    onChange={(value) => form.setData('facebook_type', value)}
                  />
                </>
              )}
              {tab === 'media' && (
                <>
                  <ImageUploader
                    title={t('products.upload_images')}
                    help={t('media.gallery_help')}
                    items={form.data.images.map((id) => ({
                      id,
                      url: '/admin/product-media/' + id,
                      meta: product.image_meta?.[id] ?? null,
                    }))}
                    primary={form.data.primary_image}
                    onPrimary={(id) => form.setData('primary_image', id)}
                    onRemove={(id) =>
                      form.setData({
                        ...form.data,
                        images: form.data.images.filter(
                          (image) => image !== id,
                        ),
                        primary_image:
                          form.data.primary_image === id
                            ? ''
                            : form.data.primary_image,
                      })
                    }
                    onReorder={(ids) => form.setData('images', ids)}
                    files={form.data.image_uploads}
                    onFiles={(files) => form.setData('image_uploads', files)}
                    progress={
                      form.processing && form.data.image_uploads.length > 0
                        ? (form.progress?.percentage ?? 0)
                        : null
                    }
                    disabled={form.processing}
                    error={
                      <>
                        {error('images')}
                        {error('image_uploads')}
                      </>
                    }
                  />
                </>
              )}
              {tab === 'attachments' && (
                <>
                  {form.data.type === 'digital' && fileSection('download')}
                  {fileSection('attachment')}
                  {error('files')}
                  {error('file_uploads')}
                </>
              )}
              {tab === 'price_history' && (
                <PriceHistoryChart
                  history={priceHistory}
                  currency={currency}
                  locale={i18n.locale}
                />
              )}
            </ProductTabCard>
          </section>
        </div>
      </form>
    </AdminLayout>
  );
}
