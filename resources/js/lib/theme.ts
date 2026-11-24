export type Theme = 'light' | 'dark';

// Per-browser preference. resources/views/app.blade.php reads the same key
// before first paint so a dark page never flashes light.
const key = 'cms-theme';

export function currentTheme(): Theme {
  return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
}

export function applyTheme(theme: Theme) {
  const root = document.documentElement;
  if (theme === 'dark') root.dataset.theme = 'dark';
  else delete root.dataset.theme;
  try {
    localStorage.setItem(key, theme);
  } catch {
    // Storage can be unavailable (private mode); the choice lasts this page.
  }
}
