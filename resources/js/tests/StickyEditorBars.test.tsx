import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { useStickyEditorBars } from '../lib/useStickyEditorBars';

it('pins editor navigation at the scrolling workspace edge and releases it when scrolling back', () => {
  let barTop = 100;
  const bounds = vi
    .spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    .mockImplementation(function (this: HTMLElement) {
      return {
        top: this.hasAttribute('scroll-region') ? 48 : barTop,
      } as DOMRect;
    });
  function Editor() {
    const content = useStickyEditorBars('/admin/categories/1/edit');
    return (
      <div scroll-region="" data-testid="workspace">
        <main ref={content}>
          <div className="editor-tab-bar" data-testid="tabs" />
        </main>
      </div>
    );
  }

  const view = render(<Editor />);
  const workspace = screen.getByTestId('workspace');
  const tabs = screen.getByTestId('tabs');
  expect(tabs).not.toHaveAttribute('data-stuck');

  barTop = 48;
  fireEvent.scroll(workspace);
  expect(tabs).toHaveAttribute('data-stuck');

  barTop = 60;
  fireEvent.scroll(workspace);
  expect(tabs).not.toHaveAttribute('data-stuck');

  view.unmount();
  bounds.mockRestore();
});
