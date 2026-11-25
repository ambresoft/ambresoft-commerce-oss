import { Link } from '@inertiajs/react';
import { Children, type ReactNode } from 'react';
import Dropdown from './Dropdown';
import { useTranslation } from '../../lib/useTranslation';
import { Icon } from './list/IconButton';
import TabIcon from './TabIcon';

/** Shared admin identity, status and actions; submit buttons keep their owning form. */
export default function EditorHeader({
  heading,
  title,
  image,
  iconKey = 'general',
  backHref,
  status,
  metadata,
  description,
  actions,
  headingId,
  menu,
}: {
  heading: ReactNode;
  title?: ReactNode;
  image?: string | null;
  iconKey?: string;
  backHref?: string;
  backLabel?: string;
  status?: ReactNode;
  metadata?: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  headingId?: string;
  menu?: ReactNode;
}) {
  const t = useTranslation();
  const menuItems = Children.toArray(menu);
  return (
    <>
      {backHref && (
        <Link
          href={backHref}
          aria-label={t('common.back')}
          className="editor-header-back"
        >
          <Icon name="arrow-left" className="size-4" />
          {t('common.back')}
        </Link>
      )}
      <header className="editor-header">
        <div className="editor-header-identity">
          <div className="editor-header-image">
            {image ? <img src={image} alt="" /> : <TabIcon name={iconKey} />}
          </div>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 id={headingId} className="text-lg font-semibold break-words">
                {heading}
              </h1>
              {!title && status && (
                <div className="editor-header-status">{status}</div>
              )}
            </div>
            {title && (
              <div className="mt-1 flex flex-wrap items-center gap-2">
                <h2 className="min-w-0 text-lg font-semibold break-words">
                  {title}
                </h2>
                {status && <div className="editor-header-status">{status}</div>}
              </div>
            )}
            {metadata && (
              <div className="editor-header-metadata">{metadata}</div>
            )}
            {description && (
              <div className="editor-header-description">{description}</div>
            )}
          </div>
        </div>
        {(actions || menuItems.length > 0) && (
          <div className="editor-header-actions">
            {actions}
            {menuItems.length > 0 && (
              <Dropdown
                label={t('common.actions')}
                trigger={<Icon name="more" className="size-5" />}
                triggerClassName="editor-header-more"
                panelClassName="editor-header-menu w-56 p-1.5"
                floating
              >
                {(close) => (
                  <div
                    className="flex flex-col gap-1"
                    onClick={(event) => {
                      const control = (event.target as Element).closest(
                        'button, a',
                      );
                      if (
                        control instanceof HTMLButtonElement &&
                        control.type === 'submit' &&
                        control.form
                      )
                        return;
                      if (control) close();
                    }}
                  >
                    {menuItems}
                  </div>
                )}
              </Dropdown>
            )}
          </div>
        )}
      </header>
    </>
  );
}
