// Literal translation keys for page enumerations (keys are never built at runtime).
export const pageLabels = {
  state: {
    live: 'pages.state_live',
    hidden: 'pages.state_hidden',
    disabled: 'pages.state_disabled',
    scheduled: 'pages.state_scheduled',
    expired: 'pages.state_expired',
  },
  status: {
    active: 'pages.status_active',
    hidden: 'pages.status_hidden',
    disabled: 'pages.status_disabled',
  },
  facebook: {
    activities: 'pages.fb_activities',
    businesses: 'pages.fb_businesses',
    groups: 'pages.fb_groups',
    organizations: 'pages.fb_organizations',
    people: 'pages.fb_people',
    places: 'pages.fb_places',
    products: 'pages.fb_products',
    websites: 'pages.fb_websites',
  } as Record<string, string>,
  fieldType: {
    input: 'pages.type_input',
    textarea: 'pages.type_textarea',
    select: 'pages.type_select',
    radio: 'pages.type_radio',
    checkboxes: 'pages.type_checkboxes',
    checkbox: 'pages.type_checkbox',
    date: 'pages.type_date',
    email: 'pages.type_email',
    number: 'pages.type_number',
    phone: 'pages.type_phone',
    country: 'pages.type_country',
    region: 'pages.type_region',
    file: 'pages.type_file',
    header: 'pages.type_header',
    separator: 'pages.type_separator',
  },
  placement: {
    before: 'pages.placement_before',
    after: 'pages.placement_after',
    marker: 'pages.placement_marker',
  },
} as const;

export const pageStateTone = {
  live: 'success',
  hidden: 'warning',
  disabled: 'neutral',
  scheduled: 'warning',
  expired: 'neutral',
} as const;
