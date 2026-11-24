import type { AnchorHTMLAttributes, ButtonHTMLAttributes } from 'react';
import { Icon } from './list/IconButton';

export type EditorAction = 'save' | 'preview' | 'duplicate';
const icons = {
  save: 'check',
  preview: 'external',
  duplicate: 'copy',
} as const;
const style = (action: EditorAction, className = '') =>
  `editor-action ${action === 'save' ? 'button-primary' : 'button-secondary'} ${className}`;

export default function EditorActionButton({
  action = 'save',
  children,
  className,
  type = 'submit',
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { action?: EditorAction }) {
  return (
    <button {...props} type={type} className={style(action, className)}>
      <Icon name={icons[action]} className="size-5 shrink-0" />
      {children}
    </button>
  );
}

export function EditorActionLink({
  action = 'preview',
  children,
  className,
  ...props
}: AnchorHTMLAttributes<HTMLAnchorElement> & { action?: EditorAction }) {
  return (
    <a {...props} className={style(action, className)}>
      {children}
      <Icon name={icons[action]} className="size-5 shrink-0" />
    </a>
  );
}
