import { render } from '@testing-library/react';
import { expect, it } from 'vitest';
import TabIcon from '../components/admin/TabIcon';

const markup = (name: string) =>
  render(<TabIcon name={name} />).container.innerHTML;

it('follows chained aliases instead of falling back to the settings gear', () => {
  const gear = markup('core.settings');
  // Report cards: orders_list → orders → core.orders, by_payment → payments → core.payment-methods, …
  for (const [key, target] of [
    ['orders_list', 'core.orders'],
    ['by_payment', 'core.payment-methods'],
    ['sales_detail', 'core.reports'],
    ['by_shipping', 'core.shipping-taxes'],
  ]) {
    expect(markup(key!)).toBe(markup(target!));
    expect(markup(key!)).not.toBe(gear);
  }
});
