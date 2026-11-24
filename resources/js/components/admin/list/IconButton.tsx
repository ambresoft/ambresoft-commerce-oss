import { Link } from '@inertiajs/react';

const icons = {
  external: ['M14 3h7v7', 'm21 3-11 11', 'M10 4H4v16h16v-6'],
  'arrow-left': ['M19 12H5', 'm11 6-6 6 6 6'],
  more: ['M12 5h.01M12 12h.01M12 19h.01'],
  warning: [
    'M10.3 4.5 2.5 18a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 4.5a2 2 0 0 0-3.4 0Z',
    'M12 9v4',
    'M12 17h.01',
  ],
  copy: [
    'M8 8h10v12H8z',
    'M16 8V5a1 1 0 0 0-1-1H6a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h2',
  ],
  trash: [
    'M4 7h16',
    'M10 11v6M14 11v6',
    'M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12',
    'M9 7V4h6v3',
  ],
  restore: ['M4 12a8 8 0 1 0 2.3-5.7L4 8.6', 'M4 4v4.6h4.6'],
  edit: ['M4 20h4L19 9l-4-4L4 16z', 'm13.5 6.5 4 4'],
  plus: ['M12 5v14M5 12h14'],
  box: ['M12 3 4 7v10l8 4 8-4V7z', 'm4 7 8 4 8-4', 'M12 11v10'],
  clock: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z', 'M12 7v5l3 2'],
  chat: ['M4 5h16v11H9l-5 4z'],
  user: ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M4 20a8 8 0 0 1 16 0'],
  card: ['M3 6h18v12H3z', 'M3 10h18', 'M7 15h3'],
  truck: [
    'M3 6h11v10H3z',
    'M14 9h4l3 3v4h-7z',
    'M7 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
    'M17 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
  ],
  pin: [
    'M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11Z',
    'M12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z',
  ],
  receipt: ['M6 3h12v18l-3-2-3 2-3-2-3 2z', 'M9 8h6M9 12h6'],
  note: ['M5 4h14v16H5z', 'M8 9h8M8 13h8M8 17h4'],
  upload: ['M12 16V4', 'm7 9 5-5 5 5', 'M5 20h14'],
  download: ['M12 4v12', 'm7 11 5 5 5-5', 'M5 20h14'],
  file: ['M7 3h7l4 4v14H7z', 'M14 3v4h4', 'M9.5 13h5M9.5 16.5h5'],
  shield: [
    'M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.5 7-10V6z',
    'M12 9v4',
    'M12 16.5v.01',
  ],
  x: ['M6 6l12 12M18 6 6 18'],
  puzzle: [
    'M19.4 7.9c-.05.32.06.65.29.88l1.57 1.57a2.4 2.4 0 0 1 0 3.4l-1.61 1.61a1 1 0 0 1-.84.28c-.47-.07-.8-.48-.97-.93a2.5 2.5 0 1 0-3.21 3.21c.45.17.86.5.93.97a1 1 0 0 1-.28.84l-1.61 1.61a2.4 2.4 0 0 1-3.4 0l-1.57-1.57a1 1 0 0 0-.88-.29c-.49.07-.84.5-1.02.97a2.5 2.5 0 1 1-3.24-3.24c.46-.18.89-.53.97-1.02a1 1 0 0 0-.29-.88l-1.57-1.57a2.4 2.4 0 0 1 0-3.4l1.53-1.53c.24-.24.58-.35.92-.3.52.08.88.53 1.07 1.01a2.5 2.5 0 1 0 3.26-3.26c-.48-.2-.93-.56-1.01-1.07-.05-.34.06-.68.3-.92l1.53-1.53a2.4 2.4 0 0 1 3.4 0l1.57 1.57c.23.23.56.34.88.29.49-.07.84-.5 1.02-.97a2.5 2.5 0 1 1 3.24 3.24c-.46.18-.89.53-.97 1.02Z',
  ],
  lock: ['M6 11h12v9H6z', 'M8.5 11V8a3.5 3.5 0 0 1 7 0v3'],
  ban: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z', 'm5.6 5.6 12.8 12.8'],
  'arrow-up': ['M12 19V5', 'm6 11 6-6 6 6'],
  'arrow-down': ['M12 5v14', 'm6 13 6 6 6-6'],
  mail: ['M4 6h16v12H4z', 'm4 7 8 6 8-6'],
  flag: ['M6 21V4', 'M6 4h11l-2.5 4 2.5 4H6'],
  check: ['m5 12.5 4.5 4.5L19 7'],
  minus: ['M6 12h12'],
  equals: ['M6 9.5h12M6 14.5h12'],
  grid: ['M4 4h7v7H4z', 'M13 4h7v7h-7z', 'M4 13h7v7H4z', 'M13 13h7v7h-7z'],
  settings: [
    'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
    'M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5v.2a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1h-.2a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h.1a1.7 1.7 0 0 0 1-1.5v-.2a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1h.2a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z',
  ],
};

export type IconName = keyof typeof icons;

export function Icon({
  name,
  className = 'size-4',
}: {
  name: IconName;
  className?: string;
}) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
    >
      {icons[name].map((d) => (
        <path key={d} d={d} />
      ))}
    </svg>
  );
}

/** Square icon-only action with its label as accessible name and tooltip. */
export default function IconButton({
  icon,
  label,
  onClick,
  href,
  danger = false,
  disabled = false,
  bare = false,
}: {
  icon: IconName;
  label: string;
  onClick?: () => void;
  href?: string;
  danger?: boolean;
  disabled?: boolean;
  /** Compact tool without its own border, for toolbars that carry their own background. */
  bare?: boolean;
}) {
  const className = bare
    ? `lx-tool ${danger ? 'lx-tool-danger' : ''}`
    : `icon-button ${danger ? 'icon-button-danger' : ''}`;
  return href ? (
    <Link href={href} aria-label={label} title={label} className={className}>
      <Icon name={icon} />
    </Link>
  ) : (
    <button
      type="button"
      aria-label={label}
      title={label}
      className={className}
      disabled={disabled}
      onClick={onClick}
    >
      <Icon name={icon} />
    </button>
  );
}
