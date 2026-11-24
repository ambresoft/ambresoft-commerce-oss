import RedisIcon from './RedisIcon';

// Outline icons for core navigation entries and groups. Unknown IDs (e.g. addon
// entries) fall back to a neutral dot so new navigation never renders blank.
const paths: Record<string, string[]> = {
  'core.dashboard': [
    'M3.5 10.5 12 3.5l8.5 7',
    'M5.5 9v10.5a1 1 0 0 0 1 1h3.5v-6h4v6h3.5a1 1 0 0 0 1-1V9',
  ],
  'core.access': [
    'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1',
    'M9 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z',
    'M22 19v-1a4 4 0 0 0-3-3.87',
    'M16 3.13a3.5 3.5 0 0 1 0 6.75',
  ],
  'core.orders': ['M6 3h12v18l-3-2-3 2-3-2-3 2V3Z', 'M9 8h6M9 12h6'],
  'core.payment-methods': [
    'M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z',
    'M3 10h18',
    'M7 15h3',
  ],
  'core.customers': [
    'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
    'M12 12.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
    'M6.2 18.4a6.5 6.5 0 0 1 11.6 0',
  ],
  'core.marketing': [
    'M3 11v2a1 1 0 0 0 1 1h2l5 4V6l-5 4H4a1 1 0 0 0-1 1Z',
    'M15 9a4 4 0 0 1 0 6',
    'M18 6a8 8 0 0 1 0 12',
  ],
  'core.website': [
    'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
    'M3 12h18',
    'M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18',
  ],
  'core.addons': [
    'M10 4a2 2 0 1 1 4 0v1h4a1 1 0 0 1 1 1v4h-1a2 2 0 1 0 0 4h1v4a1 1 0 0 1-1 1h-4v-1a2 2 0 1 0-4 0v1H6a1 1 0 0 1-1-1v-4h1a2 2 0 1 0 0-4H5V6a1 1 0 0 1 1-1h4V4Z',
  ],
  'core.data-transfer': ['M7 4v13m0 0-3-3m3 3 3-3', 'M17 20V7m0 0-3 3m3-3 3 3'],
  'core.logs': [
    'M7 3h7l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z',
    'M14 3v5h5',
    'M9 13h6M9 17h6',
  ],
  'core.categories': ['M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z'],
  'core.reports': ['M4 20V10', 'M10 20V4', 'M16 20v-8', 'M21 20H3'],
  'core.catalog': [
    'm12 3 9 4.5-9 4.5-9-4.5L12 3Z',
    'm3 12 9 4.5 9-4.5',
    'm3 16.5 9 4.5 9-4.5',
  ],
  'core.catalog-products': [
    'm12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z',
    'm4 7.5 8 4.5 8-4.5',
    'M12 12v9',
  ],
  'core.shipping-taxes': [
    'M3 7h11v9H3zM14 10h4l3 3v3h-7',
    'M7.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM17.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
  ],
  'core.settings': [
    'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
    'M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5v.2a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1h-.2a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h.1a1.7 1.7 0 0 0 1-1.5v-.2a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1h.2a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z',
  ],
};

export default function NavigationIcon({ id }: { id: string }) {
  if (id === 'core.cache') return <RedisIcon />;
  const icon = paths[id];
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      className="size-5 shrink-0"
    >
      {icon ? (
        icon.map((d) => <path key={d} d={d} />)
      ) : (
        <circle cx="12" cy="12" r="2.5" fill="currentColor" stroke="none" />
      )}
    </svg>
  );
}
