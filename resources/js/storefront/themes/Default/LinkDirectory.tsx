import { useState, type CSSProperties } from 'react';
import type { PublicBlock, PublicMenuNode } from '../../types';

/** Merchant-written link groups: headings with links under them in columns, an accordion on phones. */
export default function LinkDirectory({ block }: { block: PublicBlock }) {
  const groups = block.content.groups ?? [];
  const columns = block.content.display?.columns ?? 6;
  const [open, setOpen] = useState<Set<string>>(new Set());
  const toggle = (id: string) =>
    setOpen((old) => {
      const next = new Set(old);
      if (!next.delete(id)) next.add(id);
      return next;
    });
  const link = (node: PublicMenuNode) =>
    node.url ? (
      <a
        href={node.url}
        target={node.new_window ? '_blank' : undefined}
        rel={node.new_window ? 'noopener noreferrer' : undefined}
      >
        {node.name}
      </a>
    ) : (
      <span>{node.name}</span>
    );
  return (
    <div
      className="sf-link-directory"
      style={{ '--sf-link-cols': columns } as CSSProperties}
    >
      {groups.map((group) => (
        <section
          key={group.id}
          className={`sf-link-group ${open.has(group.id) ? 'is-open' : ''}`}
        >
          <div className="sf-link-head">
            <strong>{link(group)}</strong>
            <button
              type="button"
              className="sf-link-toggle"
              aria-expanded={open.has(group.id)}
              aria-controls={`links-${group.id}`}
              aria-label={group.name}
              onClick={() => toggle(group.id)}
            >
              <svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                width="22"
                height="22"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.4"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <path d="m6 9 6 6 6-6" />
              </svg>
            </button>
          </div>
          <ul id={`links-${group.id}`}>
            {group.children.map((child) => (
              <li key={child.id}>{link(child)}</li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}
