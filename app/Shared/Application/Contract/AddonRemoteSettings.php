<?php

namespace App\Shared\Application\Contract;

use App\Shared\Application\Command\AddonRemoteForm;
use SensitiveParameter;

/**
 * Optional beside AddonSettings: a form for settings that live in another system, such as a key for an outside service.
 * The CMS renders it, checks `addons.manage` and CSRF, validates the fields and audits the submission. It stores nothing:
 * the values reach the add-on for this one request, and never the session, a page prop, the audit trail or the settings row.
 */
interface AddonRemoteSettings
{
    /** The form as it is now, or null to show none. */
    public function remoteForm(): ?AddonRemoteForm;

    /**
     * Hands a submission on. Nothing is kept here, so the add-on decides what happens to it.
     *
     * @param  array<string, string>  $values  Field key => value. A secret left empty means "keep what is stored"
     * @return array<string, string> Field key => localized message; `form` for the form as a whole. Empty means it worked
     */
    public function submitRemoteForm(string $choice, #[SensitiveParameter] array $values): array;
}
