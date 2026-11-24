import { useEffect, useRef, useState, type MouseEvent } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { ProductPageData, PublicImage } from '../../types';
import ProductActions from './ProductActions';
import { Chevron, NoImage, Stickers } from './parts';

/** Share of the picture the lens covers, and the matching magnification of the enlarged pane. */
const LENS = 0.32;
const LENS_ZOOM = 1 / LENS;
/** Lens corner (percent) for a pointer position, kept inside the picture. */
const clampLens = (p: number) =>
  Math.min(100 - LENS * 100, Math.max(0, p - (LENS * 100) / 2));
/** Background position (percent) of the enlarged pane for a pointer position. */
const loupe = (p: number) => (clampLens(p) / (100 - LENS * 100)) * 100;

/** Full-screen pictures: thumbnails, arrows and keyboard; a click shows a lens and the enlarged area beside it (a tap zooms in place on touch screens). */
function Lightbox({
  images,
  name,
  start,
  onClose,
}: {
  images: PublicImage[];
  name: string;
  start: number;
  onClose: (index: number) => void;
}) {
  const t = useTranslation();
  const dialog = useRef<HTMLDialogElement>(null);
  const [index, setIndex] = useState(start);
  const [zoom, setZoom] = useState<{ x: number; y: number } | null>(null);
  // After a click with a mouse: a lens over the picture and the enlarged area beside it, following the mouse.
  const [lens, setLens] = useState<{ x: number; y: number } | null>(null);
  const pointer = useRef('mouse');
  const go = (step: number) => {
    setZoom(null);
    setLens(null);
    setIndex((i) => (i + step + images.length) % images.length);
  };
  useEffect(() => {
    dialog.current?.showModal();
    document.documentElement.style.overflow = 'hidden';
    return () => {
      document.documentElement.style.overflow = '';
    };
  }, []);
  const point = (e: MouseEvent<HTMLElement>) => {
    const box = e.currentTarget.getBoundingClientRect();
    return {
      x: ((e.clientX - box.left) / box.width) * 100,
      y: ((e.clientY - box.top) / box.height) * 100,
    };
  };
  const image = images[index];
  return (
    <dialog
      ref={dialog}
      className="sf-lightbox"
      aria-label={name}
      onCancel={(e) => {
        e.preventDefault();
        onClose(index);
      }}
      onKeyDown={(e) => {
        if (e.key === 'ArrowLeft') go(-1);
        if (e.key === 'ArrowRight') go(1);
      }}
    >
      {images.length > 1 && (
        <div className="sf-lightbox-thumbs">
          {images.map((img, n) => (
            <button
              key={img.url}
              type="button"
              aria-current={n === index ? 'true' : undefined}
              aria-label={t('storefront.gallery_picture', {
                n: n + 1,
                total: images.length,
              })}
              onClick={() => {
                setZoom(null);
                setIndex(n);
              }}
            >
              <img src={img.url} alt="" loading="lazy" />
            </button>
          ))}
        </div>
      )}
      <div className="sf-lightbox-stage">
        <div
          className="sf-lightbox-track"
          style={{ transform: `translateX(${-index * 100}%)` }}
        >
          {images.map((img, n) =>
            n === index ? (
              <div key={img.url} className="sf-lightbox-slide">
                <div
                  className={`sf-lightbox-figure ${zoom || lens ? 'is-zoomed' : ''}`}
                  onPointerDown={(e) => {
                    pointer.current = e.pointerType;
                  }}
                  onClick={(e) => {
                    // A click switches the magnifier on or off: with a mouse the lens and the enlarged pane, on touch an
                    // in-place zoom (the pane would not fit beside the picture).
                    if (lens || zoom) {
                      setLens(null);
                      setZoom(null);
                    } else if (pointer.current === 'mouse') setLens(point(e));
                    else setZoom(point(e));
                  }}
                  onPointerMove={(e) => {
                    if (zoom) setZoom(point(e));
                    else if (lens) setLens(point(e));
                  }}
                  role="button"
                  tabIndex={0}
                  aria-label={t(
                    zoom || lens ? 'storefront.zoom_out' : 'storefront.zoom_in',
                  )}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      setLens(null);
                      setZoom(zoom || lens ? null : { x: 50, y: 50 });
                    }
                  }}
                >
                  <img
                    src={image.url}
                    alt={`${name} (${index + 1}/${images.length})`}
                    draggable={false}
                    style={
                      zoom
                        ? {
                            transform: `scale(${LENS_ZOOM})`,
                            transformOrigin: `${zoom.x}% ${zoom.y}%`,
                          }
                        : undefined
                    }
                  />
                  {lens && !zoom && (
                    <span
                      className="sf-lightbox-lens"
                      aria-hidden="true"
                      style={{
                        width: `${LENS * 100}%`,
                        height: `${LENS * 100}%`,
                        left: `${clampLens(lens.x)}%`,
                        top: `${clampLens(lens.y)}%`,
                      }}
                    />
                  )}
                </div>
              </div>
            ) : (
              <div
                key={img.url}
                className="sf-lightbox-slide"
                aria-hidden="true"
              >
                <div className="sf-lightbox-figure">
                  <img src={img.url} alt="" draggable={false} loading="lazy" />
                </div>
              </div>
            ),
          )}
        </div>
        {lens && !zoom && (
          <div
            className="sf-lightbox-loupe"
            aria-hidden="true"
            style={{
              backgroundImage: `url("${image.url.replace(/"/g, '%22')}")`,
              backgroundSize: `${LENS_ZOOM * 100}% auto`,
              backgroundPosition: `${loupe(lens.x)}% ${loupe(lens.y)}%`,
              aspectRatio: `${image.width} / ${image.height}`,
            }}
          />
        )}
      </div>
      {images.length > 1 && (
        <>
          <button
            type="button"
            className="sf-lightbox-arrow is-prev"
            aria-label={t('storefront.gallery_prev')}
            onClick={() => go(-1)}
          >
            <Chevron direction="prev" />
          </button>
          <button
            type="button"
            className="sf-lightbox-arrow is-next"
            aria-label={t('storefront.gallery_next')}
            onClick={() => go(1)}
          >
            <Chevron direction="next" />
          </button>
        </>
      )}
      <button
        type="button"
        className="sf-lightbox-close"
        aria-label={t('storefront.close')}
        autoFocus
        onClick={() => onClose(index)}
      >
        ×
      </button>
    </dialog>
  );
}

/** Product pictures: thumbnails beside (desktop) or dots below (phones), sliding smoothly; a click opens the full gallery. */
export default function Gallery({ product }: { product: ProductPageData }) {
  const t = useTranslation();
  const [index, setIndex] = useState(0);
  const [open, setOpen] = useState(false);
  const [drag, setDrag] = useState(0);
  const start = useRef<number | null>(null);
  const images = product.images;
  const go = (step: number) =>
    setIndex((i) => (i + step + images.length) % images.length);
  if (images.length === 0)
    return (
      <div className="sf-pdp-gallery">
        <div className="sf-pdp-stage">
          <NoImage />
          <ProductActions product={product} />
        </div>
      </div>
    );
  return (
    <div className="sf-pdp-gallery">
      {images.length > 1 && (
        <div className="sf-pdp-thumbs" role="tablist" aria-label={product.name}>
          {images.map((image, n) => (
            <button
              key={image.url}
              type="button"
              role="tab"
              aria-selected={n === index}
              aria-label={t('storefront.gallery_picture', {
                n: n + 1,
                total: images.length,
              })}
              className={n === index ? 'is-active' : undefined}
              onClick={() => setIndex(n)}
            >
              <img src={image.url} alt="" loading="lazy" />
            </button>
          ))}
        </div>
      )}
      <div className="sf-pdp-stage">
        <div
          className="sf-pdp-track"
          style={{
            transform: `translateX(calc(${-index * 100}% + ${drag}px))`,
            transition: drag ? 'none' : undefined,
          }}
          onPointerDown={(e) => {
            start.current = e.clientX;
            e.currentTarget.setPointerCapture?.(e.pointerId);
          }}
          onPointerMove={(e) => {
            if (start.current !== null && images.length > 1)
              setDrag(e.clientX - start.current);
          }}
          onPointerUp={(e) => {
            const moved =
              start.current === null ? 0 : e.clientX - start.current;
            start.current = null;
            setDrag(0);
            if (Math.abs(moved) > 40 && images.length > 1)
              go(moved < 0 ? 1 : -1);
            else if (Math.abs(moved) < 8) setOpen(true);
          }}
          onPointerCancel={() => {
            start.current = null;
            setDrag(0);
          }}
        >
          {images.map((image, n) => (
            <div
              key={image.url}
              className="sf-pdp-slide"
              aria-hidden={n !== index}
            >
              <img
                src={image.url}
                alt={`${product.name}${images.length > 1 ? ` (${n + 1}/${images.length})` : ''}`}
                width={image.width}
                height={image.height}
                loading={n === 0 ? 'eager' : 'lazy'}
                fetchPriority={n === 0 ? 'high' : undefined}
                draggable={false}
              />
            </div>
          ))}
        </div>
        <Stickers stickers={product.stickers ?? []} placement="image_top" />
        <ProductActions product={product} />
        {images.length > 1 && (
          <>
            <button
              type="button"
              className="sf-pdp-arrow is-prev"
              aria-label={t('storefront.gallery_prev')}
              onClick={() => go(-1)}
            >
              <Chevron direction="prev" />
            </button>
            <button
              type="button"
              className="sf-pdp-arrow is-next"
              aria-label={t('storefront.gallery_next')}
              onClick={() => go(1)}
            >
              <Chevron direction="next" />
            </button>
            <div className="sf-pdp-dots" aria-hidden="true">
              {images.map((image, n) => (
                <span
                  key={image.url}
                  className={n === index ? 'is-active' : undefined}
                />
              ))}
            </div>
          </>
        )}
      </div>
      {open && (
        <Lightbox
          images={images}
          name={product.name}
          start={index}
          onClose={(n) => {
            setIndex(n);
            setOpen(false);
          }}
        />
      )}
    </div>
  );
}
