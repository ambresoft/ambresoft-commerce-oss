import SocialIcon from '../../../components/SocialIcon';
import { useState, type CSSProperties } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type {
  PublicBlock,
  PublicMenuNode,
  PublicSocialLink,
} from '../../types';

function Line({ node }: { node: PublicMenuNode }) {
  return node.url ? (
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
}

function AppButton({
  href,
  small,
  big,
}: {
  href: string;
  small: string;
  big: string;
}) {
  return (
    <a
      href={href}
      className="sf-app-button"
      target="_blank"
      rel="noopener noreferrer"
    >
      <small>{small}</small>
      <strong>{big}</strong>
    </a>
  );
}

/** The store footer: columns made of a merchant-written link-groups menu, plus an optional bottom line. */
export default function Footer({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const groups = block.content.groups ?? [];
  const social: PublicSocialLink[] = block.content.social ?? [];
  const apps: PublicSocialLink[] = block.content.apps ?? [];
  const payments = block.content.payments ?? [];
  const hasApps = apps.length > 0;
  const d = block.content.display ?? {};
  const accordion = d.mobile_style === 'accordion';
  const [open, setOpen] = useState<Set<string>>(new Set());
  const toggle = (id: string) =>
    setOpen((old) => {
      const next = new Set(old);
      if (!next.delete(id)) next.add(id);
      return next;
    });
  return (
    <div className="sf-footer-block">
      {(groups.length > 0 || social.length > 0 || hasApps) && (
        <div
          className={`sf-footer-cols ${accordion ? 'is-accordion' : ''}`}
          style={{ '--sf-footer-cols': d.columns ?? 4 } as CSSProperties}
        >
          {groups.map((group) => (
            <section
              key={group.id}
              className={`sf-footer-col ${open.has(group.id) ? 'is-open' : ''}`}
            >
              <div className="sf-footer-head">
                <h3>
                  <Line node={group} />
                </h3>
                <button
                  type="button"
                  className="sf-footer-toggle"
                  aria-expanded={open.has(group.id)}
                  aria-controls={`footer-${block.id}-${group.id}`}
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
              <ul id={`footer-${block.id}-${group.id}`}>
                {group.children.map((child) => (
                  <li key={child.id}>
                    <Line node={child} />
                  </li>
                ))}
              </ul>
            </section>
          ))}
          {(social.length > 0 || hasApps) && (
            <section className="sf-footer-col sf-footer-extra">
              {social.length > 0 && (
                <>
                  <h3>{t('storefront.follow_us')}</h3>
                  <ul className="sf-social">
                    {social.map((link, index) => (
                      <li key={index + link.url}>
                        <a
                          href={link.url}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          <SocialIcon
                            network={link.network}
                            image={link.image?.url}
                          />
                          {link.name}
                        </a>
                      </li>
                    ))}
                  </ul>
                </>
              )}
              {hasApps && (
                <>
                  <h3>{t('storefront.download_app')}</h3>
                  <div className="sf-apps">
                    {apps.map((app, index) =>
                      app.image ? (
                        <a
                          key={index + app.url}
                          href={app.url}
                          className="sf-app-image"
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          <img
                            src={app.image.url}
                            alt={app.name}
                            width={app.image.width}
                            height={app.image.height}
                            loading="lazy"
                          />
                        </a>
                      ) : (
                        <AppButton
                          key={index + app.url}
                          href={app.url}
                          small={t(
                            app.network === 'google_play'
                              ? 'storefront.get_it_on'
                              : 'storefront.download_on',
                          )}
                          big={app.name}
                        />
                      ),
                    )}
                  </div>
                </>
              )}
            </section>
          )}
        </div>
      )}
      {(block.content.html || payments.length > 0) && (
        <div className="sf-footer-bottom">
          <div dangerouslySetInnerHTML={{ __html: block.content.html ?? '' }} />
          {payments.length > 0 && (
            <ul className="sf-payments">
              {payments.map((icon) => (
                <li key={icon.image.url}>
                  <img
                    src={icon.image.url}
                    alt={icon.name}
                    width={icon.image.width}
                    height={icon.image.height}
                    loading="lazy"
                  />
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
