import { useEffect, useRef, useState } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { ReelDetail } from '../../../types/reels';
import type { PublicBlock, PublicItem } from '../../types';
import Dialog from './Dialog';
import { Collection, Money, NoImage, Stars } from './parts';

const csrf = () =>
  decodeURIComponent(
    document.cookie
      .split('; ')
      .find((row) => row.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length) ?? '',
  );

export default function Reels({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const [active, setActive] = useState<number | null>(null);
  const items =
    block.template === 'reel_single'
      ? (block.content.items?.slice(0, 1) ?? [])
      : (block.content.items ?? []);
  const item = active === null ? null : items[active];
  return (
    <>
      <Collection
        block={block}
        renderItem={(item, index) => (
          <button
            type="button"
            className="sf-reel-card"
            aria-label={t('reels.play', { name: item.name ?? '' })}
            onClick={() => setActive(index)}
          >
            <span className="sf-reel-cover">
              {item.image && (
                <img
                  src={item.image.url}
                  width={item.image.width}
                  height={item.image.height}
                  alt=""
                  loading="lazy"
                />
              )}
              <span className="sf-reel-play" aria-hidden="true">
                ▶
              </span>
            </span>
            <span className="sf-reel-title">{item.name}</span>
          </button>
        )}
      />
      {item && (
        <Dialog
          className="sf-reels-dialog"
          title={item.name ?? ''}
          onClose={() => setActive(null)}
        >
          <ReelPlayer
            key={item.id}
            item={item}
            previous={
              active !== null && active > 0
                ? () => setActive(active - 1)
                : undefined
            }
            next={
              active !== null && active < items.length - 1
                ? () => setActive(active + 1)
                : undefined
            }
          />
        </Dialog>
      )}
    </>
  );
}

function ReelPlayer({
  item,
  previous,
  next,
}: {
  item: PublicItem;
  previous?: () => void;
  next?: () => void;
}) {
  const t = useTranslation();
  const [detail, setDetail] = useState<ReelDetail | null>(null);
  const [failed, setFailed] = useState(false);
  const [retry, setRetry] = useState(0);
  const video = useRef<HTMLVideoElement>(null);
  const playback = useRef({
    seconds: 0,
    last: 0,
    sent: false,
    sending: false,
    retryAt: 0,
  });
  useEffect(() => {
    const abort = new AbortController();
    fetch(`/storefront/reels/${item.id}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
      signal: abort.signal,
    })
      .then(async (response) => {
        if (!response.ok) throw new Error('reel');
        return response.json() as Promise<ReelDetail>;
      })
      .then((data) => {
        if (!abort.signal.aborted) setDetail(data);
      })
      .catch(() => {
        if (!abort.signal.aborted) setFailed(true);
      });
    return () => abort.abort();
  }, [item.id, retry]);
  useEffect(() => {
    const el = video.current;
    const hide = () => {
      if (document.hidden) el?.pause();
      playback.current.last = 0;
    };
    document.addEventListener('visibilitychange', hide);
    return () => {
      document.removeEventListener('visibilitychange', hide);
      el?.pause();
    };
  }, [detail]);
  const record = async () => {
    if (!detail) return;
    const progress = playback.current;
    if (
      progress.sent ||
      progress.sending ||
      performance.now() < progress.retryAt
    )
      return;
    progress.sending = true;
    try {
      const response = await fetch(`/storefront/reels/${item.id}/views`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ token: detail.view_token }),
      });
      progress.sent =
        response.ok || (response.status < 500 && response.status !== 429);
      progress.retryAt = performance.now() + 5000;
    } catch {
      progress.retryAt = performance.now() + 5000;
      // A later playback update retries a transient connection failure; the server deduplicates it.
    } finally {
      progress.sending = false;
    }
  };
  return (
    <div
      className={`sf-reels-body ${detail?.products.length ? 'sf-reels-with-products' : ''}`}
      onKeyDown={(e) => {
        if ((e.target as HTMLElement).tagName === 'VIDEO') return;
        if (e.key === 'ArrowDown' && next) {
          e.preventDefault();
          next();
        }
        if (e.key === 'ArrowUp' && previous) {
          e.preventDefault();
          previous();
        }
      }}
    >
      <div className="sf-reels-stage">
        {detail && !failed ? (
          <video
            ref={video}
            src={detail.video}
            poster={detail.image.url}
            controls
            playsInline
            autoPlay
            preload="metadata"
            onPlaying={() => {
              playback.current.last = performance.now();
            }}
            onPause={() => {
              playback.current.last = 0;
            }}
            onWaiting={() => {
              playback.current.last = 0;
            }}
            onSeeking={() => {
              playback.current.last = 0;
            }}
            onError={() => setFailed(true)}
            onEnded={next}
            onTimeUpdate={(e) => {
              const progress = playback.current;
              const el = e.currentTarget;
              const now = performance.now();
              if (!el.paused && !el.seeking && !document.hidden) {
                if (progress.last > 0)
                  progress.seconds += Math.min(
                    1,
                    Math.max(0, (now - progress.last) / 1000),
                  );
                progress.last = now;
                if (progress.seconds >= 3) void record();
              } else progress.last = 0;
            }}
          />
        ) : (
          <div className="sf-reels-state" role="status">
            {t(failed ? 'reels.load_error' : 'reels.loading')}
            {failed && (
              <button
                type="button"
                onClick={() => {
                  setFailed(false);
                  setDetail(null);
                  setRetry(retry + 1);
                }}
              >
                {t('reels.retry')}
              </button>
            )}
          </div>
        )}
        <div className="sf-reels-navigation">
          <button
            type="button"
            disabled={!previous}
            onClick={previous}
            aria-label={t('reels.previous')}
          >
            ↑
          </button>
          <button
            type="button"
            disabled={!next}
            onClick={next}
            aria-label={t('reels.next')}
          >
            ↓
          </button>
        </div>
      </div>
      {detail && (
        <aside className="sf-reels-info">
          <p className="sf-reels-views">
            ◉ {detail.views} {t('reels.views')}
          </p>
          <h2>{detail.name}</h2>
          {detail.products.length > 0 && (
            <>
              <h3>{t('reels.products')}</h3>
              <div className="sf-reels-products">
                {detail.products.map((product) => (
                  <a
                    className="sf-reels-product"
                    key={product.id}
                    href={product.url ?? undefined}
                  >
                    <span className="sf-reels-product-media">
                      {product.image ? (
                        <img src={product.image.url} alt="" loading="lazy" />
                      ) : (
                        <NoImage />
                      )}
                    </span>
                    <span className="sf-reels-product-copy">
                      <strong>{product.name}</strong>
                      {product.rating && (
                        <span
                          className="sf-reels-rating"
                          aria-label={t('storefront.rating_summary', {
                            average: product.rating.average ?? '',
                            count: product.rating.count,
                          })}
                        >
                          <Stars
                            value={Number(product.rating.average ?? 0)}
                            size={16}
                          />{' '}
                          ({product.rating.count})
                        </span>
                      )}
                      {product.price !== null &&
                        product.price !== undefined && (
                          <span className="sf-reels-price">
                            <Money
                              amount={product.price}
                              format={detail.currency_format}
                              inline
                            />
                          </span>
                        )}
                    </span>
                  </a>
                ))}
              </div>
            </>
          )}
        </aside>
      )}
    </div>
  );
}
