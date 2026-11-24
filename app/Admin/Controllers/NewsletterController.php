<?php

namespace App\Admin\Controllers;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\NewsletterAiGenerator;
use App\Marketing\Infrastructure\NewsletterContent;
use App\Marketing\Infrastructure\NewsletterDelivery;
use App\Marketing\Infrastructure\NewsletterQueries;
use App\Settings\Infrastructure\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Newsletters, templates and autoresponders: one controller, three route sets distinguished by `type`. */
final class NewsletterController
{
    use NewsletterShell;

    private const SECTIONS = [
        'newsletter' => ['/admin/newsletters', 'newsletters_title', 'newsletter'],
        'template' => ['/admin/newsletter-templates', 'templates_title', 'template'],
        'autoresponder' => ['/admin/newsletter-autoresponders', 'autoresponders_title', 'autoresponder'],
    ];

    public function index(Request $request, NewsletterQueries $queries): Response
    {
        $type = $this->type($request);
        Gate::authorize('newsletters.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'disabled'])], 'campaign' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        [$base, $title] = self::SECTIONS[$type];

        return Inertia::render('Admin/Newsletters/Index', [...$this->shell($request, $title, $base), 'type' => $type, 'base' => $base, 'items' => $queries->newsletters($type, $filters),
            'filters' => (object) $filters, 'campaigns' => $queries->options()['campaigns']]);
    }

    public function create(Request $request, NewsletterQueries $queries): Response
    {
        $type = $this->type($request);
        Gate::authorize('newsletters.manage');

        return $this->form($request, $queries, $type, null, __('newsletters.create_'.$type));
    }

    public function store(Request $request, NewsletterContent $content): RedirectResponse
    {
        $type = $this->type($request);
        $id = $content->save((string) $request->user()->id, $type, null, null, $request->all());

        return redirect(self::SECTIONS[$type][0].'/'.$id.'/edit')->with('status', __('newsletters.saved'));
    }

    public function edit(Request $request, NewsletterQueries $queries, string $item): Response
    {
        $type = $this->type($request);
        Gate::authorize('newsletters.manage');
        $editor = $queries->editor($type, $item) ?? abort(404);

        return $this->form($request, $queries, $type, $editor, $editor['translations']->{app()->getLocale()}['name'] ?? $editor['translations']->{app(LocaleCatalog::class)->defaultCode()}['name'] ?? '#'.$item);
    }

    public function update(Request $request, NewsletterContent $content, string $item): RedirectResponse
    {
        $type = $this->type($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $content->save((string) $request->user()->id, $type, $item, (int) $data['version'], $request->except('version'));

        return redirect(self::SECTIONS[$type][0].'/'.$item.'/edit')->with('status', __('newsletters.saved'));
    }

    public function active(Request $request, NewsletterContent $content, string $item): RedirectResponse
    {
        $type = $this->type($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'active' => ['required', 'boolean']]);
        $content->setActive((string) $request->user()->id, $type, $item, (int) $data['version'], (bool) $data['active']);

        return back()->with('status', __('newsletters.saved'));
    }

    public function destroy(Request $request, NewsletterContent $content, string $item): RedirectResponse
    {
        $type = $this->type($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $content->delete((string) $request->user()->id, $type, $item, (int) $data['version']);

        return redirect(self::SECTIONS[$type][0])->with('status', __('newsletters.deleted'));
    }

    /** Queues the newsletter for everyone in its audience who was not addressed yet. */
    public function send(Request $request, NewsletterDelivery $deliveries, string $item): RedirectResponse
    {
        $count = $deliveries->queueNewsletter((string) $request->user()->id, $item);

        return back()->with('status', $count > 0 ? __('newsletters.send_queued', ['count' => $count]) : __('newsletters.send_nothing_new'));
    }

    public function test(Request $request, NewsletterDelivery $deliveries, string $item): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:254'], 'locale' => ['nullable', 'string', 'max:5']]);
        $deliveries->sendTest((string) $request->user()->id, $item, $data['email'], $data['locale'] ?? app(LocaleCatalog::class)->defaultCode());

        return back()->with('status', __('newsletters.test_sent', ['email' => $data['email']]));
    }

    /** Renders the unsaved subject and body with sample values for the live preview. */
    public function assistant(Request $request, NewsletterAiGenerator $generator): JsonResponse
    {
        return response()->json($generator->generate((string) $request->user()->id, $request->only(['locale', 'type', 'newsletter_id', 'messages', 'current'])));
    }

    public function preview(Request $request, NewsletterDelivery $deliveries): JsonResponse
    {
        $data = $request->validate(['subject' => ['nullable', 'string', 'max:300'], 'body_html' => ['nullable', 'string', 'max:200000'], 'locale' => ['required', 'string', 'max:5'], 'type' => ['sometimes', Rule::in(NewsletterContent::TYPES)]]);

        return response()->json($deliveries->preview((string) $request->user()->id, (string) ($data['subject'] ?? ''), (string) ($data['body_html'] ?? ''), $data['locale'], $data['type'] ?? 'newsletter'))->header('Cache-Control', 'no-store');
    }

    public function customers(Request $request, NewsletterQueries $queries): JsonResponse
    {
        Gate::authorize('newsletters.manage');

        return response()->json(['customers' => $queries->customerSearch((string) $request->query('term', ''))]);
    }

    /** The kind of message of this route set (set by the route defaults). */
    private function type(Request $request): string
    {
        return (string) $request->route()?->defaults['type'];
    }

    /** @param array<string, mixed>|null $editor */
    private function form(Request $request, NewsletterQueries $queries, string $type, ?array $editor, string $title): Response
    {
        [$base, $section] = self::SECTIONS[$type];

        return Inertia::render('Admin/Newsletters/Form', [...$this->shell($request, $section, $base, $title), 'type' => $type, 'base' => $base, 'item' => $editor,
            ...$queries->options(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(), 'canSend' => Gate::allows('newsletters.send'),
            'aiReady' => Gate::allows('newsletters.generate') && (fn (array $ai): bool => $ai['enabled'] && $ai['has_key'])(app(AiSettings::class)->all())]);
    }
}
