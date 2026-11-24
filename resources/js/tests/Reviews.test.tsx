import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import Index from '../pages/Admin/Reviews/Index';
import Edit from '../pages/Admin/Reviews/Edit';
import type { SharedProps } from '../types/page';
import type { ReviewDetail, ReviewRow, ReviewStatus } from '../types/reviews';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ url: '/admin', props: shared.props }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
const shell = { navigation: [], breadcrumbs: [] };
const statuses: ReviewStatus[] = ['pending', 'approved', 'rejected', 'spam'];
const row: ReviewRow = {
  id: '7',
  version: 3,
  rating: 4,
  title: '<b>Solid</b>',
  status: 'pending',
  verified_purchase: true,
  product: {
    id: '12',
    name: 'Desk lamp',
    sku: 'LAMP-1',
    status: 'active',
    trashed: false,
  },
  customer: { id: '5', name: 'Maria Example', email: null },
  created_at: '2026-10-13T09:00:00Z',
  updated_at: '2026-10-13T09:00:00Z',
  moderated_at: null,
  edited_at: null,
  excerpt: 'Bright and quiet',
  truncated: true,
};
const page = (data: ReviewRow[]) => ({
  data,
  total: data.length,
  current_page: 1,
  last_page: 1,
  prev_page_url: null,
  next_page_url: null,
});
const detail: ReviewDetail = {
  ...row,
  body: '<img src=x onerror=alert(1)> Bright and quiet',
  moderation_note: 'Checked order',
  moderator_name: null,
  editor_name: null,
  transitions: ['approved', 'rejected', 'spam'],
  product_rating: {
    count: 0,
    average: null,
    distribution: { '1': 0, '2': 0, '3': 0, '4': 0, '5': 0 },
  },
};
const labels = {
  'reviews.title': 'Reviews',
  'reviews.action_approved': 'Approve',
  'reviews.action_rejected': 'Reject',
  'reviews.action_spam': 'Mark as spam',
  'reviews.action_pending': 'Return to pending',
  'reviews.status_pending': 'Pending',
  'reviews.status_approved': 'Approved',
  'reviews.select_id': 'Select review #:id',
  'reviews.select_page': 'Select reviews on this page',
  'reviews.delete_id': 'Delete review #:id',
  'reviews.open_id': 'Open review #:id',
  'reviews.delete': 'Delete review',
  'reviews.delete_title': 'Delete review #:id?',
  'reviews.verified_badge': 'Verified purchase',
  'reviews.stars_label': ':count of 5 stars',
  'reviews.empty': 'No reviews have been submitted yet.',
  'reviews.empty_filtered': 'No reviews match these filters.',
  'reviews.moderation_note': 'Internal note',
  'reviews.body': 'Review text',
  'reviews.review_title': 'Title',
  'reviews.rating': 'Rating',
  'reviews.no_rating': 'No approved reviews yet.',
};
beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'CMS' },
    auth: {
      user: {
        id: '1',
        name: 'Admin',
        email: 'a@example.test',
        emailVerified: true,
      },
      canAccessAdmin: true,
    },
    flash: { status: null },
    capabilities: {
      'reviews.view': true,
      'reviews.moderate': true,
      'reviews.edit': true,
      'reviews.delete': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...labels },
      locales: [{ code: 'en', name: 'English' }],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  } as SharedProps;
});

it('renders customer text as text, with rating, status and verification', () => {
  render(
    <Index
      {...shell}
      reviews={page([row])}
      filters={{}}
      statuses={statuses}
      bulkResults={[]}
    />,
  );
  const tr = screen.getByText('Desk lamp').closest('tr')!;
  expect(within(tr).getByText('<b>Solid</b>')).toBeInTheDocument();
  expect(within(tr).getByText('Bright and quiet…')).toBeInTheDocument();
  expect(within(tr).getByRole('img', { name: '4 of 5 stars' })).toBeVisible();
  expect(within(tr).getByText('Pending')).toBeInTheDocument();
  expect(within(tr).getByText('Verified purchase')).toBeInTheDocument();
  expect(document.querySelector('main b, table b')).toBeNull();
});

it('bulk-moderates only the selected reviews of the current page', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(
    <Index
      {...shell}
      reviews={page([row, { ...row, id: '8', product: { ...row.product } }])}
      filters={{}}
      statuses={statuses}
      bulkResults={[]}
    />,
  );
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select review #8' }));
  fireEvent.click(screen.getByRole('button', { name: 'Mark as spam' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/reviews/bulk',
    expect.objectContaining({ ids: ['8'], status: 'spam' }),
    expect.any(Object),
  );
});

it('confirms deletion in a dialog and sends the current version', () => {
  const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
  render(
    <Index
      {...shell}
      reviews={page([row])}
      filters={{}}
      statuses={statuses}
      bulkResults={[]}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Delete review #7' }));
  expect(remove).not.toHaveBeenCalled();
  fireEvent.click(
    within(screen.getByRole('alertdialog')).getByRole('button', {
      name: 'Delete review',
    }),
  );
  expect(remove).toHaveBeenCalledWith(
    '/admin/reviews/7',
    expect.objectContaining({
      data: expect.objectContaining({ version: 3 }),
    }),
  );
});

it('hides selection and destructive actions from view-only staff', () => {
  shared.props.capabilities = { 'reviews.view': true };
  const view = render(
    <Index
      {...shell}
      reviews={page([row])}
      filters={{}}
      statuses={statuses}
      bulkResults={[]}
    />,
  );
  expect(screen.queryByRole('checkbox')).toBeNull();
  expect(screen.queryByRole('button', { name: 'Delete review #7' })).toBeNull();
  view.unmount();
  render(
    <Index
      {...shell}
      reviews={page([])}
      filters={{ status: 'spam' }}
      statuses={statuses}
      bulkResults={[{ id: '9', success: false, reason: 'Not allowed' }]}
    />,
  );
  expect(screen.getByText('No reviews match these filters.')).toBeVisible();
  expect(screen.getByText('#9: Not allowed')).toBeVisible();
});

it('offers only the allowed moderation actions and posts the version', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<Edit {...shell} review={detail} returnTo="/admin/reviews" />);
  expect(screen.getByRole('button', { name: 'Approve' })).toBeVisible();
  expect(
    screen.queryByRole('button', { name: 'Return to pending' }),
  ).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Reject' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/reviews/7/status',
    { version: 3, status: 'rejected' },
    expect.any(Object),
  );
  expect(screen.getByText('No approved reviews yet.')).toBeVisible();
});

it('saves only the fields the staff member may change', () => {
  shared.props.capabilities = { 'reviews.view': true, 'reviews.edit': true };
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(<Edit {...shell} review={detail} returnTo="/admin/reviews" />);
  expect(screen.queryByLabelText('Internal note')).toBeNull();
  expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
  fireEvent.change(screen.getByLabelText('Review text'), {
    target: { value: 'Corrected' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(visit.mock.calls[0][0]).toBe('/admin/reviews/7');
  const options = visit.mock.calls[0][1] as {
    method: string;
    data: Record<string, unknown>;
  };
  expect(options.method).toBe('patch');
  expect(options.data).toMatchObject({ version: 3, body: 'Corrected' });
  expect(options.data).not.toHaveProperty('moderation_note');
});

it('shows read-only staff the review as plain text', () => {
  shared.props.capabilities = { 'reviews.view': true };
  render(<Edit {...shell} review={detail} returnTo="/admin/reviews" />);
  expect(
    screen.getByText('<img src=x onerror=alert(1)> Bright and quiet'),
  ).toBeVisible();
  expect(document.querySelector('img[src="x"]')).toBeNull();
  expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
  expect(screen.queryByLabelText('Internal note')).toBeNull();
});
