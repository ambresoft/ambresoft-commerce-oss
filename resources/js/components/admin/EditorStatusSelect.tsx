import type { ComponentProps } from 'react';
import Select from './Select';

/** The shared compact status pill for admin identity headers. */
export default function EditorStatusSelect({
  className = '',
  ...props
}: ComponentProps<typeof Select>) {
  return (
    <Select
      {...props}
      className={`editor-status editor-status-${props.value} ${className}`}
    />
  );
}
