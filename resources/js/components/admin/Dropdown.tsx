import {
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
  type CSSProperties,
  type ReactNode,
} from 'react';

/**
 * Disclosure-style dropdown for the top bar. Closes on an outside click,
 * Escape (returning focus to the trigger) or when focus leaves the menu.
 */
export default function Dropdown({
  label,
  trigger,
  triggerClassName,
  panelClassName = 'w-60 p-1.5',
  align = 'right',
  floating = false,
  side = 'bottom',
  hover = false,
  open: controlledOpen,
  onOpenChange,
  children,
  keepMounted = false,
}: {
  label?: string;
  trigger: ReactNode;
  triggerClassName: string;
  panelClassName?: string;
  align?: 'left' | 'right';
  /** Positions the panel against the viewport, so a table's scroll container cannot clip it. */
  floating?: boolean;
  side?: 'bottom' | 'right';
  hover?: boolean;
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  keepMounted?: boolean;
  children: (close: () => void) => ReactNode;
}) {
  const [localOpen, setLocalOpen] = useState(false);
  const open = controlledOpen ?? localOpen;
  const setOpen = onOpenChange ?? setLocalOpen;
  const root = useRef<HTMLDivElement>(null);
  const button = useRef<HTMLButtonElement>(null);
  const panel = useId();
  const panelElement = useRef<HTMLDivElement>(null);
  const hoverTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [place, setPlace] = useState<CSSProperties>({});

  const show = (next: boolean) => {
    if (hoverTimer.current) clearTimeout(hoverTimer.current);
    if (floating && next && !open && button.current) {
      const box = button.current.getBoundingClientRect();
      if (side === 'right') {
        const top = Math.max(
          8,
          button.current.closest('aside')?.getBoundingClientRect().top ?? 8,
        );
        setPlace({
          left: box.right + 8,
          top,
          maxHeight: window.innerHeight - top - 8,
        });
      } else {
        const edge =
          align === 'left'
            ? { left: box.left }
            : { right: window.innerWidth - box.right };
        const above =
          window.innerHeight - box.bottom < 360 &&
          box.top > window.innerHeight - box.bottom;
        setPlace(
          above
            ? {
                ...edge,
                bottom: window.innerHeight - box.top + 8,
                maxHeight: box.top - 16,
              }
            : {
                ...edge,
                top: box.bottom + 8,
                maxHeight: window.innerHeight - box.bottom - 16,
              },
        );
      }
    }
    setOpen(next);
  };

  useLayoutEffect(() => {
    if (
      !open ||
      !floating ||
      side !== 'right' ||
      !button.current ||
      !panelElement.current
    )
      return;
    const box = button.current.getBoundingClientRect();
    const height = panelElement.current.getBoundingClientRect().height;
    const edge = Math.max(
      8,
      button.current.closest('aside')?.getBoundingClientRect().top ?? 8,
    );
    const top = Math.max(
      edge,
      Math.min(box.top, window.innerHeight - height - 8),
    );
    setPlace({
      left: box.right + 8,
      top,
      maxHeight: window.innerHeight - top - 8,
    });
  }, [open, floating, side]);

  useEffect(
    () => () => {
      if (hoverTimer.current) clearTimeout(hoverTimer.current);
    },
    [],
  );

  useEffect(() => {
    if (!open) return;
    const outside = (event: PointerEvent) => {
      if (!root.current?.contains(event.target as Node)) setOpen(false);
    };
    const escape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      setOpen(false);
      button.current?.focus();
    };
    const away = () => setOpen(false);
    document.addEventListener('pointerdown', outside);
    document.addEventListener('keydown', escape);
    if (floating) {
      window.addEventListener('scroll', away, true);
      window.addEventListener('resize', away);
    }
    return () => {
      document.removeEventListener('pointerdown', outside);
      document.removeEventListener('keydown', escape);
      window.removeEventListener('scroll', away, true);
      window.removeEventListener('resize', away);
    };
  }, [open, floating, setOpen]);

  return (
    <div
      ref={root}
      className="relative"
      onMouseEnter={
        hover
          ? () => {
              if (hoverTimer.current) clearTimeout(hoverTimer.current);
              hoverTimer.current = setTimeout(() => show(true), 120);
            }
          : undefined
      }
      onMouseLeave={
        hover
          ? () => {
              if (hoverTimer.current) clearTimeout(hoverTimer.current);
              hoverTimer.current = setTimeout(() => {
                if (!panelElement.current?.contains(document.activeElement))
                  setOpen(false);
              }, 150);
            }
          : undefined
      }
      onBlur={(event) => {
        // Safari does not focus clicked buttons, so a null target is not "left".
        const next = event.relatedTarget;
        if (next && !root.current?.contains(next)) setOpen(false);
      }}
    >
      <button
        ref={button}
        type="button"
        aria-label={label}
        aria-expanded={open}
        aria-controls={panel}
        className={triggerClassName}
        onClick={() => show(hover ? true : !open)}
      >
        {trigger}
      </button>
      {(open || keepMounted) && (
        <div
          id={panel}
          hidden={!open}
          ref={panelElement}
          style={floating ? place : undefined}
          className={`${floating ? 'fixed z-50 overflow-y-auto' : `absolute top-full z-30 mt-2 ${align === 'left' ? 'left-0' : 'right-0'}`} rounded-lg border border-slate-200 bg-white shadow-lg ${panelClassName}`}
        >
          {children(() => setOpen(false))}
        </div>
      )}
    </div>
  );
}

export function Chevron({ className = '' }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 20 20"
      fill="none"
      className={`size-4 shrink-0 text-slate-500 ${className}`}
    >
      <path d="m6 8 4 4 4-4" stroke="currentColor" strokeWidth="1.5" />
    </svg>
  );
}
