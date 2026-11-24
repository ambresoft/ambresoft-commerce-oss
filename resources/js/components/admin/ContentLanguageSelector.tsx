import { useTranslation } from '../../lib/useTranslation';

export default function ContentLanguageSelector({
  languages,
  value,
  onChange,
  errors = {},
  missing = [],
}: {
  languages: { code: string; name: string }[];
  value: string;
  onChange: (code: string) => void;
  errors?: Record<string, string>;
  missing?: string[];
}) {
  const t = useTranslation();
  return (
    <div
      className="content-language-control"
      role="group"
      aria-label={t('common.content_language')}
    >
      <div className="content-language-options">
        {languages.map((language) => {
          const error = Object.keys(errors).some((key) =>
            key.split('.').includes(language.code),
          );
          return (
            <button
              key={language.code}
              type="button"
              lang={language.code}
              aria-label={language.name}
              title={language.name}
              aria-pressed={value === language.code}
              onClick={() => onChange(language.code)}
              onKeyDown={(event) => {
                const index = languages.indexOf(language);
                const next =
                  event.key === 'ArrowRight'
                    ? (index + 1) % languages.length
                    : event.key === 'ArrowLeft'
                      ? (index + languages.length - 1) % languages.length
                      : event.key === 'Home'
                        ? 0
                        : event.key === 'End'
                          ? languages.length - 1
                          : null;
                if (next === null) return;
                event.preventDefault();
                onChange(languages[next].code);
                event.currentTarget.parentElement
                  ?.querySelectorAll('button')
                  .item(next)
                  ?.focus();
              }}
            >
              {language.code.toUpperCase()}
              {error && (
                <span className="text-red-600" aria-label={t('common.errors')}>
                  ●
                </span>
              )}
              {missing.includes(language.code) && (
                <span
                  className="text-amber-700"
                  aria-label={t('products.translation_missing')}
                >
                  ●
                </span>
              )}
            </button>
          );
        })}
      </div>
    </div>
  );
}
