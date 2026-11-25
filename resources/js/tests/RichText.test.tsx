import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';
import RichText from '../components/admin/RichText';
import { sanitizeRichText } from '../lib/sanitizeRichText';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
function Harness({ initial = '' }: { initial?: string }) {
  const [value, setValue] = useState(initial);
  return (
    <>
      <RichText
        id="body"
        label="Description"
        value={value}
        onChange={setValue}
      />
      <output data-testid="saved-html">{value}</output>
    </>
  );
}

it('edits HTML, keeps safe formatting and removes executable markup before visual rendering', async () => {
  render(<Harness initial="<p>Original</p>" />);
  fireEvent.click(screen.getByRole('button', { name: 'editor.html' }));
  const raw =
    '<h2>Details</h2><p onclick="alert(1)"><strong>Safe</strong><script>alert(2)</script><img src=x onerror=alert(3)><a href="javascript:alert(4)">Bad</a></p>';
  fireEvent.change(screen.getByRole('textbox', { name: 'Description' }), {
    target: { value: raw },
  });
  expect(screen.getByRole('textbox', { name: 'Description' })).toHaveValue(raw);
  expect(screen.getByTestId('saved-html')).toHaveTextContent(
    '<strong>Safe</strong>',
  );
  expect(screen.getByTestId('saved-html').textContent).not.toMatch(
    /<script|<img|onclick|onerror|javascript:/i,
  );
  fireEvent.click(screen.getByRole('button', { name: 'editor.visual' }));
  await waitFor(() =>
    expect(
      screen.getByRole('textbox', { name: 'Description' }),
    ).toHaveTextContent('Details'),
  );
  expect(screen.getByRole('textbox').querySelector('strong')).toHaveTextContent(
    'Safe',
  );
  expect(
    screen.getByRole('textbox').querySelector('script, img, [onclick]'),
  ).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'editor.html' }));
  expect((screen.getByRole('textbox') as HTMLTextAreaElement).value).toContain(
    '<strong>Safe</strong>',
  );
});

it('sanitizes initial content, handles external values and respects read-only mode', async () => {
  const changed = vi.fn();
  const { rerender } = render(
    <RichText
      id="body"
      label="Body"
      value={
        '<p onmouseover="bad()">First</p><iframe src="https://example.com"></iframe>'
      }
      onChange={changed}
    />,
  );
  expect(
    screen.getByRole('textbox').querySelector('iframe, [onmouseover]'),
  ).toBeNull();
  expect(changed).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'editor.html' }));
  fireEvent.click(screen.getByRole('button', { name: 'editor.visual' }));
  expect(changed).not.toHaveBeenCalled();
  expect(screen.getByRole('button', { name: 'editor.undo' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'editor.html' }));
  rerender(
    <RichText
      id="body"
      label="Body"
      value="<p>Second</p>"
      onChange={changed}
    />,
  );
  expect(screen.getByRole('textbox')).toHaveValue('<p>Second</p>');
  fireEvent.click(screen.getByRole('button', { name: 'editor.visual' }));
  await waitFor(() =>
    expect(screen.getByRole('textbox')).toHaveTextContent('Second'),
  );
  rerender(
    <RichText
      id="body"
      label="Body"
      value="<p>Second</p>"
      onChange={changed}
      disabled
    />,
  );
  await waitFor(() =>
    expect(screen.getByRole('textbox')).toHaveAttribute(
      'contenteditable',
      'false',
    ),
  );
  expect(screen.getByRole('button', { name: 'editor.bold' })).toBeDisabled();
});

it('removes styles, embeds, clobbering attributes and unsafe protocols while keeping permitted markup', () => {
  const html = sanitizeRichText(
    '<form id="body"><input name="attributes"></form><svg><a xlink:href="javascript:alert(1)">x</a></svg><p style="background:url(https://example.com)" id="app"><u>Safe</u><a href="https://example.com" title="Example">Link</a><a href="data:text/html,test">Data</a><a href="//example.com">Relative</a></p>',
  );
  expect(html).toContain('<u>Safe</u>');
  expect(html).toContain('href="https://example.com"');
  expect(html).not.toMatch(
    /<form|<input|<svg|xlink|javascript:|data:|style=|id=|name=|href="\/\//i,
  );
});
