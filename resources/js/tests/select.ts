import { fireEvent, within } from '@testing-library/react';

// Helpers for the custom Select (select-only combobox) in component tests.
export function options(trigger: HTMLElement) {
  if (trigger.getAttribute('aria-expanded') !== 'true')
    fireEvent.click(trigger);
  const list = document.getElementById(trigger.getAttribute('aria-controls')!)!;
  return within(list).getAllByRole('option');
}

export function choose(trigger: HTMLElement, label: string | RegExp) {
  const option = options(trigger).find((item) =>
    typeof label === 'string'
      ? item.textContent === label
      : label.test(item.textContent ?? ''),
  );
  if (!option) throw new Error(`No option ${String(label)}`);
  fireEvent.click(option);
}
