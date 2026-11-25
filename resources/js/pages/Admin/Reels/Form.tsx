import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import FormErrors from '../../../components/admin/FormErrors';
import FormField from '../../../components/auth/FormField';
import TargetPicker from '../Promotions/TargetPicker';
import VideoUploader from './VideoUploader';
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
import { useTranslation } from '../../../lib/useTranslation';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { ReelEditor } from '../../../types/reels';

type Props = AdminShell & {
  item: ReelEditor | null;
  defaultLocale: string;
  options: { id: string; name: string }[];
  videoMaxBytes: number;
};
export default function ReelForm(props: Props) {
  return (
    <Editor
      key={`${props.item?.id ?? 'new'}:${props.item?.version ?? 0}`}
      {...props}
    />
  );
}
function Editor({
  item,
  defaultLocale,
  options,
  videoMaxBytes,
  ...shell
}: Props) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const manage = !!capabilities['reels.manage'];
  const [locale, setLocale] = useState(defaultLocale);
  const [deleting, setDeleting] = useState(false);
  const form = useForm({
    version: item?.version ?? null,
    status: item?.status ?? 'active',
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        item?.translations[l.code] ?? { name: '' },
      ]),
    ),
    products: item?.products ?? [],
    video: null as File | null,
    cover: null as File | null,
  });
  useDirtyForm(form);
  const path = item ? `/admin/reels/${item.id}/edit` : '/admin/reels/create';
  const errors = form.errors as Record<string, string>;
  return (
    <AdminLayout title={item?.name ?? t('reels.create')} {...shell}>
      <EditorHeader
        heading={item?.name ?? t('reels.create')}
        iconKey="video"
        backHref="/admin/reels"
        backLabel={t('reels.back')}
        status={
          <>
            <EditorStatusSelect
              disabled={!manage || form.processing}
              aria-label={t('common.status')}
              value={form.data.status}
              onChange={(value) =>
                form.setData('status', value as 'active' | 'disabled')
              }
              options={[
                { value: 'active', label: t('common.active') },
                { value: 'disabled', label: t('common.inactive') },
              ]}
            />
          </>
        }
        actions={
          <>
            {manage && (
              <EditorActionButton
                type="submit"
                disabled={form.processing}
                form="admin-reels-form-0"
              >
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            )}
          </>
        }
        menu={[
          manage && item && (
            <button
              type="button"
              disabled={form.processing}
              onClick={() => setDeleting(true)}
              className="text-red-700"
            >
              <Icon name="trash" />
              {t('reels.delete_confirm')}
            </button>
          ),
        ]}
      />
      {item && capabilities['reels.analytics'] && (
        <Link
          className="button-secondary mb-5"
          href={`/admin/reels/${item.id}/history`}
        >
          {t('reels.history')}
        </Link>
      )}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          form.post(item ? `/admin/reels/${item.id}` : '/admin/reels', {
            forceFormData: true,
          });
        }}
        id="admin-reels-form-0"
      >
        <FormErrors errors={errors} />
        <fieldset disabled={!manage || form.processing}>
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
            bodyClassName="p-5 space-y-6"
          >
            <AdminTabs
              language={
                <ContentLanguageSelector
                  languages={i18n.locales}
                  value={locale}
                  onChange={setLocale}
                  errors={errors}
                />
              }
            />
            <FormField
              id="reel-name"
              label={t('reels.name')}
              required={locale === defaultLocale}
              maxLength={200}
              value={form.data.translations[locale]?.name ?? ''}
              error={errors[`translations.${locale}.name`]}
              onChange={(e) =>
                form.setData('translations', {
                  ...form.data.translations,
                  [locale]: { name: e.target.value },
                })
              }
            />
            <VideoUploader
              file={form.data.video}
              current={item?.video ?? null}
              poster={item?.cover}
              maxBytes={videoMaxBytes}
              progress={form.progress?.percentage ?? null}
              disabled={!manage || form.processing}
              error={errors.video}
              onFile={(file) => form.setData('video', file)}
            />
            <SingleImageUploader
              title={t('reels.cover')}
              help={t('reels.cover_help')}
              current={item ? { url: item.cover } : null}
              alt=""
              file={form.data.cover}
              onFile={(file) => form.setData('cover', file)}
              removed={false}
              onRemoved={() => {}}
              allowRemoveCurrent={false}
              progress={form.progress?.percentage ?? null}
              disabled={!manage || form.processing}
              error={
                errors.cover ? (
                  <p role="alert" className="text-sm text-red-700">
                    {errors.cover}
                  </p>
                ) : undefined
              }
            />
            <div className="border-t border-slate-200 pt-6">
              <p className="mb-3 text-sm text-slate-500">
                {t('reels.products_help')}
              </p>
              <TargetPicker
                label={t('reels.products')}
                kind="products"
                values={form.data.products}
                options={options}
                path={path}
                onChange={(ids) => form.setData('products', ids)}
              />
              {errors.products && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.products}
                </p>
              )}
            </div>
          </UnifiedCard>
        </fieldset>
      </form>
      {deleting && item && (
        <ConfirmDeleteDialog
          title={t('reels.delete_confirm')}
          facts={[t('reels.delete_impact')]}
          onCancel={() => setDeleting(false)}
          onConfirm={() =>
            router.delete(`/admin/reels/${item.id}`, {
              data: { version: item.version },
              onFinish: () => setDeleting(false),
            })
          }
        />
      )}
    </AdminLayout>
  );
}
