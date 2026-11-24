<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read models of the newsletter administration. Texts fall back from the interface language to the default one. */
final class NewsletterQueries
{
    private function iso(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }

    /** @return array<string, int> locale code => ID for the interface and default languages */
    private function localeIds(): array
    {
        return DB::table('locales')->whereIn('code', [app()->getLocale(), app(LocaleCatalog::class)->defaultCode()])->pluck('id', 'code')->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function newsletters(string $type, array $filters): array
    {
        $ids = $this->localeIds();
        $locale = app()->getLocale();
        $default = app(LocaleCatalog::class)->defaultCode();
        $q = DB::table('newsletters as n')->where('n.type', $type)
            ->leftJoin('newsletter_translations as c', fn ($j) => $j->on('c.newsletter_id', '=', 'n.id')->where('c.locale_id', $ids[$locale] ?? 0))
            ->leftJoin('newsletter_translations as d', fn ($j) => $j->on('d.newsletter_id', '=', 'n.id')->where('d.locale_id', $ids[$default] ?? 0))
            ->leftJoin('newsletter_campaigns as k', 'k.id', '=', 'n.campaign_id');
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $q->where(fn ($w) => $w->where('c.name', 'ilike', $term)->orWhere('d.name', 'ilike', $term)->orWhere('c.subject', 'ilike', $term)->orWhere('d.subject', 'ilike', $term));
        }
        if (in_array($filters['status'] ?? null, ['active', 'disabled'], true)) {
            $q->where('n.is_active', $filters['status'] === 'active');
        }
        if (ctype_digit((string) ($filters['campaign'] ?? ''))) {
            $q->where('n.campaign_id', $filters['campaign']);
        }
        $page = $q->orderByDesc('n.id')->select(['n.*', 'k.name as campaign_name', DB::raw("coalesce(nullif(c.name, ''), d.name, '#' || n.id) as display_name"), DB::raw("coalesce(nullif(c.subject, ''), d.subject, '') as display_subject")])
            ->paginate(25)->withQueryString();
        $stats = $this->stats($page->getCollection()->pluck('id')->all());
        $page->setCollection($page->getCollection()->map(fn ($n) => [
            'id' => (string) $n->id, 'version' => (int) $n->lock_version, 'name' => $n->display_name, 'subject' => $n->display_subject, 'active' => (bool) $n->is_active,
            'state' => $n->state, 'campaign' => $n->campaign_name, 'sent_at' => $this->iso($n->sent_at), 'updated_at' => $this->iso($n->updated_at),
            'stats' => $stats[$n->id] ?? ['total' => 0, 'sent' => 0, 'pending' => 0, 'failed' => 0],
        ]));

        return $page->toArray();
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, array{total: int, sent: int, pending: int, failed: int}>
     */
    private function stats(array $ids): array
    {
        $out = [];
        foreach (DB::table('newsletter_deliveries')->whereIn('newsletter_id', $ids)->selectRaw('newsletter_id, state, attempts, count(*) as n')->groupBy('newsletter_id', 'state', 'attempts')->get() as $r) {
            $s = $out[$r->newsletter_id] ?? ['total' => 0, 'sent' => 0, 'pending' => 0, 'failed' => 0];
            $s['total'] += $r->n;
            $key = $r->state === 'sent' ? 'sent' : ($r->state === 'failed' && $r->attempts >= 3 ? 'failed' : 'pending');
            $s[$key] += $r->n;
            $out[$r->newsletter_id] = $s;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function editor(string $type, string $id): ?array
    {
        $n = DB::table('newsletters')->where('id', $id)->where('type', $type)->first();
        if ($n === null) {
            return null;
        }
        $translations = DB::table('newsletter_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.newsletter_id', $id)->get(['l.code', 't.name', 't.subject', 't.random_subjects', 't.body_html'])
            ->mapWithKeys(fn ($t) => [$t->code => ['name' => $t->name, 'subject' => $t->subject, 'random_subjects' => $t->random_subjects, 'body_html' => $t->body_html]]);

        return [
            'id' => (string) $n->id, 'version' => (int) $n->lock_version, 'type' => $n->type, 'state' => $n->state, 'active' => (bool) $n->is_active, 'campaign_id' => $n->campaign_id === null ? '' : (string) $n->campaign_id,
            'translations' => (object) $translations->all(), 'sent_at' => $this->iso($n->sent_at),
            'list_ids' => DB::table('newsletter_audiences')->where('newsletter_id', $id)->pluck('list_id')->map(strval(...))->all(),
            'customers' => DB::table('newsletter_audience_customers as a')->join('customers as c', 'c.id', '=', 'a.customer_id')->where('a.newsletter_id', $id)->orderBy('c.last_name')->get(['c.id', 'c.first_name', 'c.last_name', 'c.email'])
                ->map(fn ($c) => ['id' => (string) $c->id, 'name' => trim($c->first_name.' '.$c->last_name), 'email' => $c->email])->all(),
            'stats' => $this->stats([$id])[$id] ?? ['total' => 0, 'sent' => 0, 'pending' => 0, 'failed' => 0],
        ];
    }

    /**
     * Options for the editors.
     *
     * @return array{campaigns: list<array<string, string>>, lists: list<array<string, string>>, templates: list<array<string, mixed>>}
     */
    public function options(): array
    {
        return [
            'campaigns' => DB::table('newsletter_campaigns')->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name])->all(),
            'lists' => $this->listChoices(),
            'templates' => DB::table('newsletters')->where('type', 'template')->orderBy('id')->pluck('id')->map(function ($id): array {
                $t = DB::table('newsletter_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.newsletter_id', $id)->get(['l.code', 't.name', 't.body_html']);

                return ['id' => (string) $id, 'name' => (string) ($t->firstWhere('code', app()->getLocale())->name ?? $t->first()->name ?? '#'.$id), 'bodies' => $t->pluck('body_html', 'code')->all()];
            })->all(),
            'autoresponders' => $this->autoresponderChoices(),
        ];
    }

    /** @return list<array{id: string, name: string}> */
    public function autoresponderChoices(): array
    {
        return $this->named('newsletters', 'newsletter_translations', 'newsletter_id', fn ($q) => $q->where('b.type', 'autoresponder'));
    }

    /** @return list<array{id: string, name: string}> */
    public function listChoices(): array
    {
        return $this->named('newsletter_lists', 'newsletter_list_translations', 'list_id');
    }

    /** @return list<array{id: string, name: string}> */
    private function named(string $base, string $translations, string $owner, ?callable $filter = null): array
    {
        $ids = $this->localeIds();
        $q = DB::table($base.' as b')
            ->leftJoin($translations.' as c', fn ($j) => $j->on('c.'.$owner, '=', 'b.id')->where('c.locale_id', $ids[app()->getLocale()] ?? 0))
            ->leftJoin($translations.' as d', fn ($j) => $j->on('d.'.$owner, '=', 'b.id')->where('d.locale_id', $ids[app(LocaleCatalog::class)->defaultCode()] ?? 0));
        if ($filter !== null) {
            $filter($q);
        }

        return $q->orderBy('b.id')->get(['b.id', DB::raw("coalesce(nullif(c.name, ''), d.name, '#' || b.id) as name")])->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name])->all();
    }

    /** @return array<string, mixed> */
    public function lists(): array
    {
        $names = collect($this->listChoices())->pluck('name', 'id');
        $members = DB::table('newsletter_list_subscribers')->selectRaw('list_id, count(*) as total, count(confirmed_at) as confirmed')->groupBy('list_id')->get()->keyBy('list_id');
        $rows = DB::table('newsletter_lists')->orderBy('id')->get()->map(fn ($l) => [
            'id' => (string) $l->id, 'version' => (int) $l->lock_version, 'name' => $names[(string) $l->id] ?? '#'.$l->id, 'active' => $l->status === 'active',
            'subscribers' => (int) ($members[$l->id]->total ?? 0), 'confirmed' => (int) ($members[$l->id]->confirmed ?? 0),
            'show_at_checkout' => (bool) $l->show_at_checkout, 'show_on_registration' => (bool) $l->show_on_registration, 'updated_at' => $this->iso($l->updated_at),
        ])->all();

        return ['data' => $rows];
    }

    /** @return array<string, mixed>|null */
    public function listEditor(string $id): ?array
    {
        $l = DB::table('newsletter_lists')->where('id', $id)->first();
        if ($l === null) {
            return null;
        }
        $translations = DB::table('newsletter_list_translations as t')->join('locales as lo', 'lo.id', '=', 't.locale_id')->where('t.list_id', $id)->get(['lo.code', 't.name', 't.description'])
            ->mapWithKeys(fn ($t) => [$t->code => ['name' => $t->name, 'description' => $t->description]]);

        return ['id' => (string) $l->id, 'version' => (int) $l->lock_version, 'status' => $l->status, 'from_name' => $l->from_name ?? '', 'from_email' => $l->from_email ?? '', 'reply_to' => $l->reply_to ?? '',
            'confirmation_id' => $l->confirmation_id === null ? '' : (string) $l->confirmation_id, 'show_at_checkout' => (bool) $l->show_at_checkout, 'show_on_registration' => (bool) $l->show_on_registration,
            'translations' => (object) $translations->all()];
    }

    /** @return array<string, mixed> */
    public function campaigns(): array
    {
        $counts = DB::table('newsletters')->whereNotNull('campaign_id')->selectRaw('campaign_id, count(*) as n')->groupBy('campaign_id')->pluck('n', 'campaign_id');
        $sent = DB::table('newsletter_deliveries as d')->join('newsletters as n', 'n.id', '=', 'd.newsletter_id')->where('d.state', 'sent')->whereNotNull('n.campaign_id')
            ->selectRaw('n.campaign_id, count(*) as n')->groupBy('n.campaign_id')->pluck('n', 'campaign_id');

        return ['data' => DB::table('newsletter_campaigns')->orderBy('name')->get()->map(fn ($c) => [
            'id' => (string) $c->id, 'version' => (int) $c->lock_version, 'name' => $c->name, 'active' => $c->status === 'active',
            'newsletters' => (int) ($counts[$c->id] ?? 0), 'sent' => (int) ($sent[$c->id] ?? 0), 'updated_at' => $this->iso($c->updated_at),
        ])->all()];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function subscriberQuery(array $filters): Builder
    {
        $q = DB::table('newsletter_subscribers as s');
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $q->where('s.email', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
        }
        if (in_array($filters['status'] ?? null, ['active', 'unsubscribed'], true)) {
            $q->where('s.status', $filters['status']);
        }
        if (ctype_digit((string) ($filters['list'] ?? ''))) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('newsletter_list_subscribers as ls')->whereColumn('ls.subscriber_id', 's.id')->where('ls.list_id', $filters['list']));
        }

        return $q;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function subscribers(array $filters): array
    {
        $page = $this->subscriberQuery($filters)->leftJoin('locales as l', 'l.id', '=', 's.locale_id')->orderByDesc('s.id')->select(['s.*', 'l.code as locale_code'])->paginate(25)->withQueryString();
        $lists = collect($this->listChoices())->pluck('name', 'id');
        $members = DB::table('newsletter_list_subscribers')->whereIn('subscriber_id', $page->getCollection()->pluck('id'))->get()->groupBy('subscriber_id');
        $page->setCollection($page->getCollection()->map(fn ($s) => [
            'id' => (string) $s->id, 'version' => (int) $s->lock_version, 'email' => $s->email, 'status' => $s->status, 'source' => $s->source, 'locale' => $s->locale_code,
            'lists' => collect($members[$s->id] ?? [])->map(fn ($m) => ['id' => (string) $m->list_id, 'name' => $lists[(string) $m->list_id] ?? '#'.$m->list_id, 'confirmed' => $m->confirmed_at !== null])->values()->all(),
            'created_at' => $this->iso($s->created_at),
        ]));

        return $page->toArray();
    }

    /** @return array<string, mixed>|null */
    public function subscriberEditor(string $id): ?array
    {
        $s = DB::table('newsletter_subscribers as s')->leftJoin('locales as l', 'l.id', '=', 's.locale_id')->where('s.id', $id)->first(['s.*', 'l.code as locale_code']);
        if ($s === null) {
            return null;
        }

        return ['id' => (string) $s->id, 'version' => (int) $s->lock_version, 'email' => $s->email, 'status' => $s->status, 'locale' => $s->locale_code ?? '', 'source' => $s->source,
            'memberships' => DB::table('newsletter_list_subscribers')->where('subscriber_id', $id)->get()->mapWithKeys(fn ($m) => [(string) $m->list_id => $m->confirmed_at !== null])->all(),
            'created_at' => $this->iso($s->created_at)];
    }

    /**
     * Rows for the CSV export of the filtered subscribers.
     *
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, list<string>>
     */
    public function export(array $filters): \Generator
    {
        $lists = collect($this->listChoices())->pluck('name', 'id');
        foreach ($this->subscriberQuery($filters)->leftJoin('locales as l', 'l.id', '=', 's.locale_id')->orderBy('s.id')->select(['s.*', 'l.code as locale_code'])->cursor() as $s) {
            $members = DB::table('newsletter_list_subscribers')->where('subscriber_id', $s->id)->get();
            yield [$s->email, $s->status, $s->locale_code ?? '', $s->source, $members->map(fn ($m) => $lists[(string) $m->list_id] ?? '#'.$m->list_id)->implode('; '),
                $members->filter(fn ($m) => $m->confirmed_at !== null)->count().'/'.$members->count(), (string) $s->created_at];
        }
    }

    /** @return list<array{id: string, name: string, email: string}> */
    public function customerSearch(string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return DB::table('customers')->where('status', 'active')->where(fn ($q) => $q->where('email', 'ilike', $like)->orWhere('last_name', 'ilike', $like)->orWhere('first_name', 'ilike', $like))
            ->orderBy('last_name')->limit(15)->get(['id', 'first_name', 'last_name', 'email'])->map(fn ($c) => ['id' => (string) $c->id, 'name' => trim($c->first_name.' '.$c->last_name), 'email' => $c->email])->all();
    }
}
