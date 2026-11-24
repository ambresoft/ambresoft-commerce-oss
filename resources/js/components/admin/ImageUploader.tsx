import { usePage } from '@inertiajs/react';
import {
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type DragEvent,
  type ReactNode,
} from 'react';
import { useTranslation } from '../../lib/useTranslation';
import type { SharedProps } from '../../types/page';
import RequiredMark from './RequiredMark';

export type ImageMeta = { size: number; width: number; height: number };
export type StoredImage = { id: string; url: string; meta?: ImageMeta | null };

const ACCEPT = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_BYTES = 5 * 1024 * 1024;

export function useFileSize() {
  const { i18n } = usePage<SharedProps>().props;
  const number = new Intl.NumberFormat(i18n?.locale ?? 'en', {
    maximumFractionDigits: 1,
  });
  return (bytes: number) =>
    bytes >= 1024 * 1024
      ? number.format(bytes / 1024 / 1024) + ' MB'
      : number.format(Math.max(1, Math.round(bytes / 1024))) + ' KB';
}

/** Object URLs for local previews, revoked when the files change or unmount. */
export function usePreviews(files: File[]) {
  const urls = useMemo(
    () =>
      files.map((file) =>
        typeof URL.createObjectURL === 'function'
          ? URL.createObjectURL(file)
          : '',
      ),
    [files],
  );
  useEffect(
    () => () => urls.forEach((url) => url && URL.revokeObjectURL?.(url)),
    [urls],
  );
  return urls;
}

function Icon({
  path,
  className = 'size-4',
}: {
  path: string;
  className?: string;
}) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
    >
      <path d={path} />
    </svg>
  );
}
const icons = {
  upload:
    'M12 16V4m0 0-4.5 4.5M12 4l4.5 4.5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3',
  trash:
    'M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3',
  star: 'm12 3.5 2.6 5.3 5.9.9-4.2 4.1 1 5.8L12 16.9l-5.3 2.7 1-5.8-4.2-4.1 5.9-.9L12 3.5Z',
  close: 'M6 6l12 12M18 6 6 18',
  plus: 'M12 5v14M5 12h14',
  grip: 'M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01',
};

/** Shared panel: header, drop area and the tile grid. */
export function UploadPanel({
  id,
  title,
  help,
  count,
  multiple,
  disabled,
  onFiles,
  error,
  notes,
  children,
  accept = ACCEPT.join(','),
  dropLabel,
  chooseLabel,
  hint,
  required,
}: {
  id: string;
  title: string;
  help?: string;
  count: string | null;
  multiple: boolean;
  disabled?: boolean;
  onFiles: (files: File[]) => void;
  error?: ReactNode;
  notes?: ReactNode;
  children: ReactNode;
  accept?: string;
  dropLabel?: string;
  chooseLabel?: string;
  hint?: string;
  required?: boolean;
}) {
  const t = useTranslation();
  const input = useRef<HTMLInputElement>(null);
  const [over, setOver] = useState(false);
  const hasFiles = (e: DragEvent) =>
    Array.from(e.dataTransfer?.types ?? []).includes('Files');
  return (
    <section
      aria-labelledby={id + '-title'}
      className="list-card space-y-5 p-5 sm:p-6"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 id={id + '-title'} className="text-base font-semibold">
            {title}
            {required && <RequiredMark />}
          </h3>
          {help && (
            <p className="mt-1 max-w-2xl text-sm text-slate-500">{help}</p>
          )}
        </div>
        {count && <span className="text-sm text-slate-500">{count}</span>}
      </div>
      <div
        className={`flex flex-wrap items-center gap-4 rounded-xl border-2 border-dashed p-5 transition-colors ${over ? 'border-primary bg-primary-tint' : 'border-emerald-200 bg-primary-tint/40'} ${disabled ? 'opacity-60' : ''}`}
        onDragOver={(e) => {
          if (disabled || !hasFiles(e)) return;
          e.preventDefault();
          setOver(true);
        }}
        onDragLeave={() => setOver(false)}
        onDrop={(e) => {
          if (disabled || !hasFiles(e)) return;
          e.preventDefault();
          setOver(false);
          onFiles(Array.from(e.dataTransfer.files));
        }}
      >
        <span className="flex size-14 shrink-0 items-center justify-center rounded-full bg-white text-primary shadow-sm">
          <Icon path={icons.upload} className="size-6" />
        </span>
        <div className="min-w-48 flex-1">
          <p className="font-semibold">
            {dropLabel ?? t(multiple ? 'media.drop_many' : 'media.drop_one')}
          </p>
          <p className="mt-0.5 text-sm text-slate-500">
            {hint ?? t('media.drop_hint')}
          </p>
        </div>
        <input
          ref={input}
          id={id}
          type="file"
          className="sr-only"
          tabIndex={-1}
          aria-label={title}
          aria-required={required || undefined}
          multiple={multiple}
          accept={accept}
          disabled={disabled}
          onChange={(e) => {
            onFiles(Array.from(e.target.files ?? []));
            e.target.value = '';
          }}
        />
        <button
          type="button"
          className="button-primary"
          disabled={disabled}
          onClick={() => input.current?.click()}
        >
          {chooseLabel ??
            t(multiple ? 'media.choose_many' : 'media.choose_one')}
        </button>
      </div>
      {error}
      {notes}
      {children}
    </section>
  );
}

function TileFrame({
  src,
  alt,
  selected,
  children,
  caption,
  meta,
  draggable,
  onDragStart,
  onDrop,
}: {
  src: string;
  alt: string;
  selected?: boolean;
  children?: ReactNode;
  caption: string;
  meta: string;
  draggable?: boolean;
  onDragStart?: () => void;
  onDrop?: () => void;
}) {
  return (
    <li
      className="min-w-0"
      draggable={draggable}
      onDragStart={(e) => {
        if (!onDragStart) return;
        e.dataTransfer.effectAllowed = 'move';
        onDragStart();
      }}
      onDragOver={(e) => {
        if (
          onDrop &&
          !Array.from(e.dataTransfer?.types ?? []).includes('Files')
        )
          e.preventDefault();
      }}
      onDrop={(e) => {
        if (
          !onDrop ||
          Array.from(e.dataTransfer?.types ?? []).includes('Files')
        )
          return;
        e.preventDefault();
        onDrop();
      }}
    >
      <div
        className={`group relative aspect-square overflow-hidden rounded-xl border bg-slate-50 ${selected ? 'border-primary ring-2 ring-primary/30' : 'border-slate-200'}`}
      >
        {src ? (
          <img src={src} alt={alt} className="size-full object-contain" />
        ) : (
          <span
            aria-hidden="true"
            className="flex size-full items-center justify-center text-slate-400"
          >
            <Icon path={icons.upload} className="size-8" />
          </span>
        )}
        {children}
      </div>
      <p className="mt-2 truncate text-sm font-medium" title={caption}>
        {caption}
      </p>
      <p className="text-xs text-slate-500">{meta}</p>
    </li>
  );
}

const reveal =
  'opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100';
const round =
  'flex size-9 items-center justify-center rounded-full bg-white shadow-sm ring-1 ring-black/5';

function DeleteOverlay({
  onCancel,
  onConfirm,
}: {
  onCancel: () => void;
  onConfirm: () => void;
}) {
  const t = useTranslation();
  const cancel = useRef<HTMLButtonElement>(null);
  useEffect(() => cancel.current?.focus(), []);
  return (
    <div
      role="alertdialog"
      aria-label={t('media.confirm_delete')}
      className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-slate-900/80 p-4 text-center"
      onKeyDown={(e) => e.key === 'Escape' && onCancel()}
    >
      <p className="font-semibold text-white">{t('media.confirm_delete')}</p>
      <button
        ref={cancel}
        type="button"
        className="w-full rounded-lg border border-white/40 px-3 py-1.5 text-sm font-semibold text-white hover:bg-white/10"
        onClick={onCancel}
      >
        {t('common.cancel')}
      </button>
      <button
        type="button"
        className="w-full rounded-lg bg-danger px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90"
        onClick={onConfirm}
      >
        {t('media.delete')}
      </button>
    </div>
  );
}

function Progress({
  percent,
  size,
  format,
}: {
  percent: number;
  size: number;
  format: (n: number) => string;
}) {
  const t = useTranslation();
  return (
    <>
      <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-white/75 px-5">
        <span className="text-2xl font-semibold tabular-nums">
          {Math.round(percent)}%
        </span>
        <span
          role="progressbar"
          aria-label={t('media.uploading')}
          aria-valuenow={Math.round(percent)}
          aria-valuemin={0}
          aria-valuemax={100}
          className="h-1.5 w-full overflow-hidden rounded-full bg-slate-200"
        >
          <span
            className="block h-full rounded-full bg-primary"
            style={{ width: percent + '%' }}
          />
        </span>
      </div>
      <span className="sr-only">
        {t('media.of', {
          done: format((size * percent) / 100),
          total: format(size),
        })}
      </span>
    </>
  );
}

/** Validates type, size and count before anything reaches the form. */
export function useIntake(maxNew: number) {
  const t = useTranslation();
  const [problems, setProblems] = useState<string[]>([]);
  const accept = (incoming: File[], existing: number) => {
    const issues: string[] = [];
    const ok = incoming.filter((file) => {
      if (!ACCEPT.includes(file.type)) {
        issues.push(t('media.invalid_type', { name: file.name }));
        return false;
      }
      if (file.size > MAX_BYTES) {
        issues.push(t('media.too_large', { name: file.name }));
        return false;
      }
      return true;
    });
    const room = Math.max(0, maxNew - existing);
    if (ok.length > room) issues.push(t('media.too_many', { count: maxNew }));
    setProblems(issues);
    return ok.slice(0, room);
  };
  const messages =
    problems.length > 0 ? (
      <ul role="alert" className="space-y-1 text-sm text-red-700">
        {problems.map((p) => (
          <li key={p}>{p}</li>
        ))}
      </ul>
    ) : null;
  return { accept, messages };
}

/** Product gallery: several images, one main image, drag to reorder. */
export function ImageUploader({
  title,
  help,
  items,
  primary,
  onPrimary,
  onRemove,
  onReorder,
  files,
  onFiles,
  maxNew = 10,
  progress = null,
  disabled = false,
  error,
}: {
  title: string;
  help?: string;
  items: StoredImage[];
  primary: string;
  onPrimary: (id: string) => void;
  onRemove: (id: string) => void;
  onReorder: (ids: string[]) => void;
  files: File[];
  onFiles: (files: File[]) => void;
  maxNew?: number;
  progress?: number | null;
  disabled?: boolean;
  error?: ReactNode;
}) {
  const t = useTranslation();
  const id = useId();
  const format = useFileSize();
  const previews = usePreviews(files);
  const [confirm, setConfirm] = useState<string | null>(null);
  const dragged = useRef<number | null>(null);
  const { accept, messages } = useIntake(maxNew);
  const add = (incoming: File[]) =>
    onFiles([...files, ...accept(incoming, files.length)]);
  const move = (from: number, to: number) => {
    if (to < 0 || to >= items.length || from === to) return;
    const next = items.map((item) => item.id);
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    onReorder(next);
  };
  const total = items.length + files.length;
  const meta = (m?: ImageMeta | null) =>
    m ? format(m.size) + ' · ' + m.width + ' × ' + m.height : '';

  return (
    <UploadPanel
      id={id}
      title={title}
      help={help}
      count={
        total
          ? t(total === 1 ? 'media.count_one' : 'media.count_other', {
              count: total,
            })
          : null
      }
      multiple
      disabled={disabled}
      onFiles={add}
      error={
        <>
          {messages}
          {error}
        </>
      }
      notes={
        files.length > 0 && progress === null ? (
          <p className="text-sm text-slate-500">{t('media.pending_note')}</p>
        ) : null
      }
    >
      <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        {items.map((item, index) => {
          const main = item.id === primary;
          const label = t('media.image_number', { number: index + 1 });
          return (
            <TileFrame
              key={item.id}
              src={item.url}
              alt={label}
              selected={main}
              caption={label}
              meta={meta(item.meta)}
              draggable={!disabled}
              onDragStart={() => (dragged.current = index)}
              onDrop={() => {
                if (dragged.current !== null) move(dragged.current, index);
                dragged.current = null;
              }}
            >
              {main && (
                <span className="absolute top-3 left-3 inline-flex items-center gap-1 rounded-full bg-primary px-2.5 py-1 text-xs font-semibold text-white">
                  <Icon path={icons.star} className="size-3.5" />
                  {t('media.main')}
                </span>
              )}
              {!main && !disabled && (
                <button
                  type="button"
                  className={`absolute top-3 left-3 cursor-grab text-slate-600 ${round} ${reveal}`}
                  aria-label={t('media.move', { name: label })}
                  title={t('media.move_hint')}
                  onKeyDown={(e) => {
                    if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                      e.preventDefault();
                      move(index, index - 1);
                    } else if (
                      e.key === 'ArrowRight' ||
                      e.key === 'ArrowDown'
                    ) {
                      e.preventDefault();
                      move(index, index + 1);
                    }
                  }}
                >
                  <Icon path={icons.grip} />
                </button>
              )}
              {!disabled && (
                <button
                  type="button"
                  className={`absolute top-3 right-3 text-danger ${round}`}
                  aria-label={t('media.delete_named', { name: label })}
                  onClick={() => setConfirm(item.id)}
                >
                  <Icon path={icons.trash} />
                </button>
              )}
              {!main && !disabled && (
                <button
                  type="button"
                  className={`absolute inset-x-3 bottom-3 inline-flex items-center justify-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-medium shadow-sm ring-1 ring-black/5 ${reveal}`}
                  onClick={() => onPrimary(item.id)}
                >
                  <Icon path={icons.star} className="size-4 text-primary" />
                  {t('media.set_main')}
                  <span className="sr-only">: {label}</span>
                </button>
              )}
              {confirm === item.id && (
                <DeleteOverlay
                  onCancel={() => setConfirm(null)}
                  onConfirm={() => {
                    setConfirm(null);
                    onRemove(item.id);
                  }}
                />
              )}
            </TileFrame>
          );
        })}
        {files.map((file, index) => (
          <TileFrame
            key={file.name + file.size + index}
            src={previews[index]}
            alt={file.name}
            caption={file.name}
            meta={
              progress === null
                ? format(file.size)
                : t('media.of', {
                    done: format((file.size * progress) / 100),
                    total: format(file.size),
                  })
            }
          >
            <span className="absolute top-3 left-3 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-emerald-900 shadow-sm">
              {t('media.new')}
            </span>
            {progress !== null && (
              <Progress percent={progress} size={file.size} format={format} />
            )}
            {!disabled && progress === null && (
              <button
                type="button"
                className={`absolute top-3 right-3 text-slate-700 ${round}`}
                aria-label={t('media.remove_new', { name: file.name })}
                onClick={() => onFiles(files.filter((_, i) => i !== index))}
              >
                <Icon path={icons.close} />
              </button>
            )}
          </TileFrame>
        ))}
        {!disabled && files.length < maxNew && (
          <li>
            <label
              htmlFor={id}
              className="flex aspect-square cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-200 text-sm text-slate-500 transition-colors hover:border-primary hover:bg-primary-tint/40"
            >
              <span className={round}>
                <Icon path={icons.plus} />
              </span>
              {t('media.add')}
            </label>
          </li>
        )}
      </ul>
    </UploadPanel>
  );
}

/** One optional image (category, landing page, swatch): choosing a file replaces it. */
export function SingleImageUploader({
  title,
  help,
  current,
  alt,
  file,
  onFile,
  removed,
  onRemoved,
  progress = null,
  disabled = false,
  allowRemoveCurrent = true,
  error,
}: {
  title: string;
  help?: string;
  current: { url: string; meta?: ImageMeta | null } | null;
  alt: string;
  file: File | null;
  onFile: (file: File | null) => void;
  removed: boolean;
  onRemoved: (removed: boolean) => void;
  progress?: number | null;
  disabled?: boolean;
  allowRemoveCurrent?: boolean;
  error?: ReactNode;
}) {
  const t = useTranslation();
  const id = useId();
  const format = useFileSize();
  const files = useMemo(() => (file ? [file] : []), [file]);
  const [preview] = usePreviews(files);
  const [confirm, setConfirm] = useState(false);
  const { accept, messages } = useIntake(1);
  const choose = (incoming: File[]) => {
    const [next] = accept(incoming.slice(0, 1), 0);
    if (next) {
      onFile(next);
      onRemoved(false);
    }
  };
  const showCurrent = !file && current && !removed;
  return (
    <UploadPanel
      id={id}
      title={title}
      help={help}
      count={null}
      multiple={false}
      disabled={disabled}
      onFiles={choose}
      error={
        <>
          {messages}
          {error}
        </>
      }
      notes={
        removed && !file && current ? (
          <p role="status" className="text-sm text-slate-600">
            {t('media.removal_pending')}{' '}
            <button
              type="button"
              className="text-link"
              onClick={() => onRemoved(false)}
            >
              {t('media.undo')}
            </button>
          </p>
        ) : file && progress === null ? (
          <p className="text-sm text-slate-500">
            {t('media.pending_note_one')}
          </p>
        ) : null
      }
    >
      {(showCurrent || file) && (
        <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
          {file ? (
            <TileFrame
              src={preview}
              alt={file.name}
              selected
              caption={file.name}
              meta={
                progress === null
                  ? format(file.size)
                  : t('media.of', {
                      done: format((file.size * progress) / 100),
                      total: format(file.size),
                    })
              }
            >
              <span className="absolute top-3 left-3 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-emerald-900 shadow-sm">
                {t('media.new')}
              </span>
              {progress !== null && (
                <Progress percent={progress} size={file.size} format={format} />
              )}
              {!disabled && progress === null && (
                <button
                  type="button"
                  className={`absolute top-3 right-3 text-slate-700 ${round}`}
                  aria-label={t('media.remove_new', { name: file.name })}
                  onClick={() => onFile(null)}
                >
                  <Icon path={icons.close} />
                </button>
              )}
            </TileFrame>
          ) : (
            current && (
              <TileFrame
                src={current.url}
                alt={alt}
                selected
                caption={t('media.current')}
                meta={
                  current.meta
                    ? format(current.meta.size) +
                      ' · ' +
                      current.meta.width +
                      ' × ' +
                      current.meta.height
                    : ''
                }
              >
                {!disabled && allowRemoveCurrent && (
                  <button
                    type="button"
                    className={`absolute top-3 right-3 text-danger ${round}`}
                    aria-label={t('media.delete_named', {
                      name: t('media.current'),
                    })}
                    onClick={() => setConfirm(true)}
                  >
                    <Icon path={icons.trash} />
                  </button>
                )}
                {confirm && (
                  <DeleteOverlay
                    onCancel={() => setConfirm(false)}
                    onConfirm={() => {
                      setConfirm(false);
                      onRemoved(true);
                    }}
                  />
                )}
              </TileFrame>
            )
          )}
        </ul>
      )}
    </UploadPanel>
  );
}
