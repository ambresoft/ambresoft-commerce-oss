import { useRef, useState, type InputHTMLAttributes } from 'react';
import { usePage } from '@inertiajs/react';
import { usePreferences } from '../../lib/usePreferences';
import { formatDate } from '../../lib/useDateDisplay';
import { useTranslation } from '../../lib/useTranslation';
import type { SharedProps } from '../../types/page';

const iso = (date: Date) => date.toISOString().slice(0, 10);
const validDate = (value: string) =>
  /^\d{4}-\d{2}-\d{2}$/.test(value) &&
  !Number.isNaN(new Date(value + 'T12:00:00Z').getTime()) &&
  iso(new Date(value + 'T12:00:00Z')) === value;

/** Calendar dates have no timezone; form values remain ISO dates regardless of display preference. */
export function parseCalendarDate(value: string, format: string): string {
  if (!value || validDate(value)) return value;
  const numbers = value.split(/[/.-]/);
  const tokens = format.split(/[/.-]/);
  if (numbers.length !== 3 || numbers.some((number) => !/^\d+$/.test(number)))
    return value;
  const parts = Object.fromEntries(
    tokens.map((token, index) => [token, numbers[index]]),
  );
  if (
    !/^\d{4}$/.test(parts.Y ?? '') ||
    !/^\d{1,2}$/.test(parts.m ?? '') ||
    !/^\d{1,2}$/.test(parts.d ?? '')
  )
    return value;
  const date = `${parts.Y?.padStart(4, '0')}-${parts.m?.padStart(2, '0')}-${parts.d?.padStart(2, '0')}`;
  return validDate(date) ? date : value;
}

export default function DateInput({
  value,
  onChange,
  className,
  disabled,
  ...props
}: Omit<
  InputHTMLAttributes<HTMLInputElement>,
  'value' | 'onChange' | 'type'
> & { value: string; onChange: (value: string) => void }) {
  const t = useTranslation();
  const {
    calendar_date_format: format,
    calendar_week_start: start,
    timezone,
  } = usePreferences();
  const { i18n } = usePage<SharedProps>().props;
  const dialog = useRef<HTMLDialogElement>(null);
  const today = formatDate(new Date(), 'Y-m-d', timezone);
  const selected = validDate(value) ? value : today;
  const [month, setMonth] = useState(() => new Date(selected + 'T12:00:00Z'));
  const [focus, setFocus] = useState(selected);
  const first = new Date(
    Date.UTC(month.getUTCFullYear(), month.getUTCMonth(), 1, 12),
  );
  const offset = (first.getUTCDay() - start + 7) % 7;
  const days = Array.from(
    { length: 42 },
    (_, index) =>
      new Date(
        Date.UTC(
          month.getUTCFullYear(),
          month.getUTCMonth(),
          1 - offset + index,
          12,
        ),
      ),
  );
  const display = validDate(value)
    ? formatDate(new Date(value + 'T12:00:00Z'), format, 'UTC')
    : value;
  const moveMonth = (direction: number) => {
    const date = new Date(
      Date.UTC(month.getUTCFullYear(), month.getUTCMonth() + direction, 1, 12),
    );
    setMonth(date);
    setFocus(iso(date));
  };
  return (
    <div className="max-w-md">
      <div className="flex items-center gap-2">
        <input
          {...props}
          disabled={disabled}
          className={className ?? 'field'}
          value={display}
          placeholder={format}
          onChange={(e) => onChange(parseCalendarDate(e.target.value, format))}
        />
        <button
          type="button"
          disabled={disabled}
          className="button-secondary shrink-0"
          aria-label={t('settings.calendar_open')}
          onClick={() => {
            setMonth(new Date(selected + 'T12:00:00Z'));
            setFocus(selected);
            dialog.current?.showModal();
          }}
        >
          ▦
        </button>
      </div>
      <dialog
        ref={dialog}
        aria-label={t('settings.calendar_choose')}
        className="m-auto w-80 rounded-xl border border-slate-200 bg-white p-4 text-slate-900 shadow-xl backdrop:bg-black/30"
        onClick={(e) => {
          if (e.target === e.currentTarget) dialog.current?.close();
        }}
      >
        <div className="mb-4 flex items-center justify-between gap-2">
          <button
            type="button"
            className="button-secondary"
            aria-label={t('common.previous')}
            onClick={() => moveMonth(-1)}
          >
            ‹
          </button>
          <span className="text-sm font-semibold">
            {new Intl.DateTimeFormat(i18n.locale, {
              timeZone: 'UTC',
              month: 'long',
              year: 'numeric',
            }).format(month)}
          </span>
          <button
            type="button"
            className="button-secondary"
            aria-label={t('common.next')}
            onClick={() => moveMonth(1)}
          >
            ›
          </button>
        </div>
        <div className="grid grid-cols-7 text-center text-xs text-slate-500">
          {Array.from({ length: 7 }, (_, index) => (
            <span key={index} className="py-2">
              {new Intl.DateTimeFormat(i18n.locale, {
                timeZone: 'UTC',
                weekday: 'short',
              }).format(
                new Date(Date.UTC(2026, 9, 4 + ((start + index) % 7), 12)),
              )}
            </span>
          ))}
        </div>
        <div
          role="grid"
          aria-label={t('settings.calendar_choose')}
          className="grid grid-cols-7 gap-1"
        >
          {Array.from({ length: 6 }, (_, week) => (
            <div key={week} role="row" className="contents">
              {days.slice(week * 7, week * 7 + 7).map((day) => (
                <button
                  type="button"
                  role="gridcell"
                  key={iso(day)}
                  data-date={iso(day)}
                  tabIndex={iso(day) === focus ? 0 : -1}
                  aria-label={formatDate(day, format, 'UTC')}
                  aria-selected={iso(day) === value}
                  aria-current={iso(day) === today ? 'date' : undefined}
                  className={
                    'rounded-md py-2 text-sm hover:bg-slate-100 ' +
                    (iso(day) === value
                      ? 'bg-primary text-on-primary'
                      : iso(day) === today
                        ? // Today is lighter than the selected day: a tinted fill with a ring.
                          'bg-primary-tint font-semibold text-emerald-800 ring-1 ring-emerald-500/50 ring-inset'
                        : day.getUTCMonth() === month.getUTCMonth()
                          ? ''
                          : 'text-slate-400')
                  }
                  onClick={() => {
                    onChange(iso(day));
                    dialog.current?.close();
                  }}
                  onKeyDown={(e) => {
                    const delta = {
                      ArrowLeft: -1,
                      ArrowRight: 1,
                      ArrowUp: -7,
                      ArrowDown: 7,
                    }[e.key];
                    if (delta !== undefined) {
                      e.preventDefault();
                      const next = new Date(day);
                      next.setUTCDate(next.getUTCDate() + delta);
                      setFocus(iso(next));
                      setMonth(next);
                      requestAnimationFrame(() =>
                        dialog.current
                          ?.querySelector<HTMLButtonElement>(
                            '[data-date="' + iso(next) + '"]',
                          )
                          ?.focus(),
                      );
                    }
                  }}
                >
                  {day.getUTCDate()}
                </button>
              ))}
            </div>
          ))}
        </div>
        <div className="mt-4 flex justify-between">
          <button
            type="button"
            className="text-link text-sm"
            onClick={() => {
              onChange('');
              dialog.current?.close();
            }}
          >
            {t('settings.calendar_clear')}
          </button>
          <button
            type="button"
            className="button-secondary"
            onClick={() => dialog.current?.close()}
          >
            {t('common.close')}
          </button>
        </div>
      </dialog>
    </div>
  );
}
