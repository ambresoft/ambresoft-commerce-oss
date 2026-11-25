/** Keep the text label accessible; a configured color is an additional visual cue. */
export default function OptionLabel({
  label,
  color,
}: {
  label: string;
  color?: string;
}) {
  return (
    <span className="inline-flex min-w-0 items-center gap-2">
      {color && (
        <span
          aria-hidden="true"
          className="size-2 shrink-0 rounded-full"
          style={{ backgroundColor: color }}
        />
      )}
      <span className="truncate">{label}</span>
    </span>
  );
}
