import { useId, type ReactNode } from 'react';
import { useTranslation } from '../../lib/useTranslation';
import type { Attachment } from '../../types/attachments';
import FormField from '../auth/FormField';
import { UploadPanel, useFileSize } from './ImageUploader';

type Entry = { file: Attachment; index: number };
/** Reindex replacement references when an unsaved upload is removed. */
export function removeAttachmentUpload<
  T extends {
    files: Attachment[];
    file_uploads: File[];
    file_upload_purposes?: string[];
  },
>(state: T, index: number): T {
  return {
    ...state,
    file_uploads: state.file_uploads.filter((_, n) => n !== index),
    files: state.files.map((file) =>
      file.replacement == null || file.replacement < index
        ? file
        : {
            ...file,
            replacement:
              file.replacement === index ? undefined : file.replacement - 1,
          },
    ),
    ...(state.file_upload_purposes
      ? {
          file_upload_purposes: state.file_upload_purposes.filter(
            (_, n) => n !== index,
          ),
        }
      : {}),
  };
}
export default function AttachmentUploader({
  title,
  help,
  items,
  pending,
  onAdd,
  onName,
  onRemove,
  onMove,
  onReplace,
  onRemovePending,
  disabled,
  required,
  progress,
  error,
  fieldError,
}: {
  title: string;
  help?: string;
  items: Entry[];
  pending: { file: File; index: number }[];
  onAdd: (files: File[]) => void;
  onName: (index: number, name: string) => void;
  onRemove: (index: number) => void;
  onMove: (index: number, by: -1 | 1) => void;
  onReplace: (index: number, file: File) => void;
  onRemovePending: (index: number) => void;
  disabled?: boolean;
  required?: boolean;
  progress?: number | null;
  error?: ReactNode;
  fieldError?: (index: number) => string | undefined;
}) {
  const t = useTranslation();
  const id = useId();
  const size = useFileSize();
  const icon = (name: string) => (
    <span
      aria-hidden="true"
      className="flex size-14 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-xs font-semibold text-primary"
    >
      {name.split('.').pop()?.toUpperCase()}
    </span>
  );
  return (
    <UploadPanel
      id={id}
      title={title}
      help={help}
      multiple
      count={String(items.length + pending.length)}
      disabled={disabled}
      onFiles={onAdd}
      accept=".pdf,.zip,.txt,.jpg,.jpeg,.png,.webp"
      required={required}
      dropLabel={t('media.files_drop')}
      chooseLabel={t('media.files_choose')}
      hint={t('media.files_hint')}
      error={error}
    >
      {progress != null && (
        <progress
          aria-label={t('media.upload_progress')}
          max={100}
          value={progress}
          className="h-2 w-full accent-emerald-600"
        />
      )}
      <div className="space-y-3">
        {items.map(({ file, index }, position) => (
          <article
            key={file.id}
            className="flex flex-wrap items-start gap-4 rounded-xl border border-slate-200 bg-white p-4"
          >
            {icon(file.original_name)}
            <div className="min-w-48 flex-1 space-y-2">
              <p className="break-all text-xs text-slate-500">
                {file.original_name}
                {file.byte_size != null ? ` · ${size(file.byte_size)}` : ''}
              </p>
              <FormField
                id={`${id}-${file.id}`}
                label={t('products.display_name')}
                requiredIndicator
                value={file.display_name}
                disabled={disabled}
                error={fieldError?.(index)}
                onChange={(event) => onName(index, event.target.value)}
              />
              {file.replacement != null && (
                <p className="text-xs text-emerald-700">
                  {t('media.file_replacement_pending')}
                </p>
              )}
              <div className="flex flex-wrap items-center gap-2">
                {file.url && (
                  <a
                    href={file.url}
                    target="_blank"
                    rel="noopener"
                    className="button-secondary text-xs"
                  >
                    {t('media.file_open')}
                  </a>
                )}
                <label
                  className={`button-secondary cursor-pointer text-xs focus-within:ring-2 focus-within:ring-emerald-500 ${disabled ? 'pointer-events-none opacity-50' : ''}`}
                >
                  {t('products.replace')}
                  <input
                    type="file"
                    className="sr-only"
                    disabled={disabled}
                    aria-label={t('products.replace')}
                    accept=".pdf,.zip,.txt,.jpg,.jpeg,.png,.webp"
                    onChange={(event) => {
                      const file = event.target.files?.[0];
                      if (file) onReplace(index, file);
                      event.target.value = '';
                    }}
                  />
                </label>
                <button
                  type="button"
                  className="button-secondary text-xs"
                  disabled={disabled || position === 0}
                  onClick={() => onMove(index, -1)}
                >
                  {t('products.up')}
                </button>
                <button
                  type="button"
                  className="button-secondary text-xs"
                  disabled={disabled || position === items.length - 1}
                  onClick={() => onMove(index, 1)}
                >
                  {t('products.down')}
                </button>
                <button
                  type="button"
                  className="button-secondary text-xs text-red-700"
                  disabled={disabled}
                  onClick={() => onRemove(index)}
                >
                  {t('products.remove')}
                </button>
              </div>
            </div>
          </article>
        ))}
        {pending.map(({ file, index }) => (
          <div
            key={index}
            className="flex items-center gap-4 rounded-xl border border-dashed border-emerald-300 bg-primary-tint/30 p-4"
          >
            {icon(file.name)}
            <div className="min-w-0 flex-1">
              <p className="break-all text-sm font-medium">{file.name}</p>
              <p className="text-xs text-slate-500">
                {size(file.size)} · {t('media.file_pending')}
              </p>
            </div>
            <button
              type="button"
              className="button-secondary text-xs"
              disabled={disabled}
              onClick={() => onRemovePending(index)}
            >
              {t('products.remove')}
            </button>
          </div>
        ))}
        {items.length + pending.length === 0 && (
          <p className="py-3 text-center text-sm text-slate-500">
            {t('media.files_empty')}
          </p>
        )}
      </div>
    </UploadPanel>
  );
}
