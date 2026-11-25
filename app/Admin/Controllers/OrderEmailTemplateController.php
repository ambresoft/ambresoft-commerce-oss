<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Order\Infrastructure\InvoiceLayout;
use App\Order\Infrastructure\OrderEmailDefaults;
use App\Order\Infrastructure\OrderEmailTemplates;
use App\Order\Infrastructure\OrderQueries;
use App\Order\Infrastructure\PackingSlipLayout;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class OrderEmailTemplateController
{
    public function index(Request $request, OrderQueries $queries): Response
    {
        Gate::authorize('order-emails.manage');
        $usage = $queries->usage();
        $updated = DB::table('order_email_templates')->selectRaw('status_id, max(updated_at) AS updated_at')->groupBy('status_id')->pluck('updated_at', 'status_id');
        $statuses = $queries->statuses();

        return Inertia::render('Admin/OrderEmails/Index', [
            ...$this->shell($request),
            'statuses' => array_map(fn (array $s): array => [...$s, 'templates' => $usage[$s['id']]['templates'], 'updated_at' => isset($updated[$s['id']]) ? CarbonImmutable::parse($updated[$s['id']])->toIso8601String() : null], $statuses),
            'invoiceStatuses' => array_values(array_map(fn (array $s): string => $s['name'], array_filter($statuses, fn (array $s): bool => $s['invoice']))),
        ]);
    }

    public function edit(Request $request, OrderQueries $queries, string $status): Response
    {
        Gate::authorize('order-emails.manage');
        $definition = collect($queries->statuses())->firstWhere('id', $status) ?? abort(404);
        // A language activated after the status was created gets its generated template on first visit.
        OrderEmailDefaults::templates((int) $status, $definition['initial']);
        $templates = DB::table('order_email_templates as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.status_id', $status)->whereIn('l.code', $this->locales())
            ->get(['t.id', 't.lock_version', 't.subject', 't.body_html', 'l.code'])
            ->mapWithKeys(fn (object $t): array => [$t->code => ['id' => (string) $t->id, 'version' => (int) $t->lock_version, 'subject' => $t->subject, 'body' => $t->body_html]]);

        return Inertia::render('Admin/OrderEmails/Edit', [...$this->shell($request, $definition['name']), 'kind' => 'status', 'status' => $definition, 'templates' => (object) $templates->all(), ...$this->editorProps()]);
    }

    public function update(Request $request, OrderEmailTemplates $templates, string $status, string $template): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'subject' => ['required', 'string', 'max:250'], 'body' => ['required', 'string', 'max:100000']]);
        abort_unless(DB::table('order_email_templates')->where('id', $template)->where('status_id', $status)->exists(), 404);
        $templates->save((string) $request->user()->id, $template, (int) $data['version'], $data['subject'], $data['body']);

        return back()->with('status', __('orders.template_saved'));
    }

    /** Header and footer (kind layout) or the invoice email (kind invoice), per language. */
    public function layout(Request $request, string $kind): Response
    {
        Gate::authorize('order-emails.manage');
        $kind = self::kind($kind);
        OrderEmailDefaults::seedLayouts();
        $templates = DB::table('order_email_layouts as t')->join('locales as l', 'l.id', '=', 't.locale_id')->whereIn('l.code', $this->locales())->get(['t.*', 'l.code'])
            ->mapWithKeys(fn (object $t): array => [$t->code => ['id' => (string) $t->id, 'version' => (int) $t->lock_version, ...($kind === 'layout' ? ['header' => $t->header_html, 'footer' => $t->footer_html] : ['subject' => $t->invoice_subject, 'body' => InvoiceLayout::expand($t->invoice_body_html, $t->code), 'item' => $t->invoice_item_html ?? InvoiceLayout::item($t->code), 'total' => $t->invoice_total_html ?? InvoiceLayout::total()])]]);

        return Inertia::render('Admin/OrderEmails/Edit', [
            ...$this->shell($request, __($kind === 'layout' ? 'orders.layout_title' : 'orders.invoice_email_title')), 'kind' => $kind, 'status' => null, 'templates' => (object) $templates->all(), ...$this->editorProps(),
            'invoiceStatuses' => DB::table('order_statuses as s')->leftJoin('order_status_translations as t', fn ($j) => $j->on('t.status_id', '=', 's.id')->where('t.locale_id', app(LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())->id ?? 0))
                ->where('s.sends_invoice', true)->orderBy('s.position')->selectRaw('coalesce(t.name, s.code) AS name')->pluck('name')->all(),
        ]);
    }

    public function updateLayout(Request $request, OrderEmailTemplates $templates, string $kind, string $layout): RedirectResponse
    {
        $kind = self::kind($kind);
        $data = $request->validate($kind === 'layout'
            ? ['version' => ['required', 'integer', 'min:1'], 'header' => ['present', 'nullable', 'string', 'max:100000'], 'footer' => ['present', 'nullable', 'string', 'max:100000']]
            : ['version' => ['required', 'integer', 'min:1'], 'subject' => ['required', 'string', 'max:250'], 'body' => ['required', 'string', 'max:100000'], 'item' => ['sometimes', 'required', 'string', 'max:100000'], 'total' => ['sometimes', 'required', 'string', 'max:100000']]);
        $version = (int) $data['version'];
        unset($data['version']);
        $templates->saveLayout((string) $request->user()->id, $layout, $version, array_map(fn ($v): string => (string) $v, $data));

        return back()->with('status', __($kind === 'layout' ? 'orders.layout_saved' : 'orders.invoice_saved'));
    }

    /** Orders → Files: printable order documents (for now the packing slip). */
    public function files(Request $request): Response
    {
        Gate::authorize('order-emails.manage');

        return Inertia::render('Admin/OrderFiles/Index', ['navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('orders.files_title'), 'href' => null]]]);
    }

    /** The packing slip per language, in the same editor as the order summary (no subject, never e-mailed). */
    public function packingSlip(Request $request): Response
    {
        Gate::authorize('order-emails.manage');
        OrderEmailDefaults::seedLayouts();
        $templates = DB::table('order_email_layouts as t')->join('locales as l', 'l.id', '=', 't.locale_id')->whereIn('l.code', $this->locales())->get(['t.*', 'l.code'])
            ->mapWithKeys(fn (object $t): array => [$t->code => ['id' => (string) $t->id, 'version' => (int) $t->lock_version,
                'body' => $t->packing_slip_body_html ?? PackingSlipLayout::document($t->code), 'item' => $t->packing_slip_item_html ?? PackingSlipLayout::item()]]);

        return Inertia::render('Admin/OrderEmails/Edit', ['navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('orders.files_title'), 'href' => '/admin/orders/files'], ['label' => __('orders.packing_slip_title'), 'href' => null]],
            'kind' => 'packing_slip', 'status' => null, 'templates' => (object) $templates->all(), ...$this->editorProps()]);
    }

    public function updatePackingSlip(Request $request, OrderEmailTemplates $templates, string $layout): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'body' => ['required', 'string', 'max:100000'], 'item' => ['required', 'string', 'max:100000']]);
        $templates->saveLayout((string) $request->user()->id, $layout, (int) $data['version'], ['body' => (string) $data['body'], 'item' => (string) $data['item']], 'packing_slip');

        return back()->with('status', __('orders.packing_slip_saved'));
    }

    /** Live preview of unsaved content with sample data, for the editors. Never sends an email. */
    public function preview(Request $request, OrderEmailTemplates $templates): JsonResponse
    {
        $data = $this->content($request);

        return response()->json($templates->preview((string) $request->user()->id, $data['kind'], $data['status_id'], $data['locale'], $data['content']))->header('Cache-Control', 'no-store');
    }

    /** Sends the unsaved content with sample data to one address; no order is involved. */
    public function test(Request $request, OrderEmailTemplates $templates): RedirectResponse
    {
        $data = $this->content($request);
        $recipient = $request->validate(['recipient' => ['required', 'string', 'max:254', 'email:rfc', 'not_regex:/[\s<>"]/']])['recipient'];
        $result = $templates->test((string) $request->user()->id, $data['kind'], $data['status_id'], $data['locale'], $data['content'], strtolower($recipient));

        $inbox = config('mail.local_inbox_url');
        $sent = __('orders.test_sent', ['email' => strtolower($recipient)]).(is_string($inbox) && $inbox !== '' ? ' '.__('orders.test_local_inbox', ['url' => $inbox]) : '');

        return back()->with('status', $result->accepted ? $sent : __('orders.test_failed', ['reason' => __('orders.mail_error_'.($result->error ?? 'mail_rejected'))]));
    }

    /** @return array{kind: string, status_id: ?string, locale: string, content: array<string, string>} */
    private function content(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(OrderEmailTemplates::KINDS)], 'status_id' => ['nullable', 'integer', 'exists:order_statuses,id'], 'locale' => ['required', Rule::in($this->locales())],
            'subject' => ['sometimes', 'nullable', 'string', 'max:250'], 'body' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'item' => ['sometimes', 'nullable', 'string', 'max:100000'], 'total' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'header' => ['sometimes', 'nullable', 'string', 'max:100000'], 'footer' => ['sometimes', 'nullable', 'string', 'max:100000'],
        ]);
        $content = [];
        foreach (['subject', 'body', 'header', 'footer', 'item', 'total'] as $field) {
            if ($request->has($field)) {
                $content[$field] = (string) ($data[$field] ?? '');
            }
        }

        return ['kind' => $data['kind'], 'status_id' => isset($data['status_id']) ? (string) $data['status_id'] : null, 'locale' => $data['locale'], 'content' => $content];
    }

    /** @return array<string, mixed> */
    private function editorProps(): array
    {
        return [
            'variables' => array_map(fn (string $v): array => ['name' => $v, 'label' => __('orders.var_'.$v), 'block' => in_array($v, OrderEmailDefaults::BLOCK_VARIABLES, true)], OrderEmailDefaults::VARIABLES),
            'defaultLocale' => app(LocaleCatalog::class)->defaultCode(),
            'localInbox' => config('mail.local_inbox_url') ?: null,
        ];
    }

    /** @return list<string> */
    private function locales(): array
    {
        return app(LocaleCatalog::class)->active()->pluck('code')->all();
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('orders.emails_title'), 'href' => $current === null ? null : '/admin/order-emails']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }

    /** The order summary's address is /order-emails/order-summary; its stored kind stays `invoice`. */
    private static function kind(string $segment): string
    {
        return match ($segment) {
            'layout' => 'layout', 'order-summary' => 'invoice', default => abort(404),
        };
    }
}
