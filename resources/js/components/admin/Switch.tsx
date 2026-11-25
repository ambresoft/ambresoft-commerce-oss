/** Compact on/off switch for list rows (enabled states). */
export default function Switch({
  checked,
  disabled = false,
  label,
  onChange,
}: {
  checked: boolean;
  disabled?: boolean;
  label: string;
  onChange: (value: boolean) => void;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={
        'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-primary)] disabled:cursor-not-allowed ' +
        (checked ? 'bg-primary' : 'bg-slate-200') +
        (disabled ? ' opacity-50' : '')
      }
    >
      <span
        aria-hidden
        className={
          'inline-block size-4 rounded-full bg-white shadow transition-transform ' +
          (checked ? 'translate-x-[1.125rem]' : 'translate-x-0.5')
        }
      />
    </button>
  );
}
