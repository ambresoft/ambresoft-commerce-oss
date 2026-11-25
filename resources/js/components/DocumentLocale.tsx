import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '../types/page';

export default function DocumentLocale() {
  const { i18n } = usePage<SharedProps>().props;
  useEffect(() => {
    document.documentElement.lang = i18n.locale;
    document.documentElement.dir = i18n.direction;
  }, [i18n.locale, i18n.direction]);
  return null;
}
