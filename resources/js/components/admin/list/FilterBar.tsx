import OptionLabel from '../OptionLabel';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import Dropdown, { Chevron } from '../Dropdown';

export type FilterOption = { value: string; label: string; color?: string };

/**
 * Filter area at the top of a list card: search (submitted with Enter) and an
 * optional sort control on the first row, filter chips and reset on the second.
 */
export default function FilterBar({
  search,
  sort,
  chips,
  resetHref,
  active,
}: {
  search: ReactNode;
  sort?: ReactNode;
  chips?: ReactNode;
  resetHref: string;
  active: boolean;
}) {
  const t = useTranslation();
  return (
    <div className="space-y-3 p-4 sm:p-5">
      <div className="flex flex-wrap gap-3">
        <div className="min-w-56 flex-1">{search}</div>
        {sort}
      </div>
      {(chips || active) && (
        <div
          role="group"
          aria-label={t('common.filters')}
          className="flex flex-wrap items-center gap-2"
        >
          {chips}
          {active && (
            <Link
              href={resetHref}
              className="ml-auto px-1 text-sm text-slate-500 hover:text-slate-900"
            >
              {t('common.reset')}
            </Link>
          )}
        </div>
      )}
    </div>
  );
}

export function SearchField({
  id,
  value,
  placeholder,
  onChange,
  onSubmit,
  embedded = false,
}: {
  id: string;
  value: string;
  placeholder: string;
  onChange: (value: string) => void;
  onSubmit: () => void;
  embedded?: boolean;
}) {
  const t = useTranslation();
  const Container = embedded ? 'div' : 'form';
  return (
    <Container
      role="search"
      className="relative"
      onSubmit={(event) => {
        event.preventDefault();
        onSubmit();
      }}
    >
      <label htmlFor={id} className="sr-only">
        {t('common.search')}
      </label>
      <svg
        aria-hidden="true"
        viewBox="0 0 20 20"
        fill="none"
        className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
      >
        <circle cx="9" cy="9" r="5.5" stroke="currentColor" strokeWidth="1.5" />
        <path d="m13.5 13.5 3 3" stroke="currentColor" strokeWidth="1.5" />
      </svg>
      <input
        id={id}
        type="search"
        onKeyDown={(event) => {
          if (embedded && event.key === 'Enter') {
            event.preventDefault();
            event.stopPropagation();
            onSubmit();
          }
        }}
        maxLength={100}
        value={value}
        placeholder={placeholder}
        onChange={(event) => onChange(event.target.value)}
        className="h-11 w-full rounded-lg border border-slate-200 bg-slate-50 pr-16 pl-10 text-sm placeholder:text-slate-400 focus:border-primary-hover focus:bg-white"
      />
      <kbd
        aria-hidden="true"
        className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 rounded border border-slate-200 bg-white px-1.5 py-0.5 font-sans text-[11px] text-slate-400"
      >
        Enter
      </kbd>
    </Container>
  );
}

function Check() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 20 20"
      fill="none"
      className="size-4 shrink-0 text-primary"
    >
      <path
        d="m4.5 10.5 3.5 3.5 7.5-8"
        stroke="currentColor"
        strokeWidth="1.75"
      />
    </svg>
  );
}

function OptionList({
  options,
  value,
  onChoose,
}: {
  options: FilterOption[];
  value: string;
  onChoose: (value: string) => void;
}) {
  const t = useTranslation();
  if (!options.length)
    return (
      <p className="px-3 py-2 text-sm text-slate-500">
        {t('common.no_options')}
      </p>
    );
  return (
    <ul className="max-h-72 overflow-y-auto">
      {options.map((option) => {
        const selected = option.value === value;
        return (
          <li key={option.value}>
            <button
              type="button"
              aria-pressed={selected}
              className={`flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm hover:bg-slate-50 ${selected ? 'font-semibold text-emerald-900' : 'text-slate-700'}`}
              onClick={() => onChoose(option.value)}
            >
              <OptionLabel label={option.label} color={option.color} />
              {selected && <Check />}
            </button>
          </li>
        );
      })}
    </ul>
  );
}

/** "Label: Value ▾" chip that applies its choice immediately. */
export function FilterChip({
  label,
  value,
  options,
  onChange,
  search,
}: {
  label: string;
  value: string;
  options: FilterOption[];
  onChange: (value: string) => void;
  search?: { value: string; onChange: (value: string) => void };
}) {
  const t = useTranslation();
  const current = options.find((option) => option.value === value);
  return (
    <Dropdown
      align="left"
      triggerClassName={`chip ${value ? 'border-emerald-200 bg-primary-tint/50' : ''}`}
      panelClassName="w-64 p-1.5"
      trigger={
        <>
          {label}:
          <span className="max-w-40 truncate font-semibold text-slate-900">
            <OptionLabel
              label={current?.label ?? t('common.all')}
              color={current?.color}
            />
          </span>
          <Chevron />
        </>
      }
    >
      {(close) => (
        <>
          {search && (
            <input
              type="search"
              aria-label={t('common.search_options')}
              placeholder={t('common.search_options')}
              maxLength={100}
              className="field mb-1.5"
              value={search.value}
              onChange={(event) => search.onChange(event.target.value)}
            />
          )}
          <OptionList
            options={options}
            value={value}
            onChoose={(choice) => {
              close();
              if (choice !== value) onChange(choice);
            }}
          />
        </>
      )}
    </Dropdown>
  );
}

/** Sort field menu joined with a direction toggle. */
export function SortControl({
  value,
  direction,
  options,
  onChange,
}: {
  value: string;
  direction: 'asc' | 'desc';
  options: FilterOption[];
  onChange: (value: string, direction: 'asc' | 'desc') => void;
}) {
  const t = useTranslation();
  const current = options.find((option) => option.value === value)?.label;
  const next = direction === 'asc' ? 'desc' : 'asc';
  return (
    <div className="flex h-11 items-stretch rounded-lg border border-slate-200 bg-white">
      <Dropdown
        triggerClassName="flex h-full items-center gap-1.5 rounded-l-lg px-3.5 text-sm text-slate-500 hover:bg-slate-50"
        panelClassName="w-56 p-1.5"
        trigger={
          <>
            {t('common.sort')}:
            <span className="font-semibold text-slate-900">{current}</span>
            <Chevron />
          </>
        }
      >
        {(close) => (
          <OptionList
            options={options}
            value={value}
            onChoose={(choice) => {
              close();
              if (choice !== value) onChange(choice, direction);
            }}
          />
        )}
      </Dropdown>
      <button
        type="button"
        aria-label={t(next === 'asc' ? 'common.sort_asc' : 'common.sort_desc')}
        title={t(next === 'asc' ? 'common.sort_asc' : 'common.sort_desc')}
        className="flex w-10 items-center justify-center rounded-r-lg border-l border-slate-200 text-slate-600 hover:bg-slate-50"
        onClick={() => onChange(value, next)}
      >
        <svg
          aria-hidden="true"
          viewBox="0 0 20 20"
          fill="none"
          className={`size-4 transition-transform ${direction === 'asc' ? 'rotate-180' : ''}`}
        >
          <path
            d="M10 4v12m0 0-4.5-4.5M10 16l4.5-4.5"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
      </button>
    </div>
  );
}
