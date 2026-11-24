import { expect, it, vi } from 'vitest';

const captured = vi.hoisted(() => ({ options: undefined as unknown }));
vi.mock('@inertiajs/react', () => ({
  createInertiaApp: (options: unknown) => {
    captured.options = options;
    return Promise.resolve();
  },
}));
vi.mock('../../css/app.css', () => ({}));

it('uses the current storefront head on each visit and clears it when entering the administration', async () => {
  await import('../app');
  const options = captured.options as {
    serverHead: (page: { component: string; props: object }) => string[];
  };
  const first = ['<meta name="description" content="First">'];
  const second = ['<meta name="description" content="Second">'];
  expect(
    options.serverHead({
      component: 'Storefront/Product',
      props: { seo: { head: first } },
    }),
  ).toEqual(first);
  expect(
    options.serverHead({
      component: 'Storefront/Category',
      props: { seo: { head: second } },
    }),
  ).toEqual(second);
  expect(
    options.serverHead({
      component: 'Admin/Dashboard',
      props: { seo: { head: second } },
    }),
  ).toEqual([]);
  expect(options.serverHead({ component: 'Auth/Login', props: {} })).toEqual(
    [],
  );
});
