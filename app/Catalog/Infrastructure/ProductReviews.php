<?php

namespace App\Catalog\Infrastructure;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Domain\Model\ReviewStatus;
use App\Catalog\Domain\Service\ReviewRules;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Notification\Infrastructure\AdminNotifications;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The single write path for product reviews. Customer submission takes an already verified customer identity (the
 * future storefront sign-in supplies it); only rating, title and text are read from the payload, so status,
 * verification and moderator fields can never be assigned by a client. Ratings are aggregated live from approved
 * rows (ProductRatings), so every change here is reflected in the next summary without a cache to invalidate.
 */
final class ProductReviews
{
    /** Products that can be bought by direct link can be reviewed; trashed and disabled products cannot. */
    public const REVIEWABLE_PRODUCT_STATUSES = ['active', 'hidden'];

    public function __construct(private ReviewRules $rules, private ReviewPurchases $purchases) {}

    /** @param array<string, mixed> $input */
    public function submit(string $customerId, string $productId, array $input): string
    {
        $fields = $this->content($input, creating: true);
        if (! $this->identifier($customerId) || ! $this->identifier($productId)) {
            throw ValidationException::withMessages(['product' => __('reviews.product_unavailable')]);
        }
        try {
            return DB::transaction(function () use ($customerId, $productId, $fields): string {
                // Shared locks keep the product and customer eligible until the review commits.
                $product = DB::table('products')->where('id', $productId)->sharedLock()->first(['id', 'status', 'deleted_at', 'reviews_enabled']);
                // Reviews switched off for the store or for this product accept no new ones.
                if ($product === null || $product->deleted_at !== null || ! in_array($product->status, self::REVIEWABLE_PRODUCT_STATUSES, true) || ! $product->reviews_enabled || ! app(GeneralSettings::class)->all()['reviews_enabled']) {
                    throw ValidationException::withMessages(['product' => __('reviews.product_unavailable')]);
                }
                $customer = DB::table('customers')->where('id', $customerId)->sharedLock()->first(['id', 'status']);
                if ($customer === null || $customer->status !== 'active') {
                    throw ValidationException::withMessages(['customer' => __('reviews.customer_unavailable')]);
                }
                if (DB::table('product_reviews')->where('product_id', $productId)->where('customer_id', $customerId)->exists()) {
                    throw ValidationException::withMessages(['product' => __('reviews.duplicate')]);
                }

                $id = (string) DB::table('product_reviews')->insertGetId([
                    'product_id' => $productId, 'customer_id' => $customerId, 'rating' => $fields['rating'], 'title' => $fields['title'] ?? null, 'body' => $fields['body'],
                    'status' => ReviewStatus::Pending->value, 'verified_purchase' => $this->purchases->verified($customerId, $productId), 'created_at' => now(), 'updated_at' => now(),
                ]);
                app(AdminNotifications::class)->reviewSubmitted($id, (int) $fields['rating']);

                return $id;
            });
        } catch (QueryException $e) {
            // A concurrent submission committed first: the unique (product, customer) key decides.
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'product_reviews_product_id_customer_id_unique')) {
                throw ValidationException::withMessages(['product' => __('reviews.duplicate')]);
            }
            throw $e;
        }
    }

    /**
     * Staff correction of rating/title/text (`reviews.edit`) and the internal note (`reviews.moderate`). Status is
     * changed only through moderate().
     *
     * @param  array<string, mixed>  $input
     */
    public function update(string $actor, string $id, array $input): void
    {
        app(AuditLogger::class)->operation('review', $actor, fn () => DB::transaction(function () use ($actor, $id, $input): void {
            $this->actor($actor);
            $version = $this->version($input);
            $content = array_intersect_key($input, array_flip(['rating', 'title', 'body']));
            $noted = array_key_exists('moderation_note', $input);
            if ($content === [] && ! $noted) {
                throw ValidationException::withMessages(['review' => __('reviews.no_changes')]);
            }
            if ($content !== []) {
                $this->authorize($actor, 'reviews.edit');
            }
            if ($noted) {
                $this->authorize($actor, 'reviews.moderate');
            }
            $row = $this->row($id, $version);
            $values = $content === [] ? [] : $this->content($content, creating: false);
            if ($noted) {
                $values['moderation_note'] = $this->note($input['moderation_note']);
            }
            $changed = array_filter($values, fn ($value, string $field): bool => (string) $value !== (string) ($row->{$field} ?? ''), ARRAY_FILTER_USE_BOTH);
            if ($changed === []) {
                return;
            }
            if (array_intersect_key($changed, array_flip(['rating', 'title', 'body'])) !== []) {
                $changed += ['edited_by' => $actor, 'edited_at' => now()];
            }
            DB::table('product_reviews')->where('id', $id)->update([...$changed, 'lock_version' => (int) $row->lock_version + 1, 'updated_at' => now()]);
        }));
    }

    public function moderate(string $actor, string $id, int $version, string $status): void
    {
        app(AuditLogger::class)->operation('review', $actor, fn () => DB::transaction(function () use ($actor, $id, $version, $status): void {
            $this->authorize($actor, 'reviews.moderate');
            $this->transition($actor, $this->row($id, $version), $this->target($status));
        }));
    }

    /**
     * Each selected review is checked and changed in its own transaction; one refusal never undoes the others.
     *
     * @param  list<string>  $ids
     * @return list<array{id: string, success: bool, reason: string}>
     */
    public function bulkModerate(string $actor, array $ids, string $status): array
    {
        return app(AuditLogger::class)->operation('review', $actor, function () use ($actor, $ids, $status): array {
            $this->authorize($actor, 'reviews.moderate');
            $target = $this->target($status);
            $ids = array_values(array_unique(array_map(strval(...), $ids)));
            if ($ids === [] || count($ids) > 100 || array_filter($ids, fn (string $id): bool => ! $this->identifier($id)) !== []) {
                throw ValidationException::withMessages(['ids' => __('reviews.selection_invalid')]);
            }
            $results = [];
            foreach ($ids as $id) {
                try {
                    DB::transaction(function () use ($actor, $id, $target): void {
                        $this->authorize($actor, 'reviews.moderate');
                        $row = DB::table('product_reviews')->where('id', $id)->lockForUpdate()->first();
                        if ($row === null) {
                            throw ValidationException::withMessages(['review' => __('reviews.unavailable')]);
                        }
                        $this->transition($actor, $row, $target);
                    });
                    $results[] = ['id' => $id, 'success' => true, 'reason' => ''];
                } catch (ValidationException $e) {
                    $results[] = ['id' => $id, 'success' => false, 'reason' => implode(' ', array_merge(...array_values($e->errors())))];
                } catch (AuthorizationException) {
                    $results[] = ['id' => $id, 'success' => false, 'reason' => __('reviews.forbidden')];
                }
            }
            $failures = count(array_filter($results, fn (array $r): bool => ! $r['success']));
            app(AuditLogger::class)->record(new AuditEvent('review.bulk_completed', 'review', status: $failures > 0 ? 'failure' : 'success', metadata: ['success_count' => count($results) - $failures, 'failure_count' => $failures]));

            return $results;
        });
    }

    public function delete(string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('review', $actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor, 'reviews.delete');
            $this->row($id, $version);
            DB::table('product_reviews')->where('id', $id)->delete();
        }));
    }

    /** Approval re-checks the purchase, so the public badge reflects the orders as they are when the review is published. */
    private function transition(string $actor, object $row, ReviewStatus $target): void
    {
        $current = ReviewStatus::from($row->status);
        if (! $current->canBecome($target)) {
            throw ValidationException::withMessages(['status' => __('reviews.transition_invalid', ['from' => __('reviews.status_'.$current->value), 'to' => __('reviews.status_'.$target->value)])]);
        }
        $values = ['status' => $target->value, 'moderated_by' => $actor, 'moderated_at' => now(), 'lock_version' => (int) $row->lock_version + 1, 'updated_at' => now()];
        if ($target === ReviewStatus::Approved) {
            $values['verified_purchase'] = $this->purchases->verified((string) $row->customer_id, (string) $row->product_id);
        }
        DB::table('product_reviews')->where('id', $row->id)->update($values);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{rating?: int, title?: ?string, body?: string}
     */
    private function content(array $input, bool $creating): array
    {
        $input = array_intersect_key($input, array_flip(['rating', 'title', 'body']));
        foreach (['title', 'body'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = $this->rules->normalize($input[$field]);
            }
        }
        $present = $creating ? 'required' : 'sometimes';
        $rules = [
            'rating' => [$present, 'required', 'integer', 'between:'.ReviewRules::MIN_RATING.','.ReviewRules::MAX_RATING, 'regex:/^[1-5]$/D'],
            'title' => ['sometimes', 'nullable', 'string', 'max:'.ReviewRules::TITLE_MAX, 'not_regex:/[\x00-\x1f\x7f]/'],
            'body' => [$present, 'required', 'string', 'max:'.ReviewRules::BODY_MAX, 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/'],
        ];
        $attributes = ['rating' => __('reviews.rating'), 'title' => __('reviews.review_title'), 'body' => __('reviews.body')];
        $fields = Validator::make($input, $rules, ['rating.regex' => __('reviews.rating_invalid'), 'rating.integer' => __('reviews.rating_invalid'), 'rating.between' => __('reviews.rating_invalid')], $attributes)->validate();
        foreach (['title', 'body'] as $field) {
            if (isset($fields[$field]) && ! $this->rules->meaningful($fields[$field])) {
                throw ValidationException::withMessages([$field => __('reviews.text_meaningless', ['attribute' => $attributes[$field]])]);
            }
        }
        if (array_key_exists('rating', $fields)) {
            $fields['rating'] = (int) $fields['rating'];
        }

        return $fields;
    }

    private function note(mixed $value): ?string
    {
        $note = is_string($value) ? $this->rules->normalize($value) : $value;
        $data = Validator::make(['moderation_note' => $note], ['moderation_note' => ['nullable', 'string', 'max:'.ReviewRules::NOTE_MAX, 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/']], [], ['moderation_note' => __('reviews.moderation_note')])->validate();

        return $data['moderation_note'];
    }

    private function target(string $status): ReviewStatus
    {
        return ReviewStatus::tryFrom($status) ?? throw ValidationException::withMessages(['status' => __('reviews.status_invalid')]);
    }

    /** @param array<string, mixed> $input */
    private function version(array $input): int
    {
        return (int) Validator::make($input, ['version' => ['required', 'integer', 'min:1']])->validate()['version'];
    }

    private function row(string $id, int $version): object
    {
        $row = $this->identifier($id) ? DB::table('product_reviews')->where('id', $id)->lockForUpdate()->first() : null;
        if ($row === null || (int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('reviews.stale')]);
        }

        return $row;
    }

    private function identifier(string $id): bool
    {
        return preg_match('/^[1-9][0-9]{0,17}$/D', $id) === 1;
    }

    private function actor(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
    }

    private function authorize(string $actor, string $ability): void
    {
        $this->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
    }
}
