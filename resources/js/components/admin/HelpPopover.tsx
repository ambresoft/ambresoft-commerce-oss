import { useId, useState, type ReactNode } from 'react';

/** Contextual help available to pointer, keyboard and touch users. */
export default function HelpPopover({
  label,
  children,
}: {
  label: string;
  children: ReactNode;
}) {
  const id = useId();
  const [open, setOpen] = useState(false);
  return (
    <span
      className="group relative inline-flex shrink-0"
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
      onFocus={() => setOpen(true)}
      onBlur={() => setOpen(false)}
      onKeyDown={(event) => {
        if (event.key === 'Escape') setOpen(false);
      }}
    >
      <button
        type="button"
        aria-label={label + ' (?)'}
        onClick={() => setOpen(true)}
        aria-describedby={open ? id : undefined}
        className="flex size-5 items-center justify-center rounded-full border border-slate-300 text-xs text-slate-500 focus-visible:ring-2 focus-visible:ring-primary"
      >
        ?
      </button>
      {open && (
        <span
          id={id}
          role="tooltip"
          className="pointer-events-none invisible absolute top-full left-0 z-30 mt-2 w-64 max-w-[calc(100vw-4rem)] rounded-lg border border-slate-200 bg-white p-3 text-xs leading-5 text-slate-700 opacity-0 shadow-lg group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100"
        >
          {children}
        </span>
      )}
    </span>
  );
}
