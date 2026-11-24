import { render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import OrderTracking from '../storefront/themes/Default/OrderTracking';
import type { PublicBlock } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string, replace?: Record<string, string>) =>
    replace ? `${key} ${Object.values(replace).join(' ')}` : key,
}));

type Tracking = NonNullable<PublicBlock['content']['tracking']>;
const block = (tracking: Tracking) =>
  ({ content: { tracking } }) as unknown as PublicBlock;

it('starts with the lookup form and where to find the number', () => {
  render(
    <OrderTracking block={block({ number: '', state: null, order: null })} />,
  );
  const form = screen
    .getByRole('textbox', { name: 'storefront.tracking_number' })
    .closest('form')!;
  expect(form).toHaveAttribute('method', 'get');
  expect(form).toHaveAttribute('action', '/order-status');
  expect(
    screen.getByRole('link', { name: 'storefront.tracking_help_account_link' }),
  ).toHaveAttribute('href', '/account/orders');
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('shows the found order with its current status first and the history newest first', () => {
  render(
    <OrderTracking
      block={block({
        number: '100004',
        state: 'found',
        order: {
          number: '100004',
          created_at: '2026-11-20T10:00:00Z',
          status: { name: 'Shipped', color: '#7c3aed' },
          timeline: [
            {
              status: { name: 'Open', color: '#2563eb' },
              at: '2026-11-20T10:00:00Z',
            },
            {
              status: { name: 'Shipped', color: '#7c3aed' },
              at: '2026-11-21T09:00:00Z',
            },
          ],
          account_url: '/account/orders/4',
        },
      })}
    />,
  );
  expect(
    screen.getByRole('heading', { name: 'storefront.tracking_order 100004' }),
  ).toBeInTheDocument();
  const steps = within(screen.getByRole('list')).getAllByRole('listitem');
  expect(
    steps.map((step) => step.querySelector('strong')?.textContent),
  ).toEqual(['Shipped', 'Open']);
  expect(steps[0]).toHaveAttribute('aria-current', 'step');
  expect(
    screen.getByRole('link', { name: 'storefront.tracking_view_order' }),
  ).toHaveAttribute('href', '/account/orders/4');
});

it('explains a missing order and asks to sign in for a customer order', () => {
  const { rerender } = render(
    <OrderTracking
      block={block({ number: '999', state: 'not_found', order: null })}
    />,
  );
  expect(screen.getByRole('alert')).toHaveTextContent(
    'storefront.tracking_not_found',
  );
  rerender(
    <OrderTracking
      block={block({ number: '100001', state: 'login', order: null })}
    />,
  );
  expect(screen.getByRole('status')).toHaveTextContent(
    'storefront.tracking_login_title',
  );
  expect(
    screen.getByRole('link', { name: 'storefront.tracking_login' }),
  ).toHaveAttribute('href', '/login');
});
