import type { ReactNode } from 'react';

/** Primary editor controls sit above the compact, sticky tab navigation. */
export default function EditorActions({
  children,
  leading,
}: {
  children: ReactNode;
  /** Shown at the start of the row (for example the status), away from the action buttons. */
  leading?: ReactNode;
}) {
  return (
    <div className="mb-4 flex flex-wrap items-center justify-end gap-3">
      {leading && (
        <div className="mr-auto flex flex-wrap items-center gap-3">
          {leading}
        </div>
      )}
      {children}
    </div>
  );
}
