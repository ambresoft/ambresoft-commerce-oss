import type { PublicBlock } from '../../types';

function SearchIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="20"
      height="20"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
    >
      <circle cx="11" cy="11" r="7" />
      <path d="m20 20-4-4" />
    </svg>
  );
}

/**
 * The Popular searches block: each term links to its search results. Rows of pills on wide screens, one row that
 * scrolls sideways (with snapping) on phones. The block's title is its heading.
 */
export default function PopularSearches({ block }: { block: PublicBlock }) {
  const terms = block.content.popular_searches ?? [];
  if (terms.length === 0) return null;
  return (
    <ul className="sf-popular-searches">
      {terms.map((item) => (
        <li key={item.term}>
          <a href={item.url}>
            <SearchIcon />
            <span>{item.term}</span>
          </a>
        </li>
      ))}
    </ul>
  );
}
