import js from '@eslint/js';
import hooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  { ignores: ['node_modules/**', 'vendor/**', 'public/build/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: [
      'resources/js/**/*.{ts,tsx}',
      'modules/ambresoft/sitemap/resources/js/**/*.{ts,tsx}',
    ],
    languageOptions: { globals: globals.browser },
    plugins: { 'react-hooks': hooks },
    rules: hooks.configs.recommended.rules,
  },
);
