<?php

return [
    'settings' => 'Settings', 'title' => 'Languages', 'translations' => 'Translations', 'permission' => 'Manage system languages and UI translations',
    'help' => 'Choose the active languages and one default. The dashboard language menu shows only active languages; each user chooses their own preference. Deactivation retains existing content.',
    'active' => 'Active languages', 'default' => 'Default system language', 'saved' => 'Language settings saved.',
    'strict_hint' => 'With two or more active languages, every localized category field is required in every active language on save. Existing incomplete categories are retained, but must be completed before their next save. Missing hierarchy parents must be imported as complete rows first.',
    'dictionary_help' => 'Search by key or text and customize UI wording. Preserve all :parameter placeholders. Changes affect this installation only; restore returns to the shipped dictionary. Merchant category content is edited separately.',
    'translation_saved' => 'UI translation saved.', 'restore' => 'Restore original', 'overridden' => 'Customized', 'original' => 'Shipped wording', 'string_count' => ':count strings', 'pagination' => 'Dictionary pages',
    'placeholders' => 'Preserve exactly the original named placeholders.',
    'catalog_required' => 'Complete every localized field in every active language before saving.',
];
