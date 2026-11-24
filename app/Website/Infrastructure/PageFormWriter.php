<?php

namespace App\Website\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Website\Domain\Service\PageRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Validates and stores a page's form definition (fields, choice variants, recipients, subject, submit text,
 * placement). Called inside the page save transaction; it never sends e-mail or stores submissions.
 */
final class PageFormWriter
{
    private const MAX_FIELDS = 50;

    private const MAX_VARIANTS = 100;

    public function __construct(private PageContentSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    public function validated(array $input, string $defaultContent, array &$errors): array
    {
        $locales = app(LocaleCatalog::class);
        $codes = $locales->active()->pluck('code')->all();
        $default = $locales->defaultCode();
        $text = fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        $bool = fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $plain = fn (string $value, int $max): bool => mb_strlen($value) <= $max && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;

        $form = [
            'enabled' => $bool($input['enabled'] ?? false),
            'placement' => $text($input['placement'] ?? 'after'),
            'subject_mode' => $text($input['subject_mode'] ?? 'form_name'),
            'subject_field' => $text($input['subject_field'] ?? ''),
        ];
        if (! in_array($form['placement'], ['before', 'after', 'marker'], true)) {
            $errors['form.placement'] = __('pages.choice_invalid');
        } elseif ($form['enabled'] && $form['placement'] === 'marker' && ! str_contains($defaultContent, PageRules::FORM_MARKER)) {
            $errors['form.placement'] = __('pages.form_marker_missing', ['marker' => PageRules::FORM_MARKER]);
        }
        if (! in_array($form['subject_mode'], ['form_name', 'field', 'custom'], true)) {
            $errors['form.subject_mode'] = __('pages.choice_invalid');
        }

        $recipients = array_values(array_unique(array_map('strtolower', preg_split('/[\s,;]+/', $text($input['recipients'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [])));
        foreach ($recipients as $address) {
            if (Validator::make(['e' => $address], ['e' => ['email:rfc', 'max:254']])->fails()) {
                $errors['form.recipients'] = __('pages.form_recipient_invalid', ['address' => mb_substr($address, 0, 80)]);
            }
        }
        if (count($recipients) > 10) {
            $errors['form.recipients'] = __('pages.form_recipients_many');
        }
        if ($form['enabled'] && $recipients === []) {
            $errors['form.recipients'] = __('pages.form_recipients_required');
        }
        $form['recipients'] = implode(', ', $recipients);

        $form['translations'] = [];
        foreach ((array) ($input['translations'] ?? []) as $code => $fields) {
            if (! in_array($code, $codes, true)) {
                continue;
            }
            $subject = $text(((array) $fields)['custom_subject'] ?? '');
            if (! $plain($subject, 200)) {
                $errors['form.translations.'.$code.'.custom_subject'] = __('pages.text_invalid', ['max' => 200]);
            }
            $raw = is_scalar(((array) $fields)['submit_text'] ?? null) ? (string) ((array) $fields)['submit_text'] : '';
            $form['translations'][$code] = ['custom_subject' => $subject, 'submit_text' => strlen($raw) > 100000 ? '' : $this->sanitizer->sanitize($raw)];
        }
        if ($form['enabled'] && $form['subject_mode'] === 'custom' && ($form['translations'][$default]['custom_subject'] ?? '') === '') {
            $errors['form.translations.'.$default.'.custom_subject'] = __('pages.form_subject_required');
        }

        $fields = array_values((array) ($input['fields'] ?? []));
        if (count($fields) > self::MAX_FIELDS) {
            $errors['form.fields'] = __('pages.form_fields_many', ['max' => self::MAX_FIELDS]);
            $fields = [];
        }
        $form['fields'] = [];
        $keys = [];
        foreach ($fields as $i => $raw) {
            $raw = (array) $raw;
            $prefix = 'form.fields.'.$i.'.';
            $field = [
                'key' => $text($raw['key'] ?? ''), 'id' => ctype_digit($text($raw['id'] ?? '')) ? (int) $raw['id'] : null,
                'type' => $text($raw['type'] ?? ''), 'status' => $text($raw['status'] ?? 'active'),
                'required' => $bool($raw['required'] ?? false), 'names' => [], 'variants' => [],
            ];
            $position = filter_var($raw['position'] ?? 0, FILTER_VALIDATE_INT);
            $field['position'] = $position === false ? 0 : max(-99999, min(99999, $position));
            if (! in_array($field['type'], PageRules::FIELD_TYPES, true)) {
                $errors[$prefix.'type'] = __('pages.choice_invalid');
            }
            if (! in_array($field['status'], ['active', 'disabled'], true)) {
                $errors[$prefix.'status'] = __('pages.choice_invalid');
            }
            $decorative = in_array($field['type'], PageRules::DECORATIVE_TYPES, true);
            if ($decorative) {
                $field['required'] = false;
            }
            foreach ((array) ($raw['names'] ?? []) as $code => $name) {
                $name = $text($name);
                if (in_array($code, $codes, true) && $name !== '') {
                    if (! $plain($name, 200)) {
                        $errors[$prefix.'names.'.$code] = __('pages.text_invalid', ['max' => 200]);
                    }
                    $field['names'][$code] = $name;
                }
            }
            if (! isset($field['names'][$default]) && $field['type'] !== 'separator') {
                $errors[$prefix.'names.'.$default] = __('pages.form_field_name_required');
            }
            if (in_array($field['type'], PageRules::CHOICE_TYPES, true)) {
                $variants = array_values((array) ($raw['variants'] ?? []));
                if (count($variants) > self::MAX_VARIANTS) {
                    $errors[$prefix.'variants'] = __('pages.form_variants_many', ['max' => self::MAX_VARIANTS]);
                    $variants = [];
                }
                foreach ($variants as $j => $variant) {
                    $variant = (array) $variant;
                    $labels = [];
                    foreach ((array) ($variant['labels'] ?? []) as $code => $label) {
                        $label = $text($label);
                        if (in_array($code, $codes, true) && $label !== '') {
                            if (! $plain($label, 200)) {
                                $errors[$prefix.'variants.'.$j.'.labels.'.$code] = __('pages.text_invalid', ['max' => 200]);
                            }
                            $labels[$code] = $label;
                        }
                    }
                    // A row without any label is an unused empty line and is skipped.
                    if ($labels === []) {
                        continue;
                    }
                    if (! isset($labels[$default])) {
                        $errors[$prefix.'variants.'.$j.'.labels.'.$default] = __('pages.form_variant_label_required');
                    }
                    $variantPosition = filter_var($variant['position'] ?? 0, FILTER_VALIDATE_INT);
                    $field['variants'][] = ['position' => $variantPosition === false ? 0 : max(-99999, min(99999, $variantPosition)), 'labels' => $labels];
                }
                if ($field['variants'] === [] && $field['status'] === 'active') {
                    $errors[$prefix.'variants'] = __('pages.form_variants_required');
                }
            }
            $keys[$field['key']] = $field;
            $form['fields'][] = $field;
        }
        if ($form['subject_mode'] === 'field') {
            $subject = $keys[$form['subject_field']] ?? null;
            if ($subject === null || ! in_array($subject['type'], PageRules::SUBJECT_TYPES, true)) {
                $errors['form.subject_field'] = __('pages.form_subject_field_invalid');
            }
        }
        if ($form['enabled'] && array_filter($form['fields'], fn (array $f): bool => $f['status'] === 'active' && ! in_array($f['type'], PageRules::DECORATIVE_TYPES, true)) === []) {
            $errors['form.fields'] = __('pages.form_fields_required');
        }

        return $form;
    }

    /** @param array<string, mixed> $form */
    public function write(int $page, array $form): void
    {
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code');
        // The subject reference is cleared first so removed fields never block on the foreign key.
        DB::table('page_forms')->updateOrInsert(['page_id' => $page], ['enabled' => $form['enabled'], 'placement' => $form['placement'], 'subject_mode' => 'form_name', 'subject_field_id' => null, 'recipients' => $form['recipients']]);
        foreach ($locales as $code => $localeId) {
            $row = $form['translations'][$code] ?? null;
            if ($row === null || ($row['custom_subject'] === '' && $row['submit_text'] === '')) {
                DB::table('page_form_translations')->where(['page_id' => $page, 'locale_id' => $localeId])->delete();
            } else {
                DB::table('page_form_translations')->updateOrInsert(['page_id' => $page, 'locale_id' => $localeId], $row);
            }
        }

        $existing = DB::table('page_form_fields')->where('page_id', $page)->pluck('id')->map(fn ($v): int => (int) $v)->all();
        $kept = [];
        $ids = [];
        foreach ($form['fields'] as $field) {
            $values = ['position' => $field['position'], 'type' => $field['type'], 'required' => $field['required'], 'status' => $field['status']];
            if ($field['id'] !== null && in_array($field['id'], $existing, true)) {
                DB::table('page_form_fields')->where('id', $field['id'])->update($values);
                $id = $field['id'];
            } else {
                $id = (int) DB::table('page_form_fields')->insertGetId(['page_id' => $page, ...$values]);
            }
            $kept[] = $id;
            $ids[$field['key']] = $id;
            foreach ($locales as $code => $localeId) {
                $name = $field['names'][$code] ?? null;
                if ($name === null) {
                    DB::table('page_form_field_translations')->where(['field_id' => $id, 'locale_id' => $localeId])->delete();
                } else {
                    DB::table('page_form_field_translations')->updateOrInsert(['field_id' => $id, 'locale_id' => $localeId], ['name' => $name]);
                }
            }
            DB::table('page_form_variants')->where('field_id', $id)->delete();
            foreach ($field['variants'] as $variant) {
                $variantId = DB::table('page_form_variants')->insertGetId(['field_id' => $id, 'position' => $variant['position']]);
                foreach ($variant['labels'] as $code => $label) {
                    DB::table('page_form_variant_translations')->insert(['variant_id' => $variantId, 'locale_id' => $locales[$code], 'label' => $label]);
                }
            }
        }
        DB::table('page_form_fields')->where('page_id', $page)->whereNotIn('id', $kept === [] ? [0] : $kept)->delete();
        if ($form['subject_mode'] !== 'form_name') {
            DB::table('page_forms')->where('page_id', $page)->update(['subject_mode' => $form['subject_mode'], 'subject_field_id' => $form['subject_mode'] === 'field' ? $ids[$form['subject_field']] : null]);
        }
    }
}
