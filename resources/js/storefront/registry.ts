import { createElement, type ComponentType } from 'react';
import type { PublicBlock, StorefrontPage } from './types';
const themes = new Map<string, ComponentType<{ page: StorefrontPage }>>();
const templates = new Map<string, ComponentType<{ block: PublicBlock }>>();
export function registerTheme(
  id: string,
  component: ComponentType<{ page: StorefrontPage }>,
) {
  if (!import.meta.hot && themes.has(id))
    throw new Error(`Duplicate storefront theme: ${id}`);
  themes.set(id, component);
}
export function registerTemplate(
  theme: string,
  id: string,
  component: ComponentType<{ block: PublicBlock }>,
) {
  const key = `${theme}:${id}`;
  if (!import.meta.hot && templates.has(key))
    throw new Error(`Duplicate storefront template: ${key}`);
  templates.set(key, component);
}
export const themeFor = (id: string) => themes.get(id) ?? themes.get('default');
export const templateFor = (theme: string, id: string) =>
  templates.get(`${theme}:${id}`);

export function renderTheme(page: StorefrontPage) {
  const Theme = themeFor(page.theme);
  return Theme ? createElement(Theme, { page }) : null;
}
