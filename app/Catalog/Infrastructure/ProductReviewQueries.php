<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\Model\ReviewStatus;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Admin projections of reviews. Customer e-mail is included (and searchable) only when the caller may see customers. */
final class ProductReviewQueries
{
    public const SORTS = ['created_at', 'moderated_at', 'rating', 'id'];

    private function base(): Builder
    {
        $locales = DB::table('locales')->whereIn('code', array_unique([app()->getLocale(), app(LocaleCatalog::class)->defaultCode()]))->pluck('id', 'code');
        $current = $locales[app()->getLocale()] ?? null;
        $default = $locales[app(LocaleCatalog::class)->defaultCode()] ?? null;

        return DB::table('product_reviews as r')->join('products as p', 'p.id', '=', 'r.product_id')->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->leftJoin('product_translations as pc', fn ($q) => $q->on('pc.product_id', '=', 'p.id')->where('pc.locale_id', $current))
            ->leftJoin('product_translations as pd', fn ($q) => $q->on('pd.product_id', '=', 'p.id')->where('pd.locale_id', $default))
            ->select(['r.id', 'r.product_id', 'r.customer_id', 'r.rating', 'r.title', 'r.status', 'r.verified_purchase', 'r.lock_version', 'r.created_at', 'r.updated_at', 'r.moderated_at', 'r.edited_at',
                'p.sku as product_sku', 'p.deleted_at as product_deleted_at', 'p.status as product_status', 'c.first_name', 'c.last_name', 'c.email'])
            ->selectRaw("coalesce(nullif(pc.name, ''), nullif(pd.name, ''), p.sku, '#' || p.id) as product_name");
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters, bool $customerDetails): array
    {
        $query = $this->base()->selectRaw('left(r.body, 160) as excerpt, length(r.body) > 160 as truncated');
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $q) use ($like, $search, $customerDetails): void {
                $q->whereExists(fn (Builder $t) => $t->selectRaw('1')->from('product_translations as st')->whereColumn('st.product_id', 'r.product_id')->where('st.name', 'ilike', $like))
                    ->orWhere('p.sku', 'ilike', $like)
                    ->orWhereRaw("(c.first_name || ' ' || c.last_name) ILIKE ?", [$like]);
                if ($customerDetails) {
                    $q->orWhere('c.email', 'ilike', $like);
                }
                if (preg_match('/^[1-9][0-9]{0,17}$/D', $search) === 1) {
                    $q->orWhere('r.id', $search);
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->where('r.status', $filters['status']);
        }
        if (! empty($filters['rating'])) {
            $query->where('r.rating', (int) $filters['rating']);
        }
        if (in_array($filters['verified'] ?? '', ['yes', 'no'], true)) {
            $query->where('r.verified_purchase', $filters['verified'] === 'yes');
        }
        if (! empty($filters['product'])) {
            $query->where('r.product_id', $filters['product']);
        }
        $timezone = app(GeneralSettings::class)->all()['timezone'];
        $from = $filters['created_from'] ?? null;
        $to = $filters['created_to'] ?? null;
        if ($from !== null && $to !== null && $from > $to) {
            throw ValidationException::withMessages(['created_to' => __('reviews.date_invalid')]);
        }
        if ($from !== null) {
            $query->where('r.created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }
        if ($to !== null) {
            $query->where('r.created_at', '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc());
        }
        $sort = in_array($filters['sort'] ?? '', self::SORTS, true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? '') === 'asc' ? 'asc' : 'desc';
        $query->orderByRaw('r.'.$sort.' '.$direction.($sort === 'moderated_at' ? ' NULLS LAST' : ''))->orderBy('r.id', $direction);
        $page = $query->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $rows = [];
        foreach ($page->items() as $row) {
            $rows[] = [...$this->projection($row, $customerDetails), 'excerpt' => $row->excerpt, 'truncated' => (bool) $row->truncated];
        }
        $page->setCollection(collect($rows));

        return $page->toArray();
    }

    /** @return array<string, mixed>|null */
    public function editor(string $id, bool $customerDetails): ?array
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $id) !== 1) {
            return null;
        }
        $row = $this->base()->addSelect(['r.body', 'r.moderation_note', 'm.name as moderator_name', 'e.name as editor_name'])
            ->leftJoin('users as m', 'm.id', '=', 'r.moderated_by')->leftJoin('users as e', 'e.id', '=', 'r.edited_by')->where('r.id', $id)->first();
        if ($row === null) {
            return null;
        }

        return [...$this->projection($row, $customerDetails), 'body' => $row->body, 'moderation_note' => $row->moderation_note ?? '', 'moderator_name' => $row->moderator_name, 'editor_name' => $row->editor_name,
            'transitions' => ReviewStatus::from($row->status)->targets(), 'product_rating' => app(ProductRatings::class)->summary((string) $row->product_id)];
    }

    /** @return array<string, mixed> */
    private function projection(object $row, bool $customerDetails): array
    {
        $time = fn (?string $value): ?string => $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();

        return ['id' => (string) $row->id, 'version' => (int) $row->lock_version, 'rating' => (int) $row->rating, 'title' => $row->title, 'status' => $row->status, 'verified_purchase' => (bool) $row->verified_purchase,
            'product' => ['id' => (string) $row->product_id, 'name' => $row->product_name, 'sku' => $row->product_sku, 'status' => $row->product_status, 'trashed' => $row->product_deleted_at !== null],
            'customer' => ['id' => (string) $row->customer_id, 'name' => trim($row->first_name.' '.$row->last_name), 'email' => $customerDetails ? $row->email : null],
            'created_at' => $time($row->created_at), 'updated_at' => $time($row->updated_at), 'moderated_at' => $time($row->moderated_at), 'edited_at' => $time($row->edited_at)];
    }
}
