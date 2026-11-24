import type { ImpactPreview } from '../../types/features';
import { useTranslation } from '../../lib/useTranslation';
export default function FeatureImpact({
  preview,
  confirmed,
  onConfirm,
}: {
  preview: ImpactPreview;
  confirmed: boolean;
  onConfirm: (value: boolean) => void;
}) {
  const t = useTranslation();
  if (!preview) return null;
  return (
    <section
      role="alert"
      className="my-4 space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4"
    >
      <h2 className="font-semibold">{t('features.impact')}</h2>
      {Object.entries(preview.impact).map(([key, value]) => (
        <p key={key}>
          {t('features.' + key)}: {value}
        </p>
      ))}
      <p>{t('features.impact_help')}</p>
      <label className="flex gap-2">
        <input
          type="checkbox"
          checked={confirmed}
          onChange={(e) => onConfirm(e.target.checked)}
        />
        {t('features.confirm_impact')}
      </label>
    </section>
  );
}
