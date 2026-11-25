import FormField from '../auth/FormField';
import Select from './Select';
import { useTranslation } from '../../lib/useTranslation';

export type AiValues = {
  version: number;
  enabled: boolean;
  api_key: string;
  clear_key: boolean;
  model: string;
  max_tokens: number;
  hourly_limit: number;
};
export type AiSettings = Omit<AiValues, 'api_key' | 'clear_key'> & {
  has_key: boolean;
};
export default function AiSettingsFields({
  value,
  onChange,
  hasKey,
  models,
  errors,
}: {
  value: AiValues;
  onChange: (value: AiValues) => void;
  hasKey: boolean;
  models: { value: string; label: string }[];
  errors: Record<string, string>;
}) {
  const t = useTranslation();
  const setData = <K extends keyof AiValues>(key: K, next: AiValues[K]) =>
    onChange({ ...value, [key]: next });
  return (
    <section className="space-y-5">
      <label className="flex items-start gap-3 text-sm">
        <input
          type="checkbox"
          className="mt-0.5 size-4"
          checked={value.enabled}
          onChange={(e) => setData('enabled', e.target.checked)}
        />
        <span>
          <span className="font-medium">{t('ai.enabled')}</span>
          <span className="mt-1 block text-xs leading-5 text-slate-500">
            {t('ai.enabled_help')}
          </span>
        </span>
      </label>
      <FormField
        id="ai-api-key"
        label={t('ai.key')}
        type="password"
        autoComplete="new-password"
        maxLength={500}
        value={value.api_key}
        error={errors.api_key}
        onChange={(e) => setData('api_key', e.target.value)}
        hint={t(hasKey ? 'ai.key_saved' : 'ai.key_missing')}
      />
      {hasKey && (
        <label className="flex items-center gap-3 text-sm">
          <input
            type="checkbox"
            className="size-4"
            checked={value.clear_key}
            onChange={(e) => setData('clear_key', e.target.checked)}
          />
          {t('ai.clear_key')}
        </label>
      )}
      <div>
        <label htmlFor="ai-model" className="mb-2 block text-sm font-medium">
          {t('ai.model')}
        </label>
        <Select
          id="ai-model"
          value={value.model}
          options={models}
          onChange={(value) => setData('model', value)}
        />
      </div>
      <FormField
        id="ai-max-tokens"
        label={t('ai.max_tokens')}
        type="number"
        min={256}
        max={16000}
        value={value.max_tokens}
        error={errors.max_tokens}
        onChange={(e) => setData('max_tokens', Number(e.target.value))}
        hint={t('ai.cost_help')}
      />
      <FormField
        id="ai-hourly-limit"
        label={t('ai.hourly_limit')}
        type="number"
        min={1}
        max={1000}
        value={value.hourly_limit}
        error={errors.hourly_limit}
        onChange={(e) => setData('hourly_limit', Number(e.target.value))}
      />
    </section>
  );
}
