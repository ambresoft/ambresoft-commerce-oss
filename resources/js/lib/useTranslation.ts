import { usePage } from '@inertiajs/react';
import type { SharedProps } from '../types/page';

export function useTranslation() {
  const { i18n } = usePage<SharedProps>().props;
  return (key: string, replacements: Record<string, string | number> = {}) => {
    let value = i18n.strings[key] ?? '';
    for (const [name, replacement] of Object.entries(replacements)) {
      value = value.replaceAll(':' + name, String(replacement));
    }
    return value;
  };
}
