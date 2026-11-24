import UnifiedCard from '../../../components/admin/AdminCard';
import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import CsvImportUpload, {
  CsvImportSteps,
  CsvImportInstructions,
} from '../../../components/admin/CsvImportUpload';
import { useFileSize } from '../../../components/admin/ImageUploader';
import { useForm } from '@inertiajs/react';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';

import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import Select from '../../../components/admin/Select';

export type ImportPreview = {
  token: string;
  filename: string;
  status: 'preview' | 'completed' | 'failed' | 'processing';
  rows: number;
  created: number;
  updated: number;
  skipped: number;
  errors: number;
  duration?: number;
  images?: number;
  sample: {
    row: number;
    action: 'created' | 'updated' | 'skipped';
    name: string;
    sku: string;
    status: string;
  }[];
  issues: {
    row: number;
    identifier: string;
    column: string;
    reason: string;
    severity: string;
  }[];
};
type Props = AdminShell & {
  columns: string[];
  delimiters: string[];
  preview: ImportPreview | null;
  limits: { fileBytes: number; imageUrls: number; imageBytes: number };
};

export default function ProductImport({
  columns,
  delimiters,
  preview,
  limits,
  ...shell
}: Props) {
  const t = useTranslation();
  const fileSize = useFileSize();
  const upload = useForm<{
    file: File | null;
    delimiter: string;
  }>({ file: null, delimiter: ',' });
  useDirtyForm(upload);
  const confirmation = useForm({ confirmed: false });
  useDirtyForm(confirmation);
  return (
    <AdminLayout title={t('product_import.title')} {...shell}>
      <EditorHeader
        heading={t('product_import.title')}
        iconKey="products"
        description={
          <>
            <p className="mt-2 max-w-2xl text-sm text-slate-500">
              {t('product_import.intro')}
            </p>
          </>
        }
        actions={
          <>
            <a
              className="button-secondary"
              href={
                '/admin/products/import/template?delimiter=' +
                encodeURIComponent(upload.data.delimiter)
              }
            >
              {t('product_import.template')}
            </a>
          </>
        }
      />
      <CsvImportSteps status={preview?.status ?? null} />
      {!preview ? (
        <CsvImportUpload
          file={upload.data.file}
          maxFileBytes={limits.fileBytes}
          processing={upload.processing}
          progress={upload.progress}
          errors={upload.errors}
          onFileChange={(file) => upload.setData('file', file)}
          onFileError={(message) => {
            if (message) upload.setError('file', message);
            else upload.clearErrors('file');
          }}
          onSubmit={() =>
            upload.post('/admin/products/import/preview', {
              forceFormData: true,
            })
          }
        >
          <div>
            <label
              htmlFor="csv-delimiter"
              className="mb-2 block text-sm font-medium"
            >
              {t('product_import.delimiter')}
            </label>
            <Select
              id="csv-delimiter"
              value={upload.data.delimiter}
              onChange={(value) => upload.setData('delimiter', value)}
              options={delimiters.map((delimiter) => ({
                value: delimiter,
                label:
                  delimiter === ','
                    ? t('product_import.comma')
                    : t('product_import.semicolon'),
              }))}
            />
          </div>
        </CsvImportUpload>
      ) : (
        <section className="space-y-5">
          <UnifiedCard
            id="import-summary"
            title={t('product_import.summary')}
            iconKey="general"
            bodyClassName="p-5 space-y-5"
          >
            <div>
              <p role="status" className="mt-2 text-sm font-medium">
                {t('product_import.' + preview.status)}
              </p>
              <p className="mt-1 break-all text-xs text-slate-500">
                {preview.filename}
              </p>
            </div>
            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
              {(
                ['rows', 'created', 'updated', 'skipped', 'errors'] as const
              ).map((key) => (
                <div key={key}>
                  <dt className="text-xs text-slate-500">
                    {t('product_import.' + key)}
                  </dt>
                  <dd className="mt-1 text-xl font-semibold">{preview[key]}</dd>
                </div>
              ))}
            </dl>
            {!!preview.images && (
              <p className="text-sm">
                {t('product_import.images')}: {preview.images}
              </p>
            )}
            {preview.duration !== undefined && (
              <p className="text-sm">
                {t('product_import.duration')}: {preview.duration}
              </p>
            )}
            <p className="text-xs text-slate-500">
              {t('product_import.bounded')}
            </p>
            {preview.issues.length > 0 && (
              <div className="space-y-3">
                <a
                  className="text-link text-sm"
                  href={'/admin/products/import/' + preview.token + '/errors'}
                >
                  {t('product_import.download_errors')}
                </a>
                <ul className="divide-y divide-red-100 rounded-md border border-red-200 text-sm">
                  {preview.issues.map((issue, index) => (
                    <li className="break-words p-3" key={index}>
                      <p className="font-medium">
                        {t('product_import.row')} {issue.row} · {issue.column}
                      </p>
                      <p className="break-all text-xs text-slate-500">
                        {issue.identifier}
                      </p>
                      <p>{issue.reason}</p>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {preview.sample.length > 0 && (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr>
                      {['row', 'action', 'identifier'].map((key) => (
                        <th key={key} className="p-2">
                          {t('product_import.' + key)}
                        </th>
                      ))}
                      <th className="p-2">{t('products.status')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.sample.map((row, index) => (
                      <tr key={index} className="border-t border-slate-100">
                        <td className="p-2">{row.row}</td>
                        <td className="p-2">
                          {t('product_import.' + row.action)}
                        </td>
                        <td className="max-w-64 break-words p-2">
                          {row.sku}
                          <span className="block text-xs text-slate-500">
                            {row.name}
                          </span>
                        </td>
                        <td className="p-2">{t('products.' + row.status)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <FormErrors errors={confirmation.errors} />
            {preview.status === 'preview' && preview.errors === 0 && (
              <form
                className="space-y-4"
                onSubmit={(event) => {
                  event.preventDefault();
                  confirmation.post(
                    '/admin/products/import/' + preview.token + '/confirm',
                  );
                }}
              >
                <label className="flex items-start gap-3 text-sm">
                  <input
                    className="mt-1 size-4 shrink-0"
                    type="checkbox"
                    required
                    checked={confirmation.data.confirmed}
                    onChange={(event) =>
                      confirmation.setData('confirmed', event.target.checked)
                    }
                  />
                  {t('product_import.confirm')}
                </label>
                <button
                  className="button-primary"
                  disabled={
                    !confirmation.data.confirmed || confirmation.processing
                  }
                >
                  {t('product_import.run')}
                </button>
              </form>
            )}
            {preview.status !== 'processing' && (
              <button
                className="text-link text-sm"
                disabled={confirmation.processing}
                onClick={() =>
                  confirmation.delete('/admin/products/import/' + preview.token)
                }
              >
                {t('product_import.cancel')}
              </button>
            )}
          </UnifiedCard>
        </section>
      )}
      <aside className="mt-6">
        <AdminCard
          id="csv-image-title"
          title={t('product_import.image_title')}
          iconKey="image_title"
          bodyClassName="p-5 space-y-3"
        >
          <p className="max-w-4xl text-sm text-slate-500">
            {t('product_import.image_help', {
              count: limits.imageUrls,
              size: fileSize(limits.imageBytes),
            })}
          </p>
          <p className="max-w-4xl text-sm text-slate-500">
            {t('product_import.image_behavior')}
          </p>
          <pre
            aria-label={t('product_import.image_example')}
            className="overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs"
          >
            <code>
              {'sku,image_url\nEXAMPLE-001,https://example.com/product.jpg'}
            </code>
          </pre>
        </AdminCard>
      </aside>
      <CsvImportInstructions
        help={t('product_import.help')}
        columns={columns}
      />
    </AdminLayout>
  );
}
