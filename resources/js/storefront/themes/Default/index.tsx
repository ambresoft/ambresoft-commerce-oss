import HeaderAccountMenu from './HeaderAccountMenu';
import { ReviewsEnabledContext } from './AccountParts';
import Directory from './Directory';
import AddonStyles from '../../components/AddonStyles';
import GdprCookies from '../../addons/GdprCookies';
import GoogleAnalytics from '../../components/GoogleAnalytics';
import GoogleTagManager from '../../components/GoogleTagManager';
import AddonFrame from '../../components/AddonFrame';
import AddonPageLinks from '../../components/AddonPageLinks';
import AddonWidgets from '../../components/AddonWidgets';
import { useState, type CSSProperties } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import { registerTemplate, registerTheme, templateFor } from '../../registry';
import type { PublicBlock, PublicGrid, StorefrontPage } from '../../types';
import Account from './Account';
import CategoryLanding from './CategoryLanding';
import BrandHeader from './CatalogLanding';
import CategoryListing, { ProductFilters } from './CategoryListing';
import Comparison from './Comparison';
import Footer from './Footer';
import BackToTop from './BackToTop';
import LinkDirectory from './LinkDirectory';
import MenuBlock, { LanguageSwitcher } from './Menu';
import Newsletter from './Newsletter';
import OrderTracking from './OrderTracking';
import ProductPage from './ProductPage';
import StaticPage from './StaticPage';
import { BlogLanding, BlogPost } from './Blog';
import Wishlist from './Wishlist';
import WishlistProvider from './WishlistProvider';
import CartProvider from './CartProvider';
import Cart from './Cart';
import HeaderSearch from './HeaderSearch';
import CompactHeader from './CompactHeader';
import PopularSearches from './PopularSearches';
import Reels from './Reels';
import Checkout, { CheckoutHeader } from './Checkout';
import HeaderCart from './MiniCart';
import CallRequest from './CallRequest';
import { useWishlist } from './wishlistContext';
import {
  blockStyle,
  CardStyleContext,
  Collection,
  InfoTip,
  Picture,
  ProductLink,
} from './parts';
import { BlockTitleIcon } from './DealCard';
import './theme.css';

function BrandAdBadge() {
  const t = useTranslation();
  return (
    <InfoTip
      className="sf-brand-ad"
      label={t('storefront.brand_ad')}
      text={t('storefront.brand_ad_tooltip')}
    >
      {t('storefront.brand_ad')}
    </InfoTip>
  );
}
function HeaderIcon({
  name,
  filled = false,
}: {
  name: 'search' | 'user' | 'heart' | 'phone';
  filled?: boolean;
}) {
  const paths = {
    phone: [
      'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2Z',
    ],
    search: ['M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14Z', 'm20 20-4-4'],
    user: [
      'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
      'M4 20a8 8 0 0 1 16 0' + (filled ? 'Z' : ''),
    ],
    heart: [
      'M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z',
    ],
  }[name];
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="26"
      height="26"
      fill={filled ? '#fff' : 'none'}
      stroke={filled ? '#fff' : 'currentColor'}
      strokeWidth={name === 'phone' ? 1.6 : 2}
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {paths.map((d) => (
        <path key={d} d={d} />
      ))}
    </svg>
  );
}
/** The store header: logo, search, account, favorites and cart, each switchable in the block settings. */
function Identity({ block }: { block: PublicBlock }) {
  // Each header style uses the shared Identity settings.
  if (block.content.display?.header_style === 'compact')
    return <CompactHeader block={block} />;
  if (block.content.display?.header_style === 'fashion')
    return (
      <MenuBlock
        fashion
        block={{
          ...block,
          content: { ...block.content, main: block.content.drawer },
        }}
      />
    );
  return <ClassicIdentity block={block} />;
}
export function ClassicIdentity({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const d = block.content.display ?? {};
  // The header heart follows the block option and the store-wide wishlist switch.
  const wishlist = useWishlist();
  const favourites = d.show_favorites !== false && wishlist.enabled;
  const languages =
    d.show_language_switcher === false ? [] : (block.content.languages ?? []);
  const order = d.icon_order ?? [
    'language',
    'account',
    'call',
    'favorites',
    'cart',
  ];
  const [calling, setCalling] = useState(false);
  const callForm =
    d.show_call_request === true ? block.content.call_request_form : null;
  return (
    <>
      <div className="sf-identity">
        {d.show_logo !== false && (
          <a className="sf-logo" href="/" aria-label={block.content.name}>
            {block.content.logo ? (
              <Picture
                image={block.content.logo}
                mobile={block.content.mobile_logo}
                alt={block.content.name ?? ''}
                eager
              />
            ) : (
              <strong>{block.content.name}</strong>
            )}
          </a>
        )}
        {d.show_search !== false && (
          <HeaderSearch display={d} icon={<HeaderIcon name="search" />} />
        )}
        <AddonPageLinks />
        {(d.show_account !== false ||
          callForm ||
          favourites ||
          d.show_cart !== false ||
          languages.length > 0) && (
          <nav
            className="sf-header-actions"
            aria-label={t('storefront.account')}
          >
            {order.map((icon) => {
              if (icon === 'language')
                return languages.length > 0 ? (
                  <LanguageSwitcher key={icon} languages={languages} />
                ) : null;
              if (icon === 'account')
                return d.show_account !== false ? (
                  <HeaderAccountMenu
                    key={icon}
                    signedIn={!!block.content.account_signed_in}
                    className="sf-header-account"
                    aria-label={t('storefront.account')}
                  >
                    <HeaderIcon
                      name="user"
                      filled={!!block.content.account_signed_in}
                    />
                  </HeaderAccountMenu>
                ) : null;
              if (icon === 'call')
                return callForm ? (
                  <button
                    key={icon}
                    type="button"
                    className="sf-header-call"
                    aria-label={t('storefront.call_request_title')}
                    title={t('storefront.call_request_title')}
                    aria-haspopup="dialog"
                    onClick={() => setCalling(true)}
                  >
                    <HeaderIcon name="phone" />
                  </button>
                ) : null;
              if (icon === 'favorites')
                return favourites ? (
                  <a
                    key={icon}
                    href="/wishlist"
                    aria-label={t('storefront.favorites')}
                  >
                    <HeaderIcon name="heart" />
                  </a>
                ) : null;
              if (icon === 'cart')
                return d.show_cart !== false ? (
                  <HeaderCart key={icon} mini={d.show_mini_cart !== false} />
                ) : null;
              return null;
            })}
          </nav>
        )}
      </div>
      {calling && callForm && (
        <CallRequest form={callForm} onClose={() => setCalling(false)} />
      )}
    </>
  );
}
/** Free rich text above the header: one text on either side, split in two, or centered. */
function Topbar({ block }: { block: PublicBlock }) {
  const d = block.content.display ?? {};
  const layout = d.layout ?? 'split';
  return (
    <div className={`sf-topbar sf-topbar-${layout}`}>
      <div
        className="sf-topbar-text"
        dangerouslySetInnerHTML={{ __html: block.content.html ?? '' }}
      />
      {layout === 'split' && block.content.html_right && (
        <div
          className="sf-topbar-text sf-topbar-right"
          dangerouslySetInnerHTML={{ __html: block.content.html_right }}
        />
      )}
    </div>
  );
}
function Html({ block }: { block: PublicBlock }) {
  return (
    <div
      className="sf-html"
      dangerouslySetInnerHTML={{ __html: block.content.html ?? '' }}
    />
  );
}
function Grids({
  nodes,
  columns = 16,
}: {
  nodes: PublicGrid[];
  columns?: number;
}) {
  const t = useTranslation();
  return (
    <>
      {[...new Set(nodes.map((g) => g.row))].map((row) => (
        <div
          className="sf-grid-row"
          style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}
          key={row}
        >
          {nodes
            .filter((g) => g.row === row)
            .map((g) => (
              <div
                key={g.id}
                className="sf-grid"
                style={{ gridColumn: `span ${g.width}` }}
              >
                {g.blocks.map((block) => {
                  const Template = templateFor('default', block.template);
                  return Template ? (
                    <section
                      key={block.id}
                      style={blockStyle(block)}
                      className={`sf-block ${block.template === 'footer' ? 'sf-block-footer ' : ''}${block.content.display?.show_top_border ? 'sf-block-topline ' : ''}${block.content.display?.full_bleed ? 'sf-block-bleed sf-block-chrome ' + (block.content.display?.allow_overflow ? 'sf-block-overflow' : '') : block.content.display?.background_color ? 'sf-block-filled' : ''} ${block.content.display?.background_color && block.content.display.full_width_background ? 'sf-block-bleed' : ''} ${block.mobile ? '' : 'sf-hide-mobile'} ${block.tablet ? '' : 'sf-hide-tablet'} ${block.desktop ? '' : 'sf-hide-desktop'} ${block.css_class}`}
                    >
                      {(block.title ||
                        block.content.link ||
                        block.content.brand ||
                        block.content.paid_ad) && (
                        <div className="sf-block-head">
                          {block.content.brand ? (
                            <div className="sf-brand-heading">
                              {block.content.brand.image && (
                                <ProductLink url={block.content.brand.url}>
                                  <img
                                    src={block.content.brand.image.url}
                                    width={64}
                                    height={64}
                                    alt={block.content.brand.image_alt}
                                    loading="lazy"
                                  />
                                </ProductLink>
                              )}
                              <div>
                                <div className="sf-brand-title-row">
                                  <h2>
                                    <ProductLink url={block.content.brand.url}>
                                      {block.content.brand.name}
                                    </ProductLink>
                                  </h2>
                                  {block.content.paid_ad && <BrandAdBadge />}
                                </div>
                                {(block.content.is_bestsellers ||
                                  block.title) && (
                                  <p>
                                    {block.content.is_bestsellers
                                      ? t('storefront.brand_bestsellers')
                                      : block.title}
                                  </p>
                                )}
                              </div>
                            </div>
                          ) : block.content.paid_ad ? (
                            <div className="sf-brand-title-row">
                              {block.title && <h2>{block.title}</h2>}
                              <BrandAdBadge />
                            </div>
                          ) : block.title ? (
                            <h2>
                              <BlockTitleIcon
                                name={block.content.display?.title_icon}
                              />
                              {block.title}
                            </h2>
                          ) : (
                            <span />
                          )}
                          {block.content.link && (
                            <a
                              className="sf-block-link"
                              href={block.content.link.url}
                            >
                              {block.content.link.text}
                            </a>
                          )}
                        </div>
                      )}
                      <Template block={block} />
                    </section>
                  ) : null;
                })}
                <Grids nodes={g.children} columns={g.width} />
              </div>
            ))}
        </div>
      ))}
    </>
  );
}
function DefaultTheme({ page }: { page: StorefrontPage }) {
  const t = useTranslation();
  const region = (key: string) => <Grids nodes={page.regions[key] ?? []} />;
  return (
    <CardStyleContext.Provider value={page.card_style ?? 'classic'}>
      <ReviewsEnabledContext.Provider value={page.reviews_enabled !== false}>
        <WishlistProvider
          signedIn={!!page.signed_in}
          enabled={page.wishlist_enabled !== false}
        >
          <CartProvider
            count={page.cart_count ?? 0}
            enabled={page.cart_enabled !== false}
          >
            <div
              className={`sf-default ${page.blend_images ? 'sf-blend' : ''}`}
              style={
                {
                  '--sf-max-width': `${page.max_width ?? 1320}px`,
                  ...(page.colors
                    ? {
                        '--sf-primary': page.colors.primary,
                        '--sf-primary-hover': page.colors.primary_hover,
                        '--sf-on-primary': page.colors.on_primary,
                        '--sf-text': page.colors.text,
                        '--sf-background': page.colors.background,
                        '--sf-sale': page.colors.sale,
                      }
                    : {}),
                } as CSSProperties
              }
            >
              <AddonStyles />
              <GoogleTagManager />
              <GoogleAnalytics />
              <GdprCookies />
              <AddonWidgets />
              {page.show_back_to_top !== false && !page.frame && <BackToTop />}
              <a className="sf-skip" href="#storefront-main">
                {t('storefront.skip')}
              </a>
              {page.regions.top_panel && (
                <div className="sf-region sf-top-panel">
                  {region('top_panel')}
                </div>
              )}
              {page.regions.header && (
                <header className="sf-region sf-header">
                  {region('header')}
                </header>
              )}
              {page.regions.navigation && (
                <nav
                  className="sf-region sf-navigation"
                  aria-label={t('storefront.home')}
                >
                  {region('navigation')}
                </nav>
              )}
              {page.regions.before_content && (
                <div className="sf-region">{region('before_content')}</div>
              )}
              {page.frame ? (
                <main id="storefront-main">
                  <AddonFrame {...page.frame} />
                </main>
              ) : (
                <main id="storefront-main" className="sf-region sf-main">
                  {region('content')}
                </main>
              )}
              {page.regions.after_content && !page.frame && (
                <div className="sf-region">{region('after_content')}</div>
              )}
              {page.regions.footer && !page.frame && (
                <footer className="sf-region sf-footer">
                  {region('footer')}
                </footer>
              )}
            </div>
          </CartProvider>
        </WishlistProvider>
      </ReviewsEnabledContext.Provider>
    </CardStyleContext.Provider>
  );
}
registerTheme('default', DefaultTheme);
/** A product carousel, with the block's picture beside it when one is set. */
function ProductCarousel({ block }: { block: PublicBlock }) {
  const image = block.content.side_image;
  if (!image) return <Collection block={block} />;
  return (
    <div className={`sf-side-image is-${image.side}`}>
      {image.link ? (
        <a className="sf-side-image-link" href={image.link}>
          <picture>
            {image.mobile && (
              <source
                media="(max-width: 639px)"
                srcSet={image.mobile.url}
                width={image.mobile.width}
                height={image.mobile.height}
              />
            )}
            <img
              src={image.url}
              width={image.width}
              height={image.height}
              alt=""
              loading="lazy"
              style={
                {
                  '--sf-fit': image.fit,
                  '--sf-fit-mobile': image.fit_mobile,
                } as CSSProperties
              }
            />
          </picture>
        </a>
      ) : (
        <picture>
          {image.mobile && (
            <source
              media="(max-width: 639px)"
              srcSet={image.mobile.url}
              width={image.mobile.width}
              height={image.mobile.height}
            />
          )}
          <img
            src={image.url}
            width={image.width}
            height={image.height}
            alt=""
            loading="lazy"
            style={
              {
                '--sf-fit': image.fit,
                '--sf-fit-mobile': image.fit_mobile,
              } as CSSProperties
            }
          />
        </picture>
      )}
      <div className="sf-side-image-body">
        <Collection block={block} />
      </div>
    </div>
  );
}
registerTemplate('default', 'reel_carousel', Reels);
registerTemplate('default', 'reel_single', Reels);
registerTemplate('default', 'product_carousel', ProductCarousel);
for (const template of [
  'banner_single',
  'banner_carousel',
  'product_single',
  'category_single',
  'category_carousel',
  'brand_single',
  'brand_carousel',
  'coupon_carousel',
  'offer_carousel',
  'blog_single',
  'blog_carousel',
  'menu_tiles_carousel',
  'menu_circles_carousel',
  'menu_cards_carousel',
])
  registerTemplate('default', template, Collection);
registerTemplate('default', 'html', Html);
registerTemplate('default', 'identity', Identity);
registerTemplate('default', 'topbar', Topbar);
registerTemplate('default', 'menu', MenuBlock);
registerTemplate('default', 'link_directory', LinkDirectory);
registerTemplate('default', 'footer', Footer);
registerTemplate('default', 'newsletter', Newsletter);
/** The main content block shows what its page owns: the product, a landing category, a product listing (category or brand), the cart, the checkout, the wishlist, the account or the comparison. */
function MainContent({ block }: { block: PublicBlock }) {
  if (block.content.directory) return <Directory block={block} />;
  if (block.content.tracking) return <OrderTracking block={block} />;
  if (block.content.page) return <StaticPage block={block} />;
  if (block.content.blog) return <BlogLanding block={block} />;
  if (block.content.post) return <BlogPost block={block} />;
  if (block.content.account) return <Account block={block} />;
  if (block.content.comparison) return <Comparison block={block} />;
  if (block.content.cart) return <Cart block={block} />;
  if (block.content.checkout) return <Checkout block={block} />;
  if (block.content.category) return <CategoryLanding block={block} />;
  if (block.content.listing) return <CategoryListing block={block} />;
  return block.content.wishlist ? (
    <Wishlist block={block} />
  ) : (
    <ProductPage block={block} />
  );
}
registerTemplate('default', 'main_content', MainContent);
registerTemplate('default', 'product_filters', ProductFilters);
registerTemplate('default', 'page_header', BrandHeader);
registerTemplate('default', 'checkout_header', CheckoutHeader);
registerTemplate('default', 'popular_searches', PopularSearches);
export default DefaultTheme;
