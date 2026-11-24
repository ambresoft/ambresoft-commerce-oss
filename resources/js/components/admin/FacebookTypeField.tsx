import { Row } from './form/fields';
import { pageLabels } from '../../lib/pageLabels';
import { useTranslation } from '../../lib/useTranslation';

/** The Open Graph object type, chosen from the same grouped list the Pages editor uses (served by the server). */
export default function FacebookTypeField({
  value,
  types,
  onChange,
  error,
  disabled = false,
}: {
  value: string;
  types: Record<string, string[]>;
  onChange: (value: string) => void;
  error?: string;
  disabled?: boolean;
}) {
  const t = useTranslation();
  return (
    <Row
      id="facebook_type"
      label={t('pages.facebook_type')}
      help={t('pages.facebook_help')}
      error={error}
    >
      <select
        id="facebook_type"
        className="field max-w-md"
        disabled={disabled}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      >
        {Object.entries(types).map(([group, items]) => (
          <optgroup key={group} label={t(pageLabels.facebook[group] ?? group)}>
            {items.map((type) => (
              <option key={type} value={type}>
                {type}
              </option>
            ))}
          </optgroup>
        ))}
      </select>
    </Row>
  );
}
