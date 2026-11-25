import { Link } from '@inertiajs/react';
import {
  Children,
  isValidElement,
  useId,
  useLayoutEffect,
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type MouseEvent,
  type ReactElement,
  type ReactNode,
} from 'react';
import Dropdown from './Dropdown';
import { Icon } from './list/IconButton';
import { useTranslation } from '../../lib/useTranslation';

type TabProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  icon?: ReactNode;
  iconKey?: string;
  overflow?: boolean;
  priority?: boolean;
  href?: string;
  preserveScroll?: boolean;
};

function TabContent({ children }: TabProps) {
  return <span className="admin-tab-label">{children}</span>;
}

export function AdminTab({
  icon,
  iconKey,
  children,
  href,
  preserveScroll,
  overflow: _overflow,
  priority: _priority,
  ...props
}: TabProps) {
  void _overflow;
  void _priority;
  const content = (
    <TabContent icon={icon} iconKey={iconKey}>
      {children}
    </TabContent>
  );
  if (href)
    return (
      <Link
        href={href}
        id={props.id}
        title={props.title}
        aria-label={props['aria-label']}
        aria-current={props['aria-selected'] ? 'page' : undefined}
        preserveScroll={preserveScroll}
        className="admin-tab"
      >
        {content}
      </Link>
    );
  return (
    <button {...props} type="button" role="tab" className="admin-tab">
      {content}
    </button>
  );
}

/** Shared admin navigation. Content-language and form state stay in the owning editor. */
export default function AdminTabs({
  children,
  language,
  label,
  id,
  className = '',
  actions,
  sticky = true,
}: {
  children?: ReactNode;
  language?: ReactNode;
  label?: string;
  id?: string;
  className?: string;
  actions?: ReactNode;
  /** Disable when the containing dialog header owns sticky positioning. */
  sticky?: boolean;
}) {
  const t = useTranslation();
  const generatedId = useId();
  const prefix = id ?? generatedId;
  const tabs = Children.toArray(children).filter(
    (child) => isValidElement(child) && child.type === AdminTab,
  ) as ReactElement<TabProps>[];
  const [widths, setWidths] = useState<number[]>([]);
  const [available, setAvailable] = useState<number | null>(null);
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const measure = useRef<HTMLDivElement>(null);
  const controls = useRef<HTMLDivElement>(null);
  const more = useRef<HTMLDivElement>(null);
  const focusAfterSelect = useRef<string | null>(null);

  useLayoutEffect(() => {
    const resize = () => {
      if (!root.current || !measure.current || !root.current.clientWidth)
        return;
      const next = Array.from(
        measure.current.children,
        (element) => element.getBoundingClientRect().width,
      );
      setWidths((previous) =>
        previous.length === next.length &&
        previous.every((width, index) => width === next[index])
          ? previous
          : next,
      );
      setAvailable(
        root.current.clientWidth -
          (controls.current?.getBoundingClientRect().width ?? 0) -
          36,
      );
    };
    resize();
    const observer =
      typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(resize);
    if (root.current) observer?.observe(root.current);
    if (measure.current) observer?.observe(measure.current);
    if (controls.current) observer?.observe(controls.current);
    window.addEventListener('resize', resize);
    return () => {
      observer?.disconnect();
      window.removeEventListener('resize', resize);
    };
  }, [children, language, actions]);

  const visible: number[] = [];
  const total = widths.reduce((sum, width) => sum + width, 0);
  const overflowing =
    tabs.some((tab) => tab.props.overflow) ||
    (available !== null && total > available);
  const limit = (available ?? Infinity) - (overflowing ? 52 : 0);
  const active = tabs.findIndex((tab) => tab.props['aria-selected']);
  const candidates = tabs
    .map((_, index) => index)
    .filter((index) => !tabs[index].props.overflow);
  const preferred = [
    ...candidates.filter((index) => tabs[index].props.priority),
    ...candidates.filter((index) => !tabs[index].props.priority),
  ];
  let used = 0;
  for (const index of preferred) {
    if (used + (widths[index] ?? 0) > limit) continue;
    visible.push(index);
    used += widths[index] ?? 0;
  }
  if (
    active >= 0 &&
    !tabs[active].props.overflow &&
    !visible.includes(active)
  ) {
    while (visible.length && used + (widths[active] ?? 0) > limit) {
      used -= widths[visible.pop()!] ?? 0;
    }
    visible.push(active);
  }
  visible.sort((a, b) => a - b);
  const hidden = tabs
    .map((_, index) => index)
    .filter((index) => !visible.includes(index));

  useLayoutEffect(() => {
    if (!focusAfterSelect.current) return;
    document.getElementById(focusAfterSelect.current)?.focus();
    focusAfterSelect.current = null;
  });

  const renderTab = (index: number) => {
    const tab = tabs[index];
    const selected = !!tab.props['aria-selected'];
    const tabId = tab.props.id ?? `${prefix}-tab-${index}`;
    return (
      <AdminTab
        {...tab.props}
        key={tab.key}
        id={tabId}
        tabIndex={
          selected ||
          (visible.includes(index) &&
            !visible.includes(active) &&
            index === visible[0]) ||
          (open &&
            hidden.includes(index) &&
            !hidden.includes(active) &&
            index === hidden[0])
            ? 0
            : -1
        }
        onClick={(event) => {
          if (open && !tab.props.overflow) focusAfterSelect.current = tabId;
          if (tab.props.overflow)
            more.current?.querySelector('button')?.focus();
          tab.props.onClick?.(event);
          setOpen(false);
        }}
        onKeyDown={(event) => {
          const enabled = tabs
            .map((item, i) => (item.props.disabled ? -1 : i))
            .filter((i) => i >= 0);
          const position = enabled.indexOf(index);
          const nextPosition =
            event.key === 'ArrowRight'
              ? (position + 1) % enabled.length
              : event.key === 'ArrowLeft'
                ? (position + enabled.length - 1) % enabled.length
                : event.key === 'Home'
                  ? 0
                  : event.key === 'End'
                    ? enabled.length - 1
                    : null;
          if (nextPosition === null) return;
          event.preventDefault();
          const next = enabled[nextPosition];
          const target = tabs[next];
          focusAfterSelect.current = target.props.id ?? `${prefix}-tab-${next}`;
          target.props.onClick?.(
            event as unknown as MouseEvent<HTMLButtonElement>,
          );
          setOpen(!!target.props.overflow);
        }}
      />
    );
  };

  return (
    <div
      ref={root}
      className={`admin-tabs ${tabs.length > 0 ? (sticky ? 'editor-tab-bar' : 'border-slate-200') : 'admin-tabs-language-only'} ${className}`}
    >
      <div
        className="admin-tabs-measure"
        ref={measure}
        aria-hidden="true"
        inert
      >
        {tabs.map((tab) => (
          <span className="admin-tab" key={tab.key}>
            <TabContent {...tab.props} />
          </span>
        ))}
      </div>
      {tabs.length > 0 && (
        <div className="admin-tabs-navigation">
          <div
            id={id}
            role={
              tabs.every((tab) => tab.props.href) ? 'navigation' : 'tablist'
            }
            aria-label={label}
            className="admin-tabs-items"
          >
            {visible.map(renderTab)}
          </div>
          {hidden.length > 0 && (
            <div ref={more} className="admin-tabs-overflow">
              <Dropdown
                label={t('common.more_tabs')}
                trigger={<Icon name="more" className="size-5 rotate-90" />}
                triggerClassName={`admin-tabs-more ${hidden.includes(active) ? 'admin-tabs-more-active' : ''}`}
                panelClassName="admin-tabs-dropdown p-1.5"
                floating
                keepMounted
                open={open}
                onOpenChange={setOpen}
              >
                {() => (
                  <div
                    role={
                      tabs.every((tab) => tab.props.href)
                        ? 'navigation'
                        : 'tablist'
                    }
                    aria-label={label}
                    className="admin-tabs-menu"
                  >
                    {hidden.map(renderTab)}
                  </div>
                )}
              </Dropdown>
            </div>
          )}
        </div>
      )}
      {(language || actions) && (
        <div ref={controls} className="admin-tabs-controls">
          {actions}
          {language && <div className="admin-tabs-language">{language}</div>}
        </div>
      )}
    </div>
  );
}
