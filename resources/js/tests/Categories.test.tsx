import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import CategoryTree from '../components/admin/CategoryTree';
import CategoryActions from '../components/admin/CategoryActions';
import CategoryDeleteAction from '../components/admin/CategoryDeleteAction';
import CategoryForm from '../pages/Admin/Categories/Form';
import CategoriesIndex from '../pages/Admin/Categories/Index';
import type {
  CategoryBranch,
  CategoryEditor,
  CategorySummary,
} from '../types/categories';
import type { SharedProps } from '../types/page';
import { english, greek } from './translations';
import { choose } from './select';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    usePage: () => ({ url: '/admin/categories/1/edit', props: shared.props }),
    Head: () => null,
    Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
      <a {...props}>{children}</a>
    ),
  };
});

const categoryStrings = {
  'categories.title': 'Categories',
  'categories.intro': 'Organize your catalog',
  'categories.create': 'Add category',
  'categories.edit': 'Edit category',
  'categories.delete': 'Delete category',
  'categories.bulk_select': 'Select :name',
  'categories.bulk_selected': ':count selected',
  'categories.bulk_delete': 'Delete selected',
  'categories.bulk_clear': 'Clear selection',
  'categories.bulk_confirm': 'Delete :count selected subcategories?',
  'categories.bulk_selection_hint':
    'Only visible leaf subcategories can be selected.',
  'categories.add_child': 'Add subcategory',
  'categories.tab_general': 'General',
  'categories.tab_content': 'Content',
  'categories.tab_seo': 'SEO',
  'categories.tab_age': 'Age restrictions',
  'categories.tab_image': 'Image',
  'categories.confirm_delete': 'Delete :name?',
  'categories.parent': 'Parent category',
  'categories.root': 'Root category',
  'categories.parent_search': 'Find a parent',
  'categories.parent_search_hint': 'Search parents',
  'categories.position': 'Position',
  'categories.position_hint': 'Numeric sibling position',
  'categories.status': 'Status',
  'categories.active': 'Active',
  'categories.disabled': 'Disabled',
  'categories.general': 'General',
  'categories.translations': 'Translations',
  'categories.name': 'Name',
  'categories.description': 'Description',
  'categories.description_hint': 'Plain text',
  'categories.seo_title': 'Page title',
  'categories.seo_description': 'Meta description',
  'categories.slug': 'SEO name (slug)',
  'categories.slug_hint': 'Unique for this language',
  'categories.meta_keywords': 'Meta keywords',
  'categories.meta_keywords_hint': 'Keywords for this language',
  'categories.age_restrictions': 'Age restrictions',
  'categories.age_verification': 'Age verification',
  'categories.age_verification_hint': 'Inherited restrictions still apply',
  'categories.age_limit': 'Age limit (years)',
  'categories.age_limit_hint': 'Minimum age',
  'categories.age_warning_message': 'Age warning message',
  'categories.age_warning_hint': 'Localized warning',
  'categories.parent_age_verification': 'Parent age verification',
  'categories.parent_age_limit': 'Parent age limit (years)',
  'categories.age_inherited_hint': 'Inherited from ancestors',
  'categories.age_configuration_hint': 'Configuration only',
  'categories.age_enabled': 'Enabled',
  'categories.age_disabled': 'Disabled',
  'categories.age_none': 'No inherited age limit',
  'categories.age_loading': 'Loading inherited age settings',
  'categories.age_load_failed': 'Unable to load inherited age settings',
  'categories.image': 'Category image',
  'categories.image_hint': 'Private image',
  'categories.image_alt': 'Image alternative text',
  'categories.current_image': 'Current category image',
  'categories.remove_image': 'Remove current image',
  'categories.children': ':count subcategories',
  'categories.children_one': ':count subcategory',
  'categories.tree_hint': 'Subcategories appear under their parent category.',
  'categories.expand': 'Expand :name',
  'categories.collapse': 'Collapse :name',
  'categories.tree': 'Category hierarchy',
  'categories.fallback': 'Showing :locale fallback',
  'categories.translation_hint': 'Independent translations',
  'categories.default_required': 'Default language required',
  'categories.changed': 'Unsaved changes',
  'categories.translated': 'Translated',
  'categories.missing': 'Missing',
  'categories.errors.children':
    'Move or delete child categories before deleting their parent.',
  'categories.load_failed': 'Unable to load this branch',
  'categories.system': 'System category',
  'categories.system_hint': 'Protected recovery category',
  'categories.search_hint': 'Localized search',
};
const node = (
  id: string,
  name: string,
  childrenCount = 0,
): CategorySummary => ({
  id,
  name,
  parentId: null,
  position: 1,
  status: 'active',
  system: false,
  version: 2,
  childrenCount,
  resolvedLocale: 'en',
  translationState: 'translated',
  editUrl: '/admin/categories/' + id + '/edit',
  childUrl: '/admin/categories/create?parent=' + id,
});
const branch = (
  data: CategorySummary[],
  parentId: string | null = null,
): CategoryBranch => ({
  parentId,
  data,
  page: 1,
  lastPage: 1,
  total: data.length,
});
beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'Commerce Workspace' },
    auth: {
      user: {
        id: '1',
        name: 'Alex Staff',
        email: 'alex@example.test',
        emailVerified: true,
      },
      canAccessAdmin: true,
    },
    flash: { status: null },
    capabilities: {
      'categories.view': true,
      'categories.create': true,
      'categories.update': true,
      'categories.delete': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...categoryStrings },
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Ελληνικά' },
      ],
    },
    links: Object.fromEntries(
      [
        'login',
        'login.store',
        'register',
        'register.store',
        'logout',
        'password.request',
        'password.email',
        'password.update',
        'verification.send',
        'admin.dashboard',
        'access.pending',
      ].map((key) => [key, '/admin']),
    ) as SharedProps['links'],
  };
});

it('edits sitemap exclusion in Additional and saves it with the category', () => {
  Object.assign(shared.props.i18n.strings, {
    'categories.tab_additional': 'Additional',
    'categories.exclude_from_sitemap': 'Exclude from XML sitemap',
  });
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(
    <CategoryForm
      category={null}
      initialParentId={null}
      defaultLocale="en"
      parentOptions={[]}
      parentAgeRestriction={{
        parentId: null,
        verification: false,
        ageLimit: null,
        sourceCategoryId: null,
      }}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  openTab('Additional');
  const checkbox = screen.getByRole('checkbox', {
    name: 'Exclude from XML sitemap',
  });
  expect(checkbox).not.toBeChecked();
  fireEvent.click(checkbox);
  expect(post).not.toHaveBeenCalled();
  openTab('General');
  openTab('Additional');
  expect(
    screen.getByRole('checkbox', { name: 'Exclude from XML sitemap' }),
  ).toBeChecked();
  fireEvent.submit(
    (screen.getByRole('button', { name: 'Save changes' }) as HTMLButtonElement)
      .form!,
  );
  expect(post.mock.calls[0][1]).toMatchObject({ exclude_from_sitemap: true });
  post.mockRestore();
});

it('loads only an expanded branch, preserves the URL, and collapses/reopens cached children', () => {
  const get = vi.spyOn(router, 'get').mockImplementation(() => {});
  const { rerender } = render(
    <CategoryTree initialBranch={branch([node('2', 'Technology', 1)])} />,
  );
  expect(screen.queryByText('Gaming')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Expand Technology' }));
  expect(get).toHaveBeenCalledWith(
    '/admin/categories',
    { parent: '2', page: 1 },
    expect.objectContaining({ only: ['branch'], preserveUrl: true }),
  );
  expect(
    screen.getByRole('button', { name: 'Expand Technology' }),
  ).toBeDisabled();
  act(() => {
    get.mock.calls[0][2]?.onSuccess?.({
      props: { branch: branch([node('3', 'Gaming')], '2') },
    } as never);
    get.mock.calls[0][2]?.onFinish?.({} as never);
  });
  expect(screen.getByText('Gaming')).toBeVisible();
  rerender(<CategoryTree initialBranch={branch([node('3', 'Gaming')], '2')} />);
  expect(screen.getByText('Technology')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Collapse Technology' }));
  expect(screen.queryByText('Gaming')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Expand Technology' }));
  expect(screen.getByText('Gaming')).toBeVisible();
  expect(get).toHaveBeenCalledOnce();
});

it('selects visible leaf subcategories and confirms one versioned bulk deletion', () => {
  const get = vi.spyOn(router, 'get').mockImplementation(() => {});
  const remove = vi.spyOn(router, 'delete').mockImplementation(() => {});
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
  render(<CategoryTree initialBranch={branch([node('2', 'Parent', 2)])} />);
  expect(
    screen.queryByRole('checkbox', { name: 'Select Parent' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Expand Parent' }));
  act(() => {
    get.mock.calls[0][2]?.onSuccess?.({
      props: {
        branch: branch(
          [
            { ...node('3', 'First'), parentId: '2' },
            { ...node('4', 'Second'), parentId: '2' },
          ],
          '2',
        ),
      },
    } as never);
    get.mock.calls[0][2]?.onFinish?.({} as never);
  });
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select First' }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select Second' }));
  expect(screen.getByText('2 selected')).toBeVisible();
  const form = (
    screen.getByRole('button', { name: 'Delete selected' }) as HTMLButtonElement
  ).form!;
  fireEvent.submit(form);
  expect(remove).not.toHaveBeenCalled();
  confirm.mockReturnValue(true);
  fireEvent.submit(form);
  expect(remove).toHaveBeenCalledWith(
    '/admin/categories/bulk',
    expect.objectContaining({
      data: {
        targets: [
          { id: '3', version: 2 },
          { id: '4', version: 2 },
        ],
      },
      preserveState: 'errors',
    }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Collapse Parent' }));
  expect(screen.queryByText('2 selected')).not.toBeInTheDocument();
});

const openTab = (name: string) =>
  fireEvent.click(screen.getByRole('tab', { name: new RegExp('^' + name) }));

it('keeps locale drafts when switching tabs and submits only the edited language', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  const category: CategoryEditor = {
    id: '2',
    displayName: 'English original',
    childrenCount: 0,
    parentId: null,
    position: 4,
    status: 'active',
    version: 7,
    imageUrl: null,
    blockImageUrl: null,
    excludeFromDiscovery: false,
    customerGroups: [],
    ageVerification: false,
    ageLimit: null,
    translations: {
      en: {
        name: 'English original',
        description: '',
        seo_title: '',
        seo_description: '',
        slug: 'english',
        image_alt: '',
        meta_keywords: 'English keywords',
        age_warning_message: 'English warning',
      },
      el: {
        name: 'Ελληνικό αρχικό',
        description: '',
        seo_title: '',
        seo_description: '',
        slug: 'elliniko',
        image_alt: '',
        meta_keywords: 'Ελληνικές λέξεις',
        age_warning_message: 'Ελληνική προειδοποίηση',
      },
    },
  };
  render(
    <CategoryForm
      category={category}
      initialParentId={null}
      defaultLocale="en"
      parentOptions={[]}
      parentAgeRestriction={{
        parentId: null,
        verification: false,
        ageLimit: null,
        sourceCategoryId: null,
      }}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
    'Edit category: English original',
  );
  const remove = vi.spyOn(router, 'delete').mockImplementation(() => {});
  const confirm = vi.spyOn(window, 'confirm');
  fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
  fireEvent.click(screen.getByRole('button', { name: 'Delete category' }));
  fireEvent.click(
    within(screen.getByRole('alertdialog')).getByRole('button', {
      name: /Cancel/,
    }),
  );
  expect(remove).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
  fireEvent.click(screen.getByRole('button', { name: 'Delete category' }));
  fireEvent.submit(screen.getByRole('alertdialog'));
  expect(confirm).not.toHaveBeenCalled();
  expect(remove).toHaveBeenCalledWith(
    '/admin/categories/2',
    expect.objectContaining({ data: { version: 7 }, preserveState: 'errors' }),
  );
  expect(screen.queryByText('Missing')).not.toBeInTheDocument();
  openTab('Content');
  fireEvent.click(screen.getByRole('button', { name: /Ελληνικά/ }));
  fireEvent.change(screen.getByLabelText('Name'), {
    target: { value: 'Νέο ελληνικό' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'English' }));
  expect(screen.getByLabelText('Name')).toHaveValue('English original');
  fireEvent.keyDown(screen.getByRole('button', { name: 'English' }), {
    key: 'ArrowRight',
  });
  expect(screen.getByLabelText('Name')).toHaveValue('Νέο ελληνικό');
  fireEvent.submit(
    (screen.getByRole('button', { name: 'Save changes' }) as HTMLButtonElement)
      .form!,
  );
  expect(post.mock.calls[0][0]).toBe('/admin/categories/2');
  expect(post.mock.calls[0][1]).toMatchObject({
    _method: 'put',
    version: 7,
    position: 4,
    translations: { el: { name: 'Νέο ελληνικό' } },
  });
  expect(post.mock.calls[0][1]).toHaveProperty('translations');
  expect(
    (post.mock.calls[0][1] as { translations: object }).translations,
  ).not.toHaveProperty('en');
});

it('does not offer edit, child or delete actions for system categories or unauthorized staff', () => {
  const { rerender } = render(
    <CategoryActions
      category={{ ...node('1', 'Uncategorized'), system: true }}
    />,
  );
  expect(screen.queryByRole('link')).not.toBeInTheDocument();
  expect(screen.queryByRole('button')).not.toBeInTheDocument();
  shared.props.capabilities = { 'categories.view': true };
  rerender(<CategoryActions category={node('2', 'Normal')} />);
  expect(screen.queryByRole('link')).not.toBeInTheDocument();
  expect(screen.queryByRole('button')).not.toBeInTheDocument();
});

it('keeps localized metadata independent and refreshes read-only inherited age settings for a new parent', () => {
  const reload = vi.spyOn(router, 'reload').mockImplementation(() => {});
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  const props = {
    category: null,
    initialParentId: '1',
    defaultLocale: 'en',
    parentOptions: [
      { id: '1', name: 'Restricted parent' },
      { id: '2', name: 'Open parent' },
    ],
    parentAgeRestriction: {
      parentId: '1',
      verification: true,
      ageLimit: 21,
      sourceCategoryId: '1',
    },
    navigation: [],
    breadcrumbs: [],
  };
  const { rerender } = render(<CategoryForm {...props} />);
  openTab('Age restrictions');
  expect(screen.getByText('21')).toBeVisible();
  expect(
    screen.queryByRole('textbox', { name: 'Parent age limit (years)' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('checkbox', { name: 'Age verification' }));
  fireEvent.change(screen.getByLabelText('Age limit (years)'), {
    target: { value: '18' },
  });
  fireEvent.change(screen.getByLabelText('Age warning message'), {
    target: { value: 'English warning' },
  });
  openTab('SEO');
  fireEvent.change(screen.getByLabelText('Meta keywords'), {
    target: { value: 'English keywords' },
  });
  fireEvent.click(screen.getByRole('button', { name: /Ελληνικά/ }));
  expect(screen.getByLabelText('Meta keywords')).toHaveValue('');
  fireEvent.change(screen.getByLabelText('Meta keywords'), {
    target: { value: 'Ελληνικές λέξεις' },
  });
  openTab('Age restrictions');
  fireEvent.change(screen.getByLabelText('Age warning message'), {
    target: { value: 'Ελληνικό μήνυμα' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'English' }));
  expect(screen.getByLabelText('Age warning message')).toHaveValue(
    'English warning',
  );
  openTab('SEO');
  expect(screen.getByLabelText('Page title')).toBeVisible();
  expect(screen.getByLabelText('Meta description')).toBeVisible();
  expect(screen.getByLabelText('SEO name (slug)')).toBeVisible();
  openTab('Content');
  fireEvent.change(screen.getByLabelText('Name'), {
    target: { value: 'English name' },
  });
  openTab('General');
  choose(screen.getByLabelText('Parent category'), /#2$/);
  expect(reload).toHaveBeenCalledWith(
    expect.objectContaining({
      data: { selected_parent: '2' },
      only: ['parentAgeRestriction'],
      preserveUrl: true,
    }),
  );
  openTab('Age restrictions');
  expect(screen.getByRole('status')).toHaveTextContent(
    'Loading inherited age settings',
  );
  expect(screen.queryByText('21')).not.toBeInTheDocument();
  rerender(
    <CategoryForm
      {...props}
      parentAgeRestriction={{
        parentId: '2',
        verification: false,
        ageLimit: null,
        sourceCategoryId: null,
      }}
    />,
  );
  expect(screen.getByText('No inherited age limit')).toBeVisible();
  expect(screen.getByLabelText('Age warning message')).toHaveValue(
    'English warning',
  );
  fireEvent.submit(
    (screen.getByRole('button', { name: 'Save changes' }) as HTMLButtonElement)
      .form!,
  );
  expect(post.mock.calls[0][1]).toMatchObject({
    parent_id: '2',
    age_verification: true,
    age_limit: '18',
    translations: {
      en: {
        meta_keywords: 'English keywords',
        age_warning_message: 'English warning',
      },
      el: {
        meta_keywords: 'Ελληνικές λέξεις',
        age_warning_message: 'Ελληνικό μήνυμα',
      },
    },
  });
});

it('requires deletion confirmation and sends the current version without optimistic removal', () => {
  const remove = vi.spyOn(router, 'delete').mockImplementation(() => {});
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
  render(<CategoryActions category={node('2', 'Chairs')} />);
  const form = (
    screen.getByRole('button', { name: 'Delete: Chairs' }) as HTMLButtonElement
  ).form!;
  fireEvent.submit(form);
  expect(remove).not.toHaveBeenCalled();
  confirm.mockReturnValue(true);
  fireEvent.submit(form);
  expect(remove).toHaveBeenCalledWith(
    '/admin/categories/2',
    expect.objectContaining({ data: { version: 2 }, preserveState: 'errors' }),
  );
});

it('explains why a parent category cannot be deleted from the edit page', () => {
  render(
    <CategoryDeleteAction
      id="2"
      name="Parent"
      version={7}
      childrenCount={1}
      labelKey="categories.delete"
    />,
  );
  expect(
    screen.getByRole('button', { name: 'Delete category' }),
  ).toBeDisabled();
  expect(
    screen.getByText(
      'Move or delete child categories before deleting their parent.',
    ),
  ).toBeVisible();
});

it('renders the new category screen in Greek using the server dictionary', () => {
  shared.props.i18n = {
    ...shared.props.i18n,
    locale: 'el',
    strings: {
      ...greek,
      ...categoryStrings,
      'categories.title': 'Κατηγορίες',
      'categories.intro': 'Ιεραρχία καταλόγου',
      'categories.create': 'Προσθήκη κατηγορίας',
      'categories.system': 'Κατηγορία συστήματος',
    },
  };
  render(
    <CategoriesIndex
      branch={branch([
        { ...node('1', 'Χωρίς κατηγορία'), system: true, status: 'disabled' },
      ])}
      results={null}
      filtered={false}
      filters={{}}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
    'Κατηγορίες',
  );
  expect(
    screen.getByRole('link', { name: 'Προσθήκη κατηγορίας' }),
  ).toHaveAttribute('href', '/admin/categories/create');
  expect(screen.getByText('Χωρίς κατηγορία')).toBeVisible();
});

it('shows the tree as rows with status, counts, system details and a hint', () => {
  render(
    <CategoryTree
      initialBranch={branch([
        {
          ...node('1', 'Uncategorized'),
          system: true,
          status: 'disabled',
          position: 0,
          translationState: 'system',
        },
        node('2', 'Technology', 1),
        node('3', 'Gifts', 2),
      ])}
    />,
  );
  const tree = screen.getByRole('region', { name: 'Category hierarchy' });
  expect(within(tree).getByText('Position 1 · 1 subcategory')).toBeVisible();
  expect(within(tree).getByText('Position 1 · 2 subcategories')).toBeVisible();
  const system = within(tree).getByText('Uncategorized').closest('li')!;
  expect(within(system).getByText('System category')).toBeVisible();
  expect(within(system).getByText('Protected recovery category')).toBeVisible();
  expect(within(system).queryByRole('link')).not.toBeInTheDocument();
  expect(
    within(tree).getByRole('link', { name: 'Add subcategory: Technology' }),
  ).toHaveAttribute('href', '/admin/categories/create?parent=2');
  expect(
    within(tree).getByText('Subcategories appear under their parent category.'),
  ).toBeVisible();
});

it.each([1, 2])(
  'marks category requirements for %i active languages and conditional age limits',
  (languageCount) => {
    shared.props.i18n.locales = shared.props.i18n.locales.slice(
      0,
      languageCount,
    );
    render(
      <CategoryForm
        category={null}
        initialParentId={null}
        defaultLocale="en"
        parentOptions={[]}
        parentAgeRestriction={{
          parentId: null,
          verification: false,
          ageLimit: null,
          sourceCategoryId: null,
        }}
        navigation={[]}
        breadcrumbs={[]}
      />,
    );
    expect(screen.getByLabelText('Position')).toHaveAttribute(
      'aria-required',
      'true',
    );
    expect(document.querySelector('label[for="position"]')).toHaveTextContent(
      '*',
    );
    expect(screen.getByLabelText('Status')).toHaveAttribute(
      'aria-required',
      'true',
    );
    expect(screen.getByLabelText('Parent category')).not.toHaveAttribute(
      'aria-required',
    );
    openTab('Content');
    expect(screen.getByLabelText('Name')).toHaveAttribute(
      'aria-required',
      'true',
    );
    expect(
      document.querySelector('label[for="description-en"]'),
    ).not.toHaveTextContent('*');
    openTab('SEO');
    for (const label of ['Page title', 'Meta description', 'Meta keywords']) {
      const input = screen.getByLabelText(label);
      if (languageCount > 1)
        expect(input).toHaveAttribute('aria-required', 'true');
      else expect(input).not.toHaveAttribute('aria-required');
      expect(input).not.toHaveAttribute('required');
    }
    expect(screen.getByLabelText('SEO name (slug)')).toHaveAttribute(
      'aria-required',
      'true',
    );
    openTab('Image');
    if (languageCount > 1)
      expect(screen.getByLabelText('Image alternative text')).toHaveAttribute(
        'aria-required',
        'true',
      );
    else
      expect(
        screen.getByLabelText('Image alternative text'),
      ).not.toHaveAttribute('aria-required');
    openTab('Age restrictions');
    expect(screen.getByLabelText('Age warning message')).not.toHaveAttribute(
      'aria-required',
    );
    expect(screen.getByLabelText('Age limit (years)')).not.toHaveAttribute(
      'aria-required',
    );
    fireEvent.click(screen.getByRole('checkbox', { name: 'Age verification' }));
    expect(screen.getByLabelText('Age limit (years)')).toHaveAttribute(
      'aria-required',
      'true',
    );
    expect(document.querySelector('label[for="age_limit"]')).toHaveTextContent(
      '*',
    );
    expect(screen.getByLabelText('Age warning message')).not.toHaveAttribute(
      'aria-required',
    );
  },
);

it('finds parent categories as you type, without a separate search button', () => {
  vi.useFakeTimers();
  const reload = vi.spyOn(router, 'reload').mockImplementation(() => {});
  render(
    <CategoryForm
      category={null}
      initialParentId=""
      defaultLocale="en"
      parentOptions={[{ id: '1', name: 'Tech › Phones' }]}
      parentAgeRestriction={{
        parentId: null,
        verification: false,
        ageLimit: null,
        sourceCategoryId: null,
      }}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  openTab('General');
  expect(
    screen.queryByRole('button', { name: 'Search' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByLabelText('Parent category'));
  fireEvent.change(screen.getByRole('combobox', { name: 'Find a parent' }), {
    target: { value: 'pho' },
  });
  expect(reload).not.toHaveBeenCalled();
  vi.advanceTimersByTime(300);
  expect(reload).toHaveBeenCalledWith(
    expect.objectContaining({
      data: { parent_search: 'pho', selected_parent: '' },
      only: ['parentOptions'],
      preserveUrl: true,
    }),
  );
  vi.useRealTimers();
  reload.mockRestore();
});
