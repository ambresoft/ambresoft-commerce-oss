import { Link } from '@inertiajs/react';
import { useReportUrl } from '../../../lib/reportQuery';

/** A table heading that sorts by its column: first click descending, then toggles; the URL keeps every filter. */
export default function SortHeader({
  label,
  field,
  sort,
  direction,
  align = 'left',
}: {
  label: string;
  field: string;
  sort: string;
  direction: 'asc' | 'desc';
  align?: 'left' | 'right';
}) {
  const { href } = useReportUrl();
  const active = sort === field;
  const next = active && direction === 'desc' ? 'asc' : 'desc';
  return (
    <th
      aria-sort={
        active ? (direction === 'asc' ? 'ascending' : 'descending') : undefined
      }
      className={align === 'right' ? 'text-right' : undefined}
    >
      <Link
        className="hover:underline"
        href={href({ sort: field, direction: next })}
        preserveScroll
      >
        {label}
        {active && (
          <span aria-hidden="true">{direction === 'asc' ? ' ↑' : ' ↓'}</span>
        )}
      </Link>
    </th>
  );
}
