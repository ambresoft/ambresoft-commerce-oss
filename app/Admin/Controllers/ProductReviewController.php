<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Catalog\Domain\Model\ReviewStatus;
use App\Catalog\Infrastructure\ProductReviewQueries;
use App\Catalog\Infrastructure\ProductReviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ProductReviewController
{
    public function index(Request $request, ProductReviewQueries $queries): Response
    {
        Gate::authorize('reviews.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::in(ReviewStatus::values())], 'rating' => ['nullable', 'integer', 'between:1,5'], 'verified' => ['nullable', Rule::in(['yes', 'no'])], 'product' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807'], 'created_from' => ['nullable', 'date_format:Y-m-d'], 'created_to' => ['nullable', 'date_format:Y-m-d'], 'sort' => ['nullable', Rule::in(ProductReviewQueries::SORTS)], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'per_page' => ['nullable', Rule::in([25, 50, 100])], 'page' => ['nullable', 'integer', 'between:1,1000000']]);
        // An empty PHP array serializes as a JS array, whose inherited .sort would mask the default.
        $filters = ['sort' => 'created_at', 'direction' => 'desc', 'per_page' => '25', ...array_map(strval(...), array_filter($filters, fn ($value): bool => $value !== null))];

        return Inertia::render('Admin/Reviews/Index', [...$this->shell($request), 'reviews' => $queries->listing($filters, Gate::allows('customers.view')), 'filters' => $filters, 'statuses' => ReviewStatus::values(), 'bulkResults' => $request->session()->get('review_bulk_results', [])]);
    }

    public function edit(Request $request, ProductReviewQueries $queries, string $review): Response
    {
        Gate::authorize('reviews.view');
        $editor = $queries->editor($review, Gate::allows('customers.view')) ?? abort(404);

        return Inertia::render('Admin/Reviews/Edit', [...$this->shell($request, __('reviews.review_number', ['id' => $review])), 'review' => $editor, 'returnTo' => $this->returnTo($request)]);
    }

    public function update(Request $request, ProductReviews $reviews, string $review): RedirectResponse
    {
        Gate::authorize('reviews.view');
        $reviews->update((string) $request->user()->id, $review, $request->only(['version', 'rating', 'title', 'body', 'moderation_note']));

        return redirect('/admin/reviews/'.$review.'/edit?'.http_build_query(['return_to' => $this->returnTo($request)]))->with('status', __('reviews.saved'));
    }

    public function status(Request $request, ProductReviews $reviews, string $review): RedirectResponse
    {
        Gate::authorize('reviews.moderate');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(ReviewStatus::values())]]);
        $reviews->moderate((string) $request->user()->id, $review, (int) $data['version'], $data['status']);

        return redirect()->back(fallback: '/admin/reviews/'.$review.'/edit')->with('status', __('reviews.moderated_'.$data['status']));
    }

    public function bulk(Request $request, ProductReviews $reviews): RedirectResponse
    {
        Gate::authorize('reviews.moderate');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct'], 'status' => ['required', Rule::in([ReviewStatus::Approved->value, ReviewStatus::Rejected->value, ReviewStatus::Spam->value])]]);
        $results = $reviews->bulkModerate((string) $request->user()->id, array_map(strval(...), $data['ids']), $data['status']);

        return redirect($this->returnTo($request))->with('review_bulk_results', $results);
    }

    public function destroy(Request $request, ProductReviews $reviews, string $review): RedirectResponse
    {
        Gate::authorize('reviews.delete');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $reviews->delete((string) $request->user()->id, $review, (int) $data['version']);

        return redirect($this->returnTo($request))->with('status', __('reviews.deleted'));
    }

    private function returnTo(Request $request): string
    {
        $value = $request->input('return_to');

        return is_string($value) && strlen($value) <= 2000 && preg_match('/^\/admin\/reviews(?:\?[^\r\n#]*)?$/D', $value) === 1 ? $value : '/admin/reviews';
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('reviews.title'), 'href' => $current === null ? null : '/admin/reviews']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
