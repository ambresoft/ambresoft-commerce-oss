import { useId, useMemo, useRef, useState, type DragEvent } from 'react';
import { usePreviews } from '../../../components/admin/ImageUploader';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import { useTranslation } from '../../../lib/useTranslation';

/** A reel stays attached until a replacement is saved; removing a draft restores the saved preview. */
export default function VideoUploader({
  file,
  current,
  poster,
  maxBytes,
  progress,
  disabled,
  error,
  onFile,
}: {
  file: File | null;
  current: string | null;
  poster?: string;
  maxBytes: number;
  progress: number | null;
  disabled: boolean;
  error?: string;
  onFile: (file: File | null) => void;
}) {
  const t = useTranslation();
  const id = useId();
  const input = useRef<HTMLInputElement>(null);
  const files = useMemo(() => (file ? [file] : []), [file]);
  const [preview] = usePreviews(files);
  const [over, setOver] = useState(false);
  const [invalid, setInvalid] = useState('');
  const source = file ? preview : current;
  const choose = (next?: File) => {
    if (!next || disabled) return;
    if (
      (next.type && next.type !== 'video/mp4') ||
      (!next.type && !next.name.toLowerCase().endsWith('.mp4')) ||
      next.size > maxBytes ||
      next.size === 0
    ) {
      setInvalid(t('reels.video_invalid'));
      return;
    }
    setInvalid('');
    onFile(next);
  };
  const acceptsDrop = (e: DragEvent) =>
    Array.from(e.dataTransfer.types).includes('Files');
  return (
    <section
      aria-labelledby={id + '-title'}
      className="overflow-hidden rounded-xl border border-slate-200 bg-white"
    >
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
        <div className="flex items-center gap-3">
          <span className="flex size-10 items-center justify-center rounded-xl bg-primary-tint text-primary">
            <Icon name="file" className="size-5" />
          </span>
          <div>
            <h2 id={id + '-title'} className="text-base font-semibold">
              {t('reels.video')}
            </h2>
            <p className="mt-0.5 text-xs text-slate-500">
              {t('reels.video_format')}
            </p>
          </div>
        </div>
        <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
          {t(
            file
              ? 'reels.video_pending'
              : current
                ? 'reels.video_saved'
                : 'reels.video_required',
          )}
        </span>
      </div>
      <div className="grid gap-5 p-5 lg:grid-cols-[minmax(200px,0.85fr)_minmax(0,1.15fr)]">
        <div className="flex min-h-56 items-center justify-center overflow-hidden rounded-xl bg-slate-950">
          {source ? (
            <video
              key={source}
              src={source}
              poster={file ? undefined : poster}
              controls
              playsInline
              preload="metadata"
              className="max-h-80 w-full object-contain"
            />
          ) : (
            <div className="flex flex-col items-center gap-3 px-5 py-10 text-slate-400">
              <svg
                aria-hidden="true"
                className="size-14"
                viewBox="0 0 48 48"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
              >
                <rect x="6" y="4" width="28" height="40" rx="6" />
                <path d="m34 18 8-5v22l-8-5M17 17l9 7-9 7z" />
              </svg>
              <span className="text-xs">9:16 · MP4</span>
            </div>
          )}
        </div>
        <div
          className={`flex flex-col justify-center gap-4 rounded-xl border-2 border-dashed p-5 transition-colors ${over ? 'border-primary bg-primary-tint' : 'border-slate-200 bg-slate-50'} ${disabled ? 'opacity-60' : ''}`}
          onDragOver={(e) => {
            if (disabled || !acceptsDrop(e)) return;
            e.preventDefault();
            setOver(true);
          }}
          onDragLeave={() => setOver(false)}
          onDrop={(e) => {
            if (disabled || !acceptsDrop(e)) return;
            e.preventDefault();
            setOver(false);
            choose(e.dataTransfer.files[0]);
          }}
        >
          <span className="flex size-11 items-center justify-center rounded-full border border-slate-200 bg-white text-primary">
            <Icon name="upload" className="size-5" />
          </span>
          <div>
            <p className="font-semibold">
              {t(source ? 'reels.video_replace_title' : 'reels.video_drop')}
            </p>
            <p className="mt-1 text-sm leading-6 text-slate-500">
              {t('reels.video_drop_help')}
            </p>
          </div>
          <input
            ref={input}
            id={id}
            type="file"
            accept="video/mp4,.mp4"
            className="sr-only"
            tabIndex={-1}
            aria-label={t('reels.video')}
            aria-describedby={id + '-error'}
            disabled={disabled}
            onChange={(e) => {
              choose(e.target.files?.[0]);
              e.target.value = '';
            }}
          />
          <div className="flex flex-wrap items-center gap-3">
            <button
              type="button"
              className="button-primary gap-2"
              disabled={disabled}
              onClick={() => input.current?.click()}
            >
              <Icon name="plus" />
              {t(source ? 'reels.video_replace' : 'reels.video_choose')}
            </button>
            {file && (
              <IconButton
                icon="x"
                label={t('media.remove_new', { name: file.name })}
                disabled={disabled}
                onClick={() => {
                  onFile(null);
                  setInvalid('');
                }}
              />
            )}
          </div>
          {file && (
            <div
              className="min-w-0 rounded-lg border border-slate-200 bg-white px-3 py-2.5"
              role="status"
            >
              <p className="truncate text-sm font-medium" title={file.name}>
                {file.name}
              </p>
              <p className="mt-1 text-xs text-slate-500">
                {new Intl.NumberFormat(undefined, {
                  maximumFractionDigits: 1,
                }).format(file.size / 1048576)}{' '}
                MiB · MP4
              </p>
            </div>
          )}
          <p className="text-xs leading-5 text-slate-500">
            {t('reels.video_save_hint')}
          </p>
        </div>
      </div>
      {(invalid || error) && (
        <p
          id={id + '-error'}
          role="alert"
          className="px-5 pb-5 text-sm text-red-700"
        >
          {invalid || error}
        </p>
      )}
      {progress !== null && (
        <div className="space-y-2 border-t border-slate-200 px-5 py-4">
          <div className="flex justify-between text-xs font-medium text-slate-600">
            <span>{t('media.uploading')}</span>
            <span>{progress}%</span>
          </div>
          <progress
            aria-label={t('reels.video')}
            max={100}
            value={progress}
            className="h-2 w-full accent-[var(--color-primary)]"
          />
        </div>
      )}
    </section>
  );
}
