import { useState, type InputHTMLAttributes, type ReactNode } from 'react';
import UrlSlugInput from '../admin/UrlSlugInput';
import RequiredMark from '../admin/RequiredMark';

type Props = InputHTMLAttributes<HTMLInputElement> & {
  id: string;
  label: string;
  error?: string;
  urlPrefix?: string;
  hint?: string;
  tooltip?: string;
  tooltipLabel?: string;
  /** Indicates server completeness without blocking draft or hidden-tab saves. */
  requiredIndicator?: boolean;
  /** Shown at the end of the label row, e.g. a "Forgot password?" link. */
  labelAside?: ReactNode;
  /** Password fields can show what was typed, through an eye toggle. */
  revealable?: boolean;
  revealLabel?: { show: string; hide: string };
};

export default function FormField({
  id,
  label,
  error,
  hint,
  tooltip,
  tooltipLabel,
  requiredIndicator,
  labelAside,
  revealable,
  revealLabel,
  urlPrefix,
  ...input
}: Props) {
  const [revealed, setRevealed] = useState(false);
  const mandatory = requiredIndicator ?? input.required;
  const description = [hint && `${id}-hint`, error && `${id}-error`]
    .filter(Boolean)
    .join(' ');

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        <label
          htmlFor={id}
          className="block text-sm font-medium text-slate-800"
        >
          {label}
          {mandatory && <RequiredMark />}
        </label>
        {tooltip && (
          <span className="group relative inline-flex">
            <button
              type="button"
              aria-label={tooltipLabel ?? label}
              aria-describedby={`${id}-tooltip`}
              className="flex size-5 items-center justify-center rounded-full border border-slate-300 text-[11px] font-semibold text-slate-600 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-primary"
            >
              ?
            </button>
            <span
              id={`${id}-tooltip`}
              role="tooltip"
              className="pointer-events-none invisible absolute bottom-full left-0 z-20 mb-2 w-64 max-w-[calc(100vw-4rem)] rounded-lg bg-slate-900 p-3 text-xs leading-5 text-white opacity-0 shadow-lg transition-opacity group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100"
            >
              {tooltip}
            </span>
          </span>
        )}
        {labelAside && <span className="ml-auto text-sm">{labelAside}</span>}
      </div>
      <div className={revealable ? 'field-wrap' : undefined}>
        {urlPrefix !== undefined ? (
          <UrlSlugInput
            prefix={urlPrefix}
            {...input}
            type={revealable && revealed ? 'text' : input.type}
            id={id}
            name={input.name ?? id}
            aria-label={input['aria-label'] ?? label}
            aria-invalid={error ? true : undefined}
            aria-required={mandatory || undefined}
            aria-describedby={description || undefined}
          />
        ) : (
          <input
            {...input}
            type={revealable && revealed ? 'text' : input.type}
            id={id}
            name={input.name ?? id}
            className="field"
            aria-label={input['aria-label'] ?? label}
            aria-invalid={error ? true : undefined}
            aria-required={mandatory || undefined}
            aria-describedby={description || undefined}
          />
        )}
        {revealable && (
          <button
            type="button"
            className="field-reveal"
            aria-label={
              revealed
                ? (revealLabel?.hide ?? 'Hide')
                : (revealLabel?.show ?? 'Show')
            }
            onClick={() => setRevealed(!revealed)}
          >
            <svg
              viewBox="0 0 24 24"
              width="22"
              height="22"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
              <circle cx="12" cy="12" r="3" />
              {revealed && <path d="M3 3l18 18" />}
            </svg>
          </button>
        )}
      </div>
      {hint && (
        <p id={`${id}-hint`} className="text-xs leading-5 text-slate-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  );
}
