import OptionLabel from './OptionLabel';
import {
  useEffect,
  useId,
  useRef,
  useState,
  type CSSProperties,
  type KeyboardEvent,
} from 'react';
import { createPortal } from 'react-dom';
import { Chevron } from './Dropdown';

// Fixed coordinates below (or above) the trigger, measured before every render
// of the list so it is never laid out at the end of <body>.
function measure(trigger: HTMLElement): CSSProperties {
  const rect = trigger.getBoundingClientRect();
  const below = window.innerHeight - rect.bottom;
  const up = below < 240 && rect.top > below;
  return {
    position: 'fixed',
    left: rect.left,
    minWidth: rect.width,
    maxHeight: Math.max(160, Math.min(288, (up ? rect.top : below) - 12)),
    ...(up
      ? { bottom: window.innerHeight - rect.top + 6 }
      : { top: rect.bottom + 6 }),
  };
}

export type SelectOption = {
  value: string;
  label: string;
  color?: string;
  disabled?: boolean;
};

/**
 * Select-only combobox (WAI-ARIA APG) styled like the top-bar dropdowns. The
 * trigger is a <button>, so <label htmlFor> and wrapping labels still name it.
 * The list renders in a portal so table and card overflow cannot clip it.
 */
export default function Select({
  id,
  value,
  options,
  onChange,
  disabled = false,
  invalid = false,
  className = '',
  search,
  'aria-label': ariaLabel,
  'aria-describedby': describedBy,
  'aria-required': required,
}: {
  id?: string;
  value: string;
  options: SelectOption[];
  onChange: (value: string) => void;
  disabled?: boolean;
  invalid?: boolean;
  className?: string;
  search?: {
    label: string;
    emptyMessage: string;
    onChange?: (query: string) => void;
  };
  'aria-label'?: string;
  'aria-describedby'?: string;
  'aria-required'?: boolean;
}) {
  const generated = useId();
  const listId = generated + '-list';
  const optionId = (index: number) => `${generated}-option-${index}`;
  const trigger = useRef<HTMLButtonElement>(null);
  const list = useRef<HTMLUListElement>(null);
  const popup = useRef<HTMLDivElement>(null);
  const searchInput = useRef<HTMLInputElement>(null);
  const [query, setQuery] = useState('');
  const normalize = (text: string) =>
    text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
  const visible = options
    .map((option, index) => ({ option, index }))
    .filter(
      ({ option }) =>
        !search ||
        normalize(option.label + ' ' + option.value).includes(
          normalize(query.trim()),
        ),
    );
  const typed = useRef({ text: '', at: 0 });
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [position, setPosition] = useState<CSSProperties>({});
  const [portalHost, setPortalHost] = useState<HTMLElement | null>(null);
  const [listLabel, setListLabel] = useState(ariaLabel);
  const selected = options.findIndex((option) => option.value === value);
  const enabled = visible
    .map(({ option, index }) => (option.disabled ? -1 : index))
    .filter((index) => index >= 0);
  const highlighted = enabled.includes(active) ? active : (enabled[0] ?? -1);

  const show = (index = selected) => {
    if (disabled || options.every((option) => option.disabled)) return;
    setQuery('');
    search?.onChange?.('');
    if (trigger.current) setPosition(measure(trigger.current));
    setActive(
      !options[index]?.disabled && options[index]
        ? index
        : options.findIndex((option) => !option.disabled),
    );
    setPortalHost(trigger.current?.closest('dialog') ?? document.body);
    setListLabel(ariaLabel ?? trigger.current?.labels?.[0]?.textContent ?? '');
    setOpen(true);
  };
  const choose = (index: number) => {
    const option = options[index];
    setOpen(false);
    trigger.current?.focus();
    if (option && !option.disabled && option.value !== value)
      onChange(option.value);
  };
  const step = (from: number, by: number) => {
    const at = enabled.indexOf(from);
    const next = at < 0 ? 0 : at + by;
    return enabled[Math.max(0, Math.min(enabled.length - 1, next))];
  };
  const match = (key: string, now: number) => {
    const text =
      (now - typed.current.at < 500 ? typed.current.text : '') +
      key.toLowerCase();
    typed.current = { text, at: now };
    const start = open ? highlighted : selected;
    const ordered = [...enabled.filter((i) => i > start), ...enabled];
    return ordered.find((i) => options[i].label.toLowerCase().startsWith(text));
  };

  useEffect(() => {
    if (open && search) searchInput.current?.focus();
  }, [open, search]);

  useEffect(() => {
    if (!open) return;
    const place = () => {
      if (trigger.current) setPosition(measure(trigger.current));
    };
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    return () => {
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const outside = (event: PointerEvent) => {
      const target = event.target as Node;
      if (
        !trigger.current?.contains(target) &&
        !popup.current?.contains(target)
      )
        setOpen(false);
    };
    document.addEventListener('pointerdown', outside);
    return () => document.removeEventListener('pointerdown', outside);
  }, [open]);

  useEffect(() => {
    const box = list.current;
    const item = document.getElementById(optionId(highlighted));
    if (!open || !box || !item) return;
    if (item.offsetTop < box.scrollTop) box.scrollTop = item.offsetTop;
    else if (
      item.offsetTop + item.offsetHeight >
      box.scrollTop + box.clientHeight
    )
      box.scrollTop = item.offsetTop + item.offsetHeight - box.clientHeight;
  });

  const keyDown = (event: KeyboardEvent<HTMLElement>) => {
    const { key } = event;
    if (!open) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(key)) {
        event.preventDefault();
        show();
      } else if (key === 'Home' || key === 'End') {
        event.preventDefault();
        show(key === 'Home' ? enabled[0] : enabled[enabled.length - 1]);
      } else if (key.length === 1 && !event.metaKey && !event.ctrlKey) {
        const found = match(key, event.timeStamp);
        if (found !== undefined) show(found);
      }
      return;
    }
    const moves: Record<string, () => number> = {
      ArrowDown: () => step(highlighted, 1),
      ArrowUp: () => step(highlighted, -1),
      PageDown: () => step(highlighted, 10),
      PageUp: () => step(highlighted, -10),
      Home: () => enabled[0],
      End: () => enabled[enabled.length - 1],
    };
    if (moves[key]) {
      event.preventDefault();
      setActive(enabled.length ? moves[key]() : -1);
    } else if (
      key === 'Enter' ||
      (key === ' ' && event.timeStamp - typed.current.at > 500)
    ) {
      event.preventDefault();
      if (highlighted >= 0) choose(highlighted);
    } else if (key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      setOpen(false);
      trigger.current?.focus();
    } else if (key === 'Tab') {
      if (search) trigger.current?.focus();
      setOpen(false);
    } else if (key.length === 1 && !event.metaKey && !event.ctrlKey) {
      const found = match(key, event.timeStamp);
      if (found !== undefined) setActive(found);
    }
  };

  return (
    <>
      <button
        ref={trigger}
        id={id}
        type="button"
        role="combobox"
        aria-label={ariaLabel}
        aria-describedby={describedBy}
        aria-required={required || undefined}
        aria-invalid={invalid || undefined}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={listId}
        aria-activedescendant={
          open && !search && highlighted >= 0
            ? optionId(highlighted)
            : undefined
        }
        disabled={disabled}
        onClick={() => (open ? setOpen(false) : show())}
        onKeyDown={keyDown}
        onBlur={(event) => {
          if (!popup.current?.contains(event.relatedTarget)) setOpen(false);
        }}
        className={`field flex items-center justify-between gap-2 text-left disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500 ${className}`}
      >
        <OptionLabel
          label={options[selected]?.label ?? ''}
          color={options[selected]?.color}
        />
        <Chevron
          className={`transition-transform ${open ? 'rotate-180' : ''}`}
        />
      </button>
      {open &&
        createPortal(
          <div
            ref={popup}
            style={search ? position : undefined}
            className={
              search
                ? 'z-50 flex flex-col overflow-hidden rounded-lg border border-slate-200 bg-white p-1.5 shadow-lg'
                : undefined
            }
            onBlur={(event) => {
              if (
                !popup.current?.contains(event.relatedTarget) &&
                event.relatedTarget !== trigger.current
              )
                setOpen(false);
            }}
          >
            {search && (
              <input
                ref={searchInput}
                type="search"
                role="combobox"
                aria-label={search.label}
                placeholder={search.label}
                aria-autocomplete="list"
                aria-expanded={open}
                aria-controls={listId}
                aria-activedescendant={
                  highlighted >= 0 ? optionId(highlighted) : undefined
                }
                className="field mb-1.5 shrink-0"
                value={query}
                maxLength={100}
                onChange={(event) => {
                  const value = event.target.value;
                  setQuery(value);
                  search.onChange?.(value);
                  setActive(
                    options.findIndex(
                      (option) =>
                        !option.disabled &&
                        normalize(option.label + ' ' + option.value).includes(
                          normalize(value.trim()),
                        ),
                    ),
                  );
                }}
                onKeyDown={(event) => {
                  if (
                    [
                      'ArrowDown',
                      'ArrowUp',
                      'PageDown',
                      'PageUp',
                      'Enter',
                      'Escape',
                      'Tab',
                    ].includes(event.key)
                  )
                    keyDown(event);
                }}
              />
            )}
            <ul
              ref={list}
              id={listId}
              role="listbox"
              aria-label={listLabel}
              tabIndex={-1}
              style={search ? undefined : position}
              className={
                search
                  ? 'min-h-0 overflow-y-auto'
                  : 'z-50 overflow-y-auto rounded-lg border border-slate-200 bg-white p-1.5 shadow-lg'
              }
            >
              {visible.map(({ option, index }) => {
                const isSelected = index === selected;
                return (
                  <li
                    key={option.value}
                    id={optionId(index)}
                    role="option"
                    aria-selected={isSelected}
                    aria-disabled={option.disabled || undefined}
                    className={`flex cursor-pointer items-center justify-between gap-3 rounded-md px-3 py-2 text-sm whitespace-nowrap ${index === highlighted ? 'bg-slate-100' : ''} ${isSelected ? 'font-semibold text-emerald-900' : 'text-slate-700'} ${option.disabled ? 'cursor-not-allowed opacity-50' : ''}`}
                    onPointerDown={(event) => event.preventDefault()}
                    onPointerEnter={() => !option.disabled && setActive(index)}
                    onClick={() => !option.disabled && choose(index)}
                  >
                    <OptionLabel label={option.label} color={option.color} />
                    {isSelected && (
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
                    )}
                  </li>
                );
              })}
            </ul>
            {search && visible.length === 0 && (
              <p role="status" className="px-3 py-2 text-sm text-slate-500">
                {search.emptyMessage}
              </p>
            )}
          </div>,
          portalHost ?? document.body,
        )}
    </>
  );
}
