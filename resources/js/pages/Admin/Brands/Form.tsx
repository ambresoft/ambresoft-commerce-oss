import UnifiedCard from '../../../components/admin/AdminCard';
import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import FormErrors from '../../../components/admin/FormErrors';
import FormField from '../../../components/auth/FormField';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import RichText from '../../../components/admin/RichText';
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
import AttachmentUploader, {
  removeAttachmentUpload,
} from '../../../components/admin/AttachmentUploader';
import { useTranslation } from '../../../lib/useTranslation';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import type { AdminShell } from '../../../types/admin';
import type { BrandEditor, BrandText } from '../../../types/brands';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & { brand: BrandEditor | null; defaultLocale: string };
export default function BrandForm(props: Props) {
  return (
    <Editor
      key={`${props.brand?.id ?? 'new'}:${props.brand?.version ?? 0}`}
      {...props}
    />
  );
}
function Editor({ brand, defaultLocale, ...shell }: Props) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const allowed = !!capabilities['brands.manage'];
  const [locale, setLocale] = useState(defaultLocale);
  const [tab, setTab] = useState('general');
  const [deleting, setDeleting] = useState(false);
  const form = useForm({
    version: brand?.version ?? null,
    code: brand?.code ?? '',
    status: brand?.status ?? 'active',
    position: brand?.position ?? 0,
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        brand?.translations[l.code] ?? {
          name: '',
          description: '',
          image_alt: '',
          banner_alt: '',
        },
      ]),
    ) as Record<string, BrandText>,
    image: null as File | null,
    remove_image: false,
    banner_image: null as File | null,
    remove_banner_image: false,
    banner_link: brand?.banner_link ?? '',
    files_submitted: true,
    files: brand?.files ?? [],
    file_uploads: [] as File[],
  });
  useDirtyForm(form);
  const text = form.data.translations[locale];
  const setText = (key: keyof BrandText, value: string) =>
    form.setData('translations', {
      ...form.data.translations,
      [locale]: { ...text, [key]: value },
    });
  const errors = form.errors as Record<string, string>;
  return (
    <AdminLayout title={brand?.name ?? t('brands.create')} {...shell}>
      <EditorHeader
        image={brand?.image?.url}
        heading={brand?.name ?? t('brands.create')}
        iconKey="brand"
        backHref="/admin/brands"
        backLabel={t('brands.back')}
        status={
          <EditorStatusSelect
            aria-label={t('common.status')}
            disabled={!allowed}
            value={form.data.status}
            onChange={(value) =>
              form.setData('status', value as 'active' | 'disabled')
            }
            options={[
              { value: 'active', label: t('common.active') },
              { value: 'disabled', label: t('common.inactive') },
            ]}
          />
        }
        actions={
          <>
            {allowed && (
              <EditorActionButton
                type="submit"

                disabled={form.processing}
                form="admin-brands-form-0"
              >
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            )}
          </>
        }
        menu={[
          brand && allowed && (
            <button
              type="button"
              onClick={() => setDeleting(true)}
              disabled={form.processing}
              className="text-red-700"
            >
              <Icon name="trash" />
              {t('common.delete')}
            </button>
          ),
        ]}
      />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          form.transform((data) => ({
            ...data,
            files: data.files.map(({ id, display_name, replacement }) => ({
              id,
              display_name,
              ...(replacement == null ? {} : { replacement }),
            })),
          }));
          form.post(brand ? `/admin/brands/${brand.id}` : '/admin/brands', {
            forceFormData: true,
          });
        }}
        id="admin-brands-form-0"
      >
        <AdminTabs
          label={t('brands.title')}
          className="mb-5"
          language={
            <ContentLanguageSelector
              languages={i18n.locales}
              value={locale}
              onChange={setLocale}
              errors={errors}
            />
          }
        >
          {['general', 'image', 'attachments'].map((key) => (
            <AdminTab
              type="button"
              role="tab"
              aria-selected={tab === key}
              key={key}
              onClick={() => setTab(key)}
              iconKey={key}
            >
              {t(`brands.${key}`)}
            </AdminTab>
          ))}
        </AdminTabs>
        <FormErrors errors={errors} />
        <fieldset disabled={!allowed || form.processing}>
          <UnifiedCard
            title={t('brands.' + tab)}
            iconKey=""
            bodyClassName="p-5 space-y-5"
          >
            {tab === 'general' ? (
              <>
                <div className="grid gap-5 sm:grid-cols-2">
                  <FormField
                    id="brand-code"
                    label={t('brands.code')}
                    value={form.data.code}
                    onChange={(e) => form.setData('code', e.target.value)}
                    error={errors.code}
                    required
                    maxLength={80}
                  />
                  <FormField
                    id="brand-position"
                    label={t('brands.position')}
                    type="number"
                    min={0}
                    value={form.data.position}
                    onChange={(e) =>
                      form.setData('position', Number(e.target.value))
                    }
                    error={errors.position}
                  />
                </div>
                <p className="text-sm text-slate-500">
                  {t('brands.code_help')}
                </p>

                <FormField
                  id="brand-name"
                  label={t('brands.name')}
                  value={text.name}
                  onChange={(e) => setText('name', e.target.value)}
                  error={errors[`translations.${locale}.name`]}
                  required
                  maxLength={200}
                />
                <div>
                  <label className="mb-2 block text-sm font-medium">
                    {t('brands.description')}
                  </label>
                  <RichText
                    id="brand-description"
                    label={t('brands.description')}
                    value={text.description}
                    onChange={(value) => setText('description', value)}
                  />
                  {errors[`translations.${locale}.description`] && (
                    <p className="mt-1 text-sm text-red-700">
                      {errors[`translations.${locale}.description`]}
                    </p>
                  )}
                </div>
                <FormField
                  id="brand-image-alt"
                  label={t('brands.image_alt')}
                  value={text.image_alt}
                  onChange={(e) => setText('image_alt', e.target.value)}
                  error={errors[`translations.${locale}.image_alt`]}
                  maxLength={200}
                />
                {brand && (
                  <Link
                    className="text-link"
                    href={`/admin/products?brand_id=${brand.id}`}
                  >
                    {t('brands.assigned_products', {
                      count: brand.products_count,
                    })}
                  </Link>
                )}
              </>
            ) : tab === 'attachments' ? (
              <AttachmentUploader
                title={t('brands.attachments')}
                help={t('brands.attachments_hint')}
                items={form.data.files.map((file, index) => ({ file, index }))}
                pending={form.data.file_uploads
                  .map((file, index) => ({ file, index }))
                  .filter(
                    ({ index }) =>
                      !form.data.files.some(
                        (file) => file.replacement === index,
                      ),
                  )}
                disabled={!allowed || form.processing}
                progress={
                  form.processing && form.data.file_uploads.length
                    ? form.progress?.percentage
                    : undefined
                }
                onAdd={(files) =>
                  form.setData('file_uploads', [
                    ...form.data.file_uploads,
                    ...files,
                  ])
                }
                onName={(index, name) =>
                  form.setData(
                    'files',
                    form.data.files.map((file, n) =>
                      n === index ? { ...file, display_name: name } : file,
                    ),
                  )
                }
                fieldError={(index) =>
                  errors['files.' + index + '.display_name']
                }
                onRemovePending={(index) =>
                  form.setData(removeAttachmentUpload(form.data, index))
                }
                onMove={(index, by) => {
                  const files = [...form.data.files];
                  [files[index], files[index + by]] = [
                    files[index + by],
                    files[index],
                  ];
                  form.setData('files', files);
                }}
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
                    files: form.data.files.map((old, n) =>
                      n === index ? { ...old, replacement: upload } : old,
                    ),
                  });
                }}
              />
            ) : (
              <>
                <SingleImageUploader
                  title={t('brands.image')}
                  current={brand?.image ?? null}
                  alt={text.image_alt || text.name}
                  file={form.data.image}
                  onFile={(file) => form.setData('image', file)}
                  removed={form.data.remove_image}
                  onRemoved={(value) => form.setData('remove_image', value)}
                  progress={form.progress?.percentage}
                  disabled={!allowed || form.processing}
                  error={errors.image}
                />

                <div className="space-y-5 border-t border-slate-200 pt-6">
                  <SingleImageUploader
                    title={t('brands.banner_image')}
                    help={t('brands.banner_image_help')}
                    current={brand?.banner_image ?? null}
                    alt={text.banner_alt || text.name}
                    file={form.data.banner_image}
                    onFile={(file) => form.setData('banner_image', file)}
                    removed={form.data.remove_banner_image}
                    onRemoved={(value) =>
                      form.setData('remove_banner_image', value)
                    }
                    progress={
                      form.data.banner_image
                        ? form.progress?.percentage
                        : undefined
                    }
                    disabled={!allowed || form.processing}
                    error={errors.banner_image}
                  />

                  <FormField
                    id="brand-banner-alt"
                    label={t('brands.banner_alt')}
                    hint={t('brands.banner_alt_help')}
                    value={text.banner_alt ?? ''}
                    maxLength={200}
                    onChange={(event) =>
                      setText('banner_alt', event.target.value)
                    }
                    error={errors[`translations.${locale}.banner_alt`]}
                  />
                  <FormField
                    id="brand-banner-link"
                    label={t('brands.banner_link')}
                    hint={t('brands.banner_link_help')}
                    value={form.data.banner_link}
                    maxLength={2048}
                    onChange={(event) =>
                      form.setData('banner_link', event.target.value)
                    }
                    error={errors.banner_link}
                  />
                </div>
              </>
            )}
          </UnifiedCard>
        </fieldset>
      </form>
      {deleting && brand && (
        <ConfirmDeleteDialog
          title={t('brands.delete_title', { name: brand.name })}
          facts={[
            t('brands.assigned_products', { count: brand.products_count }),
            t('brands.delete_used'),
          ]}
          onArchive={() => {
            router.patch(`/admin/brands/${brand.id}/status`, {
              status: 'disabled',
              version: brand.version,
            });
            setDeleting(false);
          }}
          onCancel={() => setDeleting(false)}
          onConfirm={() => {
            router.delete(`/admin/brands/${brand.id}`, {
              data: { version: brand.version },
              onFinish: () => setDeleting(false),
            });
          }}
        />
      )}
    </AdminLayout>
  );
}
