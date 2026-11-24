import { createInertiaApp } from '@inertiajs/react';
import '../css/app.css';

void createInertiaApp({
  strictMode: true,
  serverHead: (page) =>
    page.component.startsWith('Storefront/')
      ? ((page.props.seo as { head?: string[] } | undefined)?.head ?? [])
      : [],
});
