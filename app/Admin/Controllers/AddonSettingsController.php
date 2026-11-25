<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Shared\Application\Command\AddonPanelAction;
use App\Shared\Application\Command\AddonPanelRow;
use App\Shared\Application\Command\AddonRemoteChoice;
use App\Shared\Application\Contract\AddonRemoteSettings;
use App\Shared\Application\Contract\AddonSettings;
use App\Shared\Application\Contract\AddonSettingsPanel;
use App\Shared\Infrastructure\Modules\AddonCatalog;
use App\Shared\Infrastructure\Modules\AddonSettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Add-ons → an add-on's own settings (the `settings` contribution), for staff who manage add-ons. */
final class AddonSettingsController
{
    public function show(Request $request, AddonSettingsStore $store, string $vendor, string $name): Response
    {
        Gate::authorize('addons.manage');
        $addon = $vendor.'/'.$name;
        $settings = $store->for($addon) ?? abort(404);
        $title = app(AddonCatalog::class)->manifest($addon)->title ?? $addon;

        return Inertia::render('Admin/Addons/Settings', [
            'navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('navigation.addons'), 'href' => '/admin/addons'], ['label' => $title, 'href' => null]],
            'addon' => ['name' => $addon, 'title' => $title, 'version' => (int) $store->row($addon)->lock_version, 'panel' => $this->panel($settings), 'remote' => $this->remote($store, $settings), 'fields' => $store->fieldValues()->editor($settings->settings(), $store->values($addon))],
        ]);
    }

    /** Runs a panel action. A secret leaves only in this response: never in the session, a flash message or a page prop. */
    public function action(Request $request, AddonSettingsStore $store, AuditLogger $audit, string $vendor, string $name, string $action): JsonResponse
    {
        Gate::authorize('addons.manage');
        $addon = $vendor.'/'.$name;
        $settings = $store->for($addon);
        if (! $settings instanceof AddonSettingsPanel || ! in_array($action, array_map(fn (AddonPanelAction $a): string => $a->key, $settings->panelActions()), true)) {
            abort(404);
        }
        $secret = $settings->runPanelAction($action);
        $audit->record(new AuditEvent('addon.settings_action', 'addon', $addon, entityLabel: $action));

        return response()->json(['secret' => $secret === null ? null : ['label' => $secret->label, 'hint' => $secret->hint, 'value' => $secret->value]])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Hands a remote form to its add-on. It answers JSON rather than redirecting back: a redirect with errors would flash the
     * submitted values into the session, and this form exists so that the CMS keeps none of them.
     */
    public function remoteSubmit(Request $request, AddonSettingsStore $store, AuditLogger $audit, string $vendor, string $name): JsonResponse
    {
        Gate::authorize('addons.manage');
        $addon = $vendor.'/'.$name;
        $settings = $store->for($addon);
        $form = $settings instanceof AddonRemoteSettings ? $settings->remoteForm() : null;
        if ($form === null) {
            abort(404);
        }
        $choice = collect($form->choices)->first(fn (AddonRemoteChoice $c): bool => $c->value === $request->input('choice'));
        if ($choice === null) {
            return $this->formAnswer(['form' => __('settings_fields.choice_invalid')]);
        }
        $input = $request->input('values');
        $submission = $store->fieldValues()->submit($choice->fields, [], is_array($input) ? $input : [], prefix: '');
        $errors = $submission['errors'] !== [] ? $submission['errors'] : $settings->submitRemoteForm($choice->value, $submission['values']);
        if ($errors === []) {
            $audit->record(new AuditEvent('addon.settings_action', 'addon', $addon, entityLabel: 'remote:'.$choice->value));
        }

        return $this->formAnswer($errors);
    }

    /** @param array<string, string> $errors */
    private function formAnswer(array $errors): JsonResponse
    {
        return response()->json(['errors' => (object) $errors], $errors === [] ? 200 : 422)->header('Cache-Control', 'no-store, private');
    }

    /** @return array{title: string, hint: string, selected: string, rows: list<array{label: string, value: string}>, choices: list<array<string, mixed>>}|null */
    private function remote(AddonSettingsStore $store, AddonSettings $settings): ?array
    {
        $form = $settings instanceof AddonRemoteSettings ? $settings->remoteForm() : null;
        if ($form === null) {
            return null;
        }

        return ['title' => $form->title, 'hint' => $form->hint, 'selected' => $form->selected,
            'rows' => array_map(fn (AddonPanelRow $r): array => ['label' => $r->label, 'value' => $r->value], $form->rows),
            'choices' => array_map(fn (AddonRemoteChoice $c): array => ['value' => $c->value, 'label' => $c->label, 'description' => $c->description, 'guide' => $c->guide,
                'icon' => str_starts_with($c->icon, 'data:image/') ? $c->icon : '',
                'fields' => $store->fieldValues()->editor($c->fields, $c->values)], $form->choices)];
    }

    /** @return array{rows: list<array{label: string, value: string}>, actions: list<array{key: string, label: string, confirm: string, destructive: bool, in_form: bool}>}|null */
    private function panel(AddonSettings $settings): ?array
    {
        if (! $settings instanceof AddonSettingsPanel) {
            return null;
        }

        return ['rows' => array_map(fn (AddonPanelRow $r): array => ['label' => $r->label, 'value' => $r->value], $settings->panelRows()),
            'actions' => array_map(fn (AddonPanelAction $a): array => ['key' => $a->key, 'label' => $a->label, 'confirm' => $a->confirm, 'destructive' => $a->destructive, 'in_form' => $a->inForm], $settings->panelActions())];
    }

    public function update(Request $request, AddonSettingsStore $store, string $vendor, string $name): RedirectResponse
    {
        Gate::authorize('addons.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $store->save((string) $request->user()->id, $vendor.'/'.$name, (int) $data['version'], $request->only(['settings', 'clear']));

        return back()->with('status', __('addons.settings_saved'));
    }
}
