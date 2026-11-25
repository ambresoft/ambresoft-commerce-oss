<?php

namespace App\Website\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FaqQueries
{
    private function questions(): Builder
    {
        $locales = app(LocaleCatalog::class)->active();
        $default = $locales->firstWhere('is_default', true)->id;
        $current = $locales->firstWhere('code', app()->getLocale())->id ?? $default;

        return DB::table('faqs as f')->leftJoin('faq_translations as c', fn ($j) => $j->on('c.faq_id', '=', 'f.id')->where('c.locale_id', $current))
            ->leftJoin('faq_translations as d', fn ($j) => $j->on('d.faq_id', '=', 'f.id')->where('d.locale_id', $default))
            ->select('f.id', 'f.type', 'f.status', 'f.position', 'f.lock_version')->selectRaw("coalesce(c.question, d.question, '#' || f.id) as question");
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters): array
    {
        $query = $this->questions();
        if (($filters['search'] ?? '') !== '') {
            $search = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('c.question', 'ilike', $search)->orWhere('d.question', 'ilike', $search));
        }
        foreach (['type', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('f.'.$field, $filters[$field]);
            }
        }
        foreach (['category', 'product', 'page'] as $owner) {
            $query->selectSub(DB::table($owner.'_faqs')->selectRaw('count(*)')->whereColumn('faq_id', 'f.id'), $owner.'_links');
        }
        $page = $query->orderBy('f.position')->orderBy('f.id')->paginate(50)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn ($row): array => ['id' => (string) $row->id, 'question' => $row->question, 'type' => $row->type, 'status' => $row->status, 'position' => (int) $row->position, 'version' => (int) $row->lock_version, 'links' => (int) $row->category_links + (int) $row->product_links + (int) $row->page_links]));

        return $page->toArray();
    }

    /** @return array<string, mixed>|null */
    public function editor(string $id): ?array
    {
        $row = $this->questions()->where('f.id', $id)->first();
        if ($row === null) {
            return null;
        }
        $translations = DB::table('faq_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.faq_id', $id)->get()->mapWithKeys(fn ($t): array => [$t->code => ['question' => $t->question, 'answer' => $t->answer]])->all();

        return ['id' => (string) $row->id, 'question' => $row->question, 'type' => $row->type, 'status' => $row->status, 'position' => (int) $row->position, 'version' => (int) $row->lock_version, 'translations' => (object) $translations, 'links' => app(FaqLinks::class)->usage($id)];
    }

    /** @param list<string> $selected
     * @return list<array{id: string, question: string, type: string, status: string}>
     */
    public function options(string $owner, array $selected = [], string $search = ''): array
    {
        $query = $this->questions()->whereIn('f.type', $owner === 'page' ? ['general'] : ['general', $owner]);
        $available = (clone $query)->where('f.status', 'active');
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '%_\\').'%';
            $available->where(fn ($q) => $q->where('c.question', 'ilike', $pattern)->orWhere('d.question', 'ilike', $pattern));
        }
        $rows = $available->orderBy('f.position')->orderBy('f.id')->limit(50)->get();
        $rows = $rows->merge((clone $query)->whereIn('f.id', $selected)->get())->unique('id')->values();

        return $rows->map(fn ($r): array => ['id' => (string) $r->id, 'question' => $r->question, 'type' => $r->type, 'status' => $r->status])->all();
    }
}
