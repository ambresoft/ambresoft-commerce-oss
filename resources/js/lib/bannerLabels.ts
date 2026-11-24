// Literal translation keys for banner enumerations (keys are never built at runtime).
export const bannerLabels = {
  state: {
    live: 'banners.state_live',
    scheduled: 'banners.state_scheduled',
    expired: 'banners.state_expired',
    disabled: 'banners.state_disabled',
  },
  object: {
    image: 'banners.object_image',
    youtube: 'banners.object_youtube',
    products: 'banners.object_products',
  },
  weight: {
    thin: 'banners.weight_thin',
    normal: 'banners.weight_normal',
    bold: 'banners.weight_bold',
    heavy: 'banners.weight_heavy',
  },
  hover: {
    none: 'banners.hover_none',
    darken: 'banners.hover_darken',
    lighten: 'banners.hover_lighten',
    lift: 'banners.hover_lift',
    grow: 'banners.hover_grow',
    glow: 'banners.hover_glow',
    invert: 'banners.hover_invert',
  },
  style: {
    standard: 'banners.style_standard',
    outline: 'banners.style_outline',
    text: 'banners.style_text',
  },
  direction: {
    auto: 'banners.direction_auto',
    top: 'banners.direction_top',
    bottom: 'banners.direction_bottom',
    left: 'banners.direction_left',
    right: 'banners.direction_right',
  },
  device: {
    pc: 'banners.tab_pc',
    tablet: 'banners.tab_tablet',
    phone: 'banners.tab_phone',
  },
  css: {
    'banner-button-bottom': 'banners.css_banner_button_bottom',
    'banner-enlarge-image': 'banners.css_banner_enlarge_image',
    'banner-image-width': 'banners.css_banner_image_width',
    'banner-image-edge': 'banners.css_banner_image_edge',
    'banner-background-fade': 'banners.css_banner_background_fade',
    'banner-mask-indent': 'banners.css_banner_mask_indent',
    'banner-vertical-reverse': 'banners.css_banner_vertical_reverse',
  } as Record<string, string>,
} as const;
