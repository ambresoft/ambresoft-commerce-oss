import { usePreferences } from '../../lib/usePreferences';
import { useTranslation } from '../../lib/useTranslation';
import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import type { NavigationItem } from '../../types/page';
import NavigationIcon from './NavigationIcon';
import Dropdown from './Dropdown';

type Child =
  | { kind: 'item'; item: NavigationItem }
  | { kind: 'group'; id: string; label: string; children: NavigationItem[] };
type Block =
  | { kind: 'link'; id: string; item: NavigationItem }
  | { kind: 'group'; id: string; label: string; children: Child[] };

const rowClass = 'admin-nav-row flex items-center gap-2.5 rounded-md px-2';

function NavigationLink({
  item,
  nested = false,
  iconOnly = false,
  onNavigate,
}: {
  item: NavigationItem;
  nested?: boolean;
  iconOnly?: boolean;
  onNavigate?: () => void;
}) {
  const { show_menu_descriptions } = usePreferences();
  return (
    <Link
      title={
        iconOnly
          ? item.label
          : show_menu_descriptions
            ? item.description
            : undefined
      }
      aria-label={iconOnly ? item.label : undefined}
      href={item.href}
      onClick={onNavigate}
      aria-current={item.active ? 'page' : undefined}
      className={`${rowClass} ${iconOnly ? 'admin-rail-link' : ''} ${nested ? 'text-[13px]' : 'text-sm'} ${item.active ? 'is-active bg-nav-active font-semibold text-nav-active-ink' : ''}`}
    >
      {!nested && <NavigationIcon id={item.id} />}
      <span className={iconOnly ? 'sr-only' : 'min-w-0 truncate'}>
        {item.label}
      </span>
    </Link>
  );
}

function Group({
  id,
  label,
  open,
  nested = false,
  children,
}: {
  id: string;
  label: string;
  open: boolean;
  nested?: boolean;
  children: ReactNode;
}) {
  return (
    <details className={nested ? 'group/sub' : 'group/top'} open={open}>
      <summary
        className={`${rowClass} list-none ${nested ? 'text-[13px]' : 'text-sm'} [&::-webkit-details-marker]:hidden`}
      >
        {!nested && <NavigationIcon id={id} />}
        <span className="min-w-0 flex-1 truncate">{label}</span>
        <svg
          aria-hidden="true"
          viewBox="0 0 20 20"
          fill="none"
          className={`size-4 shrink-0 transition-transform ${nested ? 'group-open/sub:rotate-90' : 'group-open/top:rotate-90'}`}
        >
          <path d="m8 6 4 4-4 4" stroke="currentColor" strokeWidth="1.5" />
        </svg>
      </summary>
      <ul className="my-0.5 ml-[1.125rem] space-y-0.5 border-l border-slate-300 pl-2">
        {children}
      </ul>
    </details>
  );
}

/** Server entries in their registered order, children gathered under their group (one nested level). */
function blocks(navigation: NavigationItem[]): Block[] {
  const result: Block[] = [];
  for (const item of navigation) {
    if (!item.group) {
      result.push({ kind: 'link', id: item.id, item });
      continue;
    }
    const top = item.group.parent ?? item.group;
    let block = result.find((b) => b.kind === 'group' && b.id === top.id);
    if (block?.kind !== 'group') {
      block = { kind: 'group', id: top.id, label: top.label, children: [] };
      result.push(block);
    }
    if (!item.group.parent) {
      block.children.push({ kind: 'item', item });
      continue;
    }
    const sub = block.children.find(
      (c) => c.kind === 'group' && c.id === item.group!.id,
    );
    if (sub?.kind === 'group') sub.children.push(item);
    else
      block.children.push({
        kind: 'group',
        id: item.group.id,
        label: item.group.label,
        children: [item],
      });
  }
  return result;
}

const childActive = (child: Child): boolean =>
  child.kind === 'item'
    ? child.item.active
    : child.children.some((c) => c.active);

function NavigationChildren({
  items,
  onNavigate,
}: {
  items: Child[];
  onNavigate?: () => void;
}) {
  return items.map((child) =>
    child.kind === 'item' ? (
      <li key={child.item.id}>
        <NavigationLink item={child.item} nested onNavigate={onNavigate} />
      </li>
    ) : (
      <li key={'group:' + child.id}>
        <Group
          id={child.id}
          label={child.label}
          open={childActive(child)}
          nested
        >
          {child.children.map((leaf) => (
            <li key={leaf.id}>
              <NavigationLink item={leaf} nested onNavigate={onNavigate} />
            </li>
          ))}
        </Group>
      </li>
    ),
  );
}

function CollapsedGroup({
  block,
  onNavigate,
  open,
  onOpenChange,
}: {
  block: Extract<Block, { kind: 'group' }>;
  onNavigate?: () => void;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dropdown
      label={block.label}
      trigger={<NavigationIcon id={block.id} />}
      triggerClassName={`${rowClass} admin-rail-link w-full ${block.children.some(childActive) ? 'is-active' : ''}`}
      panelClassName="w-60"
      floating
      side="right"
      hover
      open={open}
      onOpenChange={onOpenChange}
    >
      {(close) => (
        <>
          <p className="border-b border-slate-200 px-3 py-2.5 text-sm font-semibold">
            {block.label}
          </p>
          <ul className="space-y-0.5 p-1.5">
            <NavigationChildren
              items={block.children}
              onNavigate={() => {
                close();
                onNavigate?.();
              }}
            />
          </ul>
        </>
      )}
    </Dropdown>
  );
}

function CollapsedNavigation({
  navigation,
  onNavigate,
}: {
  navigation: NavigationItem[];
  onNavigate?: () => void;
}) {
  const [openGroup, setOpenGroup] = useState<string | null>(null);
  return blocks(navigation).map((block) => (
    <li key={block.kind + ':' + block.id}>
      {block.kind === 'link' ? (
        <NavigationLink item={block.item} iconOnly onNavigate={onNavigate} />
      ) : (
        <CollapsedGroup
          block={block}
          onNavigate={onNavigate}
          open={openGroup === block.id}
          onOpenChange={(next) =>
            setOpenGroup((current) =>
              next ? block.id : current === block.id ? null : current,
            )
          }
        />
      )}
    </li>
  ));
}

export default function Sidebar({
  navigation,
  onNavigate,
  collapsed = false,
}: {
  navigation: NavigationItem[];
  onNavigate?: () => void;
  collapsed?: boolean;
}) {
  const t = useTranslation();
  return (
    <div className="admin-sidebar-scroll flex h-full flex-col overflow-y-auto bg-sidebar px-2 py-3 text-ink">
      <nav aria-label={t('navigation.administration')}>
        <ul className="space-y-0.5">
          {collapsed ? (
            <CollapsedNavigation
              navigation={navigation}
              onNavigate={onNavigate}
            />
          ) : (
            blocks(navigation).map((block) => (
              <li key={block.kind + ':' + block.id}>
                {block.kind === 'link' ? (
                  <NavigationLink item={block.item} onNavigate={onNavigate} />
                ) : (
                  <Group
                    id={block.id}
                    label={block.label}
                    open={block.children.some(childActive)}
                  >
                    <NavigationChildren
                      items={block.children}
                      onNavigate={onNavigate}
                    />
                  </Group>
                )}
              </li>
            ))
          )}
        </ul>
      </nav>
    </div>
  );
}
