import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import AdminTabs, { AdminTab } from '../components/admin/AdminTabs';
import ContentLanguageSelector from '../components/admin/ContentLanguageSelector';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
afterEach(() => vi.restoreAllMocks());

function Editor() {
  const [active, select] = useState('general');
  const [language, setLanguage] = useState('en');
  const [drafts, setDrafts] = useState({ en: 'Chair', el: 'Καρέκλα' });
  return (
    <>
      <AdminTabs
        label="Editor"
        language={
          <ContentLanguageSelector
            languages={[
              { code: 'en', name: 'English' },
              { code: 'el', name: 'Greek' },
            ]}
            value={language}
            onChange={setLanguage}
          />
        }
      >
        {['general', 'features', 'variations', 'sales'].map((key) => (
          <AdminTab
            key={key}
            id={'tab-' + key}
            iconKey={key}
            priority={key === 'features' || key === 'variations'}
            overflow={key === 'sales'}
            aria-selected={active === key}
            onClick={() => select(key)}
          >
            {key}
          </AdminTab>
        ))}
      </AdminTabs>
      <section role="tabpanel" aria-labelledby={'tab-' + active}>
        <input
          aria-label="Name"
          value={drafts[language as keyof typeof drafts]}
          onChange={(event) =>
            setDrafts({ ...drafts, [language]: event.target.value })
          }
        />
      </section>
    </>
  );
}

it('keeps language drafts independent using the single code selector without a visible language label', () => {
  render(<Editor />);
  expect(
    screen.getAllByRole('group', { name: 'common.content_language' }),
  ).toHaveLength(1);
  expect(screen.queryByText('common.content_language')).toBeNull();
  fireEvent.change(screen.getByLabelText('Name'), {
    target: { value: 'New chair' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Greek' }));
  expect(screen.getByLabelText('Name')).toHaveValue('Καρέκλα');
  fireEvent.keyDown(screen.getByRole('button', { name: 'Greek' }), {
    key: 'ArrowLeft',
  });
  expect(screen.getByRole('button', { name: 'English' })).toHaveFocus();
  expect(screen.getByLabelText('Name')).toHaveValue('New chair');
});

it('uses the menu for secondary tabs and retains their selected panel label after closing', () => {
  render(<Editor />);
  expect(screen.queryByRole('tab', { name: 'sales' })).toBeNull();
  const more = screen.getByRole('button', { name: 'common.more_tabs' });
  fireEvent.click(more);
  fireEvent.click(screen.getByRole('tab', { name: 'sales' }));
  expect(more).toHaveAttribute('aria-expanded', 'false');
  expect(more).toHaveFocus();
  expect(screen.getByRole('tabpanel', { name: 'sales' })).toBeVisible();
  fireEvent.click(more);
  expect(screen.getByRole('tab', { name: 'sales' })).toHaveAttribute(
    'aria-selected',
    'true',
  );
  fireEvent.keyDown(screen.getByRole('tab', { name: 'sales' }), {
    key: 'Escape',
  });
  expect(more).toHaveFocus();
  expect(more).toHaveAttribute('aria-expanded', 'false');
});

it('prioritizes Features and Variations when the bar narrows and navigates by keyboard into overflow', () => {
  vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(400);
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
    function (this: HTMLElement) {
      return {
        width: this.classList.contains('admin-tabs-controls') ? 70 : 100,
        top: 0,
        bottom: 44,
        left: 0,
        right: 100,
      } as DOMRect;
    },
  );
  render(<Editor />);
  const tabs = screen.getByRole('tablist', { name: 'Editor' });
  expect(within(tabs).getByRole('tab', { name: 'general' })).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'common.more_tabs' }));
  fireEvent.click(screen.getByRole('tab', { name: 'features' }));
  expect(within(tabs).getByRole('tab', { name: 'features' })).toBeVisible();
  expect(within(tabs).getByRole('tab', { name: 'variations' })).toBeVisible();
  fireEvent.keyDown(screen.getByRole('tab', { name: 'variations' }), {
    key: 'ArrowRight',
  });
  expect(screen.getByRole('tab', { name: 'sales' })).toHaveFocus();
  expect(screen.getByRole('tabpanel', { name: 'sales' })).toBeVisible();
});
