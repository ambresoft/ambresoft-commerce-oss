<?php

namespace App\Admin\Controllers;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\NewsletterQueries;
use App\Marketing\Infrastructure\NewsletterSubscribers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class NewsletterSubscriberController
{
    use NewsletterShell;

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'unsubscribed'])], 'list' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
    }

    public function index(Request $request, NewsletterQueries $queries): Response
    {
        Gate::authorize('subscribers.view');
        $filters = $this->filters($request);

        return Inertia::render('Admin/Newsletters/Subscribers', [...$this->shell($request, 'subscribers_title', '/admin/newsletter-subscribers'), 'subscribers' => $queries->subscribers($filters),
            'filters' => (object) $filters, 'lists' => $queries->listChoices()]);
    }

    public function create(Request $request, NewsletterQueries $queries): Response
    {
        Gate::authorize('subscribers.manage');

        return $this->form($request, $queries, null, __('newsletters.create_subscriber'));
    }

    public function store(Request $request, NewsletterSubscribers $subscribers): RedirectResponse
    {
        $id = $subscribers->create((string) $request->user()->id, $request->all());

        return redirect('/admin/newsletter-subscribers/'.$id.'/edit')->with('status', __('newsletters.saved'));
    }

    public function import(Request $request, NewsletterSubscribers $subscribers): RedirectResponse
    {
        $result = $subscribers->import((string) $request->user()->id, $request->all());

        return redirect('/admin/newsletter-subscribers')->with('status', __('newsletters.import_done', $result));
    }

    public function edit(Request $request, NewsletterQueries $queries, string $subscriber): Response
    {
        Gate::authorize('subscribers.manage');
        $editor = $queries->subscriberEditor($subscriber) ?? abort(404);

        return $this->form($request, $queries, $editor, $editor['email']);
    }

    public function update(Request $request, NewsletterSubscribers $subscribers, string $subscriber): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $subscribers->update((string) $request->user()->id, $subscriber, (int) $data['version'], $request->except('version'));

        return redirect('/admin/newsletter-subscribers/'.$subscriber.'/edit')->with('status', __('newsletters.saved'));
    }

    public function destroy(Request $request, NewsletterSubscribers $subscribers, string $subscriber): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $subscribers->delete((string) $request->user()->id, $subscriber, (int) $data['version']);

        return redirect('/admin/newsletter-subscribers')->with('status', __('newsletters.deleted'));
    }

    public function export(Request $request, NewsletterQueries $queries): StreamedResponse
    {
        Gate::authorize('subscribers.view');
        $filters = $this->filters($request);

        return response()->streamDownload(function () use ($queries, $filters): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['email', 'status', 'language', 'source', 'lists', 'confirmed', 'created_at']);
            foreach ($queries->export($filters) as $row) {
                // Spreadsheet formulas in addresses must not run when the file is opened.
                fputcsv($out, array_map(fn (string $v): string => $v !== '' && str_contains('=+-@', $v[0]) ? "'".$v : $v, $row));
            }
            fclose($out);
        }, 'subscribers.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @param array<string, mixed>|null $editor */
    private function form(Request $request, NewsletterQueries $queries, ?array $editor, string $title): Response
    {
        return Inertia::render('Admin/Newsletters/SubscriberForm', [...$this->shell($request, 'subscribers_title', '/admin/newsletter-subscribers', $title), 'item' => $editor,
            'lists' => $queries->listChoices(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }
}
