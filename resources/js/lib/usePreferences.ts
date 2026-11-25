import { usePage } from '@inertiajs/react';
import type { SharedProps } from '../types/page';

export const defaultPreferences = {
  date_format: 'd/m/Y',
  time_format: 'H:i',
  timezone: 'UTC',
  calendar_date_format: 'd/m/Y',
  calendar_week_start: 1,
  warn_unsaved_changes: true,
  show_menu_descriptions: true,
  notification_duration: 5,
};

export function usePreferences() {
  return { ...defaultPreferences, ...usePage<SharedProps>().props.preferences };
}
