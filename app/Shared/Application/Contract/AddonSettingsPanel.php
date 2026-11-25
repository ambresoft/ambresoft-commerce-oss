<?php

namespace App\Shared\Application\Contract;

use App\Shared\Application\Command\AddonPanelAction;
use App\Shared\Application\Command\AddonPanelRow;
use App\Shared\Application\Command\AddonPanelSecret;

/**
 * Optional beside AddonSettings: read-only status lines and buttons on the add-on's Settings page, for things a form
 * cannot express. The CMS renders them, authorizes each action with `addons.manage` and audits it.
 */
interface AddonSettingsPanel
{
    /** @return list<AddonPanelRow> */
    public function panelRows(): array;

    /** @return list<AddonPanelAction> The actions on offer now; anything else is refused. */
    public function panelActions(): array;

    /** Runs an action on offer. A returned secret reaches the browser once and is stored nowhere by the CMS. */
    public function runPanelAction(string $action): ?AddonPanelSecret;
}
