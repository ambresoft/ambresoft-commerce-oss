import AdminCard from '../AdminCard';
import { useId, useRef, type ReactNode } from 'react';
import Select from '../Select';
import { useTranslation } from '../../../lib/useTranslation';

export function Section({
  title,
  description,
  iconKey = 'content',
  children,
}: {
  title: string;
  iconKey?: string;
  /** A short explanation under the title, as on Settings → General. */
  description?: string;
  children: ReactNode;
}) {
  return (
    <AdminCard title={title} iconKey={iconKey}>
      <div className="space-y-5">
        {description && (
          <p className="text-sm leading-6 text-slate-500">{description}</p>
        )}
        {children}
      </div>
    </AdminCard>
  );
}

/** A sub-heading inside a Section, separated from what precedes it by a rule (Settings → General style). */
export function SubSection({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <div className="space-y-5 border-t border-slate-200 pt-6 first:border-t-0 first:pt-0">
      <div>
        <h3 className="text-base font-semibold">{title}</h3>
        {description && (
          <p className="mt-1 text-sm text-slate-600">{description}</p>
        )}
      </div>
      {children}
    </div>
  );
}

/** Label above a full-width control, hint and error below (the add-on screens' layout). */
export function Field({
  id,
  label,
  help,
  error,
  required = false,
  children,
}: {
  id: string;
  label: string;
  help?: string;
  error?: string;
  required?: boolean;
  children: ReactNode;
}) {
  return (
    <div className="space-y-2">
      <label htmlFor={id} className="block text-sm font-medium text-slate-800">
        {label}
        {required && (
          <span aria-hidden="true" className="ml-0.5 text-red-600">
            *
          </span>
        )}
      </label>
      {children}
      {help && (
        <p id={id + '-help'} className="text-xs leading-5 text-slate-500">
          {help}
        </p>
      )}
      {error && (
        <p role="alert" className="text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  );
}

/** A checkbox with its label beside it and the explanation beneath (Settings → General style). */
export function CheckField({
  id,
  label,
  help,
  checked,
  onChange,
  disabled,
}: {
  id: string;
  label: string;
  help?: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <div className="flex items-start gap-3">
      <input
        id={id}
        type="checkbox"
        className="mt-0.5 size-4 accent-[var(--color-primary)]"
        checked={checked}
        disabled={disabled}
        aria-describedby={help ? id + '-help' : undefined}
        onChange={(e) => onChange(e.target.checked)}
      />
      <div>
        <label htmlFor={id} className="text-sm font-medium text-slate-800">
          {label}
        </label>
        {help && (
          <p
            id={id + '-help'}
            className="mt-1 text-xs leading-5 text-slate-500"
          >
            {help}
          </p>
        )}
      </div>
    </div>
  );
}

/** Label column on the left (stacked on phones), control on the right. */
export function Row({
  id,
  label,
  help,
  error,
  required = false,
  children,
}: {
  id: string;
  label: string;
  help?: string;
  error?: string;
  required?: boolean;
  children: ReactNode;
}) {
  return (
    <div className="grid gap-2 sm:grid-cols-[minmax(10rem,15rem)_1fr] sm:items-start sm:gap-6">
      <label
        htmlFor={id}
        className="pt-2 text-sm font-medium text-slate-800 sm:text-right"
      >
        {label}
        {required && (
          <span aria-hidden="true" className="ml-0.5 text-red-600">
            *
          </span>
        )}
      </label>
      <div className="min-w-0 space-y-1.5">
        {children}
        {help && (
          <p id={id + '-help'} className="text-xs leading-5 text-slate-500">
            {help}
          </p>
        )}
        {error && (
          <p role="alert" className="text-sm text-red-700">
            {error}
          </p>
        )}
      </div>
    </div>
  );
}

export function Choice<T extends string>({
  id,
  value,
  options,
  onChange,
  disabled,
  className = 'max-w-md',
}: {
  id: string;
  value: T;
  options: { value: T; label: string }[];
  onChange: (value: T) => void;
  disabled?: boolean;
  className?: string;
}) {
  return (
    <div className={className}>
      <Select
        id={id}
        value={value}
        options={options}
        disabled={disabled}
        onChange={(next) => onChange(next as T)}
      />
    </div>
  );
}

export function Check({
  id,
  checked,
  onChange,
  disabled,
  label,
}: {
  id: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
  label?: string;
}) {
  return (
    <label className="inline-flex items-center gap-2 pt-2 text-sm">
      <input
        id={id}
        type="checkbox"
        className="size-4 accent-[var(--color-primary)]"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
      />
      {label && <span>{label}</span>}
    </label>
  );
}

/** Unchecked = automatic colour from the colour scheme (null); checked = a custom #rrggbb. */
export function ColorField({
  id,
  value,
  onChange,
  disabled,
  fallback = '#000000',
}: {
  id: string;
  value: string | null;
  onChange: (value: string | null) => void;
  disabled?: boolean;
  fallback?: string;
}) {
  const t = useTranslation();
  return (
    <div className="flex items-center gap-3 pt-1">
      <input
        id={id}
        type="checkbox"
        aria-label={t('banners.color_custom')}
        className="size-4 accent-[var(--color-primary)]"
        checked={value !== null}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked ? fallback : null)}
      />
      <input
        type="color"
        aria-label={t('banners.color_custom')}
        className="h-9 w-14 cursor-pointer rounded border border-slate-300 bg-white p-1 disabled:cursor-not-allowed disabled:opacity-40"
        value={value ?? fallback}
        disabled={disabled || value === null}
        onChange={(e) => onChange(e.target.value)}
      />
      {value && (
        <span className="font-mono text-xs text-slate-500">{value}</span>
      )}
    </div>
  );
}

/**
 * Tablet/phone wrapper: the control edits the device override when checked,
 * otherwise it shows the inherited PC value read-only.
 */
export function Override({
  label,
  active,
  onToggle,
  disabled,
  children,
}: {
  label: string;
  active: boolean;
  onToggle: (active: boolean) => void;
  disabled?: boolean;
  children: ReactNode;
}) {
  const t = useTranslation();
  return (
    <div className="flex flex-wrap items-start gap-3">
      <label className="inline-flex shrink-0 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-medium text-slate-700">
        <input
          type="checkbox"
          className="size-3.5 accent-[var(--color-primary)]"
          aria-label={t('banners.override_label', { field: label })}
          checked={active}
          disabled={disabled}
          onChange={(e) => onToggle(e.target.checked)}
        />
        {t('banners.override')}
      </label>
      <div className={`min-w-0 flex-1 ${active ? '' : 'opacity-60'}`}>
        {children}
        {!active && (
          <p className="mt-1 text-xs text-slate-500">
            {t('banners.inherited')}
          </p>
        )}
      </div>
    </div>
  );
}

/** MP4 chooser with the current/selected/removed states of a stored video. */
export function VideoField({
  hasCurrent,
  file,
  removed,
  onFile,
  onRemoved,
  maxBytes,
  disabled,
  error,
}: {
  hasCurrent: boolean;
  file: File | null;
  removed: boolean;
  onFile: (file: File | null) => void;
  onRemoved: (removed: boolean) => void;
  maxBytes: number;
  disabled?: boolean;
  error?: string;
}) {
  const t = useTranslation();
  const input = useRef<HTMLInputElement>(null);
  const id = useId();
  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-3">
        <input
          ref={input}
          id={id}
          type="file"
          accept="video/mp4"
          className="sr-only"
          disabled={disabled}
          onChange={(e) => {
            const next = e.target.files?.[0] ?? null;
            e.target.value = '';
            if (!next) return;
            if (next.type && next.type !== 'video/mp4') return;
            if (next.size > maxBytes) return;
            onFile(next);
            onRemoved(false);
          }}
        />
        <button
          type="button"
          className="button-secondary"
          disabled={disabled}
          onClick={() => input.current?.click()}
        >
          {t('banners.video_choose')}
        </button>
        {(file || (hasCurrent && !removed)) && (
          <button
            type="button"
            className="text-sm text-red-700 underline underline-offset-4"
            disabled={disabled}
            onClick={() => {
              onFile(null);
              if (hasCurrent) onRemoved(true);
            }}
          >
            {t('banners.video_remove')}
          </button>
        )}
      </div>
      <p className="text-xs text-slate-500">
        {file
          ? t('banners.video_selected', { name: file.name })
          : hasCurrent && removed
            ? t('banners.video_removed')
            : hasCurrent
              ? t('banners.video_current')
              : t('banners.background_video_help')}
        {hasCurrent && removed && !file && (
          <button
            type="button"
            className="ml-2 underline underline-offset-4"
            onClick={() => onRemoved(false)}
          >
            {t('banners.undo')}
          </button>
        )}
      </p>
      {error && (
        <p role="alert" className="text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  );
}
