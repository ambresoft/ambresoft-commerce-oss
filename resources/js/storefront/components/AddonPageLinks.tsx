import { usePage } from '@inertiajs/react';

type PageLink = { addon: string; url: string; title: string };

/** The header's way to an enabled add-on's full page. It goes when the add-on or the store does. */
export default function AddonPageLinks() {
  const { storefrontPageLinks = [] } = usePage<{
    storefrontPageLinks?: PageLink[];
  }>().props;
  return storefrontPageLinks.map(({ addon, url, title }) => (
    <a key={addon} className="sf-addon-page-link" href={url} title={title}>
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        width="20"
        height="20"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinejoin="round"
      >
        <path d="M12 3c.8 5.2 3.8 8.2 9 9-5.2.8-8.2 3.8-9 9-.8-5.2-3.8-8.2-9-9 5.2-.8 8.2-3.8 9-9z" />
      </svg>
      <span>{title}</span>
    </a>
  ));
}
