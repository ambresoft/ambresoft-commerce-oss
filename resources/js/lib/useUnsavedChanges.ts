import { router } from '@inertiajs/react';
import { useEffect, useId } from 'react';
import { usePreferences } from './usePreferences';
import { useTranslation } from './useTranslation';

const dirtyForms = new Set<string>();

/** Editors register their Inertia dirty state; the admin layout owns one navigation guard. */
export function useDirtyForm(form: { isDirty: boolean; processing: boolean }) {
  const id = useId();
  useEffect(() => {
    if (form.isDirty && !form.processing) dirtyForms.add(id);
    else dirtyForms.delete(id);
    return () => {
      dirtyForms.delete(id);
    };
  }, [id, form.isDirty, form.processing]);
}

export function useUnsavedWarning() {
  const { warn_unsaved_changes: enabled } = usePreferences();
  const t = useTranslation();
  useEffect(() => {
    if (!enabled) return;
    const unload = (event: BeforeUnloadEvent) => {
      if (dirtyForms.size === 0) return;
      event.preventDefault();
      event.returnValue = '';
    };
    const off = router.on('before', (event) => {
      const visit = event.detail.visit;
      // Picker lookups retain the same editor and its dirty form state.
      const optionsReload =
        visit.method === 'get' &&
        visit.preserveState === true &&
        visit.url?.pathname === window.location.pathname &&
        visit.only?.length === 1 &&
        visit.only[0] === 'options';
      if (
        !optionsReload &&
        (visit.method === 'get' || visit.url?.pathname === '/logout') &&
        dirtyForms.size > 0 &&
        !window.confirm(t('settings.unsaved_confirm'))
      )
        event.preventDefault();
    });
    window.addEventListener('beforeunload', unload);
    return () => {
      off();
      window.removeEventListener('beforeunload', unload);
    };
  }, [enabled, t]);
}
