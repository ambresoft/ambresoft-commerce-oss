import AdminCard from './AdminCard';
import type { ReactNode } from 'react';
import { useTranslation } from '../../lib/useTranslation';
import { UploadPanel, useFileSize } from './ImageUploader';
import FormErrors from './FormErrors';

/** Default admin CSV upload UI. Keep parsing, authorization and confirmation in each import workflow. */
export default function CsvImportUpload({
  file,
  maxFileBytes,
  processing,
  progress,
  errors,
  onFileChange,
  onFileError,
  onSubmit,
  children,
}: {
  file: File | null;
  maxFileBytes: number;
  processing: boolean;
  progress?: { percentage?: number } | null;
  errors: Record<string, string>;
  onFileChange: (file: File | null) => void;
  onFileError: (message: string | null) => void;
  onSubmit: () => void;
  children: ReactNode;
}) {
  const t = useTranslation();
  const fileSize = useFileSize();
  return (
    <form
      className="space-y-5"
      onSubmit={(event) => {
        event.preventDefault();
        if (!processing && file && !errors.file) onSubmit();
      }}
    >
      <FormErrors errors={errors} />
      <UploadPanel
        id="csv-file"
        title={t('common.csv_import.file')}
        count={null}
        multiple={false}
        disabled={processing}
        accept=".csv,.txt,text/csv,text/plain"
        dropLabel={t('common.csv_import.drop')}
        chooseLabel={t('common.csv_import.choose')}
        hint={t('common.csv_import.file_hint', {
          size: fileSize(maxFileBytes),
        })}
        required
        onFiles={(files) => {
          if (
            files.length !== 1 ||
            !/\.(csv|txt)$/i.test(files[0].name) ||
            files[0].size > maxFileBytes ||
            files[0].size === 0
          ) {
            onFileError(
              t('common.csv_import.invalid_file', {
                size: fileSize(maxFileBytes),
              }),
            );
            return;
          }
          onFileError(null);
          onFileChange(files[0]);
        }}
      >
        {file && (
          <div
            className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-4"
            role="status"
          >
            <div className="min-w-0">
              <p className="text-xs text-slate-500">
                {t('common.csv_import.selected')}
              </p>
              <p className="truncate font-medium">{file.name}</p>
              <p className="mt-1 text-xs text-slate-500">
                {fileSize(file.size)}
              </p>
            </div>
            <button
              type="button"
              className="button-secondary shrink-0"
              disabled={processing}
              onClick={() => {
                onFileChange(null);
                onFileError(null);
              }}
            >
              {t('common.csv_import.remove_file')}
            </button>
          </div>
        )}
      </UploadPanel>

      <section className="">
        <AdminCard
          id="import-settings"
          title={t('common.csv_import.settings')}
          iconKey="settings"
          bodyClassName="p-5 space-y-4"
        >
          <div className="grid items-start gap-5 sm:grid-cols-2">
            {children}
          </div>
        </AdminCard>
      </section>
      <div className="flex flex-wrap items-center justify-end gap-4">
        {processing && (
          <p role="status" className="text-sm text-slate-500">
            {progress
              ? t('common.csv_import.uploading', {
                  percent: progress.percentage ?? 0,
                })
              : t('common.csv_import.previewing')}
          </p>
        )}
        <button
          className="button-primary"
          disabled={processing || !file || !!errors.file}
        >
          {t('common.csv_import.upload')}
        </button>
      </div>
    </form>
  );
}

export function CsvImportSteps({
  status,
}: {
  status: 'preview' | 'completed' | 'failed' | 'processing' | null;
}) {
  const t = useTranslation();
  return (
    <ol
      aria-label={t('common.csv_import.steps')}
      className="mb-6 flex flex-wrap gap-3 text-sm"
    >
      {(['prepare', 'review', 'apply'] as const).map((step, index) => {
        const active = status ? (status === 'completed' ? 2 : 1) : 0;
        return (
          <li
            key={step}
            aria-current={active === index ? 'step' : undefined}
            className={`flex items-center gap-2 rounded-full px-3 py-2 ${active === index ? 'bg-primary-tint font-medium text-primary' : 'text-slate-500'}`}
          >
            <span className="flex size-6 items-center justify-center rounded-full border border-current text-xs">
              {index + 1}
            </span>
            {t('common.csv_import.' + step)}
          </li>
        );
      })}
    </ol>
  );
}

export function CsvImportInstructions({
  help,
  columns,
}: {
  help: string;
  columns: string[];
}) {
  const t = useTranslation();
  return (
    <details className="list-card mt-6 p-5 sm:p-6">
      <summary className="cursor-pointer font-semibold">
        {t('common.csv_import.instructions')}
      </summary>
      <div className="mt-4 space-y-4">
        <p className="text-sm text-slate-600">{help}</p>
        <details>
          <summary className="cursor-pointer text-sm font-medium">
            {t('common.csv_import.columns')}
          </summary>
          <ul className="mt-3 grid gap-2 break-all text-xs sm:grid-cols-2">
            {columns.map((column) => (
              <li key={column}>
                <code>{column}</code>
              </li>
            ))}
          </ul>
        </details>
      </div>
    </details>
  );
}
