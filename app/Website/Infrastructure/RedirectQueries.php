<?php

namespace App\Website\Infrastructure;

use App\Website\Domain\Service\RedirectRules;
use Illuminate\Support\Facades\DB;

/** Read projections for Website → SEO Rules, with simple chain hints taken from the enabled rules. */
final class RedirectQueries
{
    /**
     * @param  array<string, mixed>  $filters  search, status (enabled|disabled), code (301|302), page, per_page
     * @return array<string, mixed> Laravel paginator array.
     */
    public function listing(array $filters): array
    {
        $q = DB::table('seo_redirects');
        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
            $q->where(fn ($w) => $w->where('source_path', 'like', $like)->orWhereRaw('lower(destination) like ?', [$like]));
        }
        if (! empty($filters['status'])) {
            $q->where('enabled', $filters['status'] === 'enabled');
        }
        if (! empty($filters['code'])) {
            $q->where('status_code', (int) $filters['code']);
        }
        $hints = $this->hints();

        return $q->orderBy('source_path')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()
            ->through(fn ($r) => [...$this->row($r), ...$hints($r->source_path, $r->destination)])->toArray();
    }

    /** @return array<string, mixed>|null */
    public function editor(string $id): ?array
    {
        $row = DB::table('seo_redirects')->where('id', $id)->first();

        return $row === null ? null : [...$this->row($row), ...($this->hints())($row->source_path, $row->destination)];
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        return ['id' => (string) $r->id, 'source_path' => $r->source_path, 'destination' => $r->destination, 'status_code' => (int) $r->status_code,
            'enabled' => (bool) $r->enabled, 'external' => RedirectRules::internalPath($r->destination) === null, 'version' => (int) $r->lock_version,
            'updated_at' => $r->updated_at === null ? null : now()->parse($r->updated_at)->utc()->toIso8601ZuluString()];
    }

    /**
     * Where the destination is itself redirected (a chain), and which other enabled rules point at this source.
     *
     * @return callable(string, string): array{chain_to: ?string, incoming: list<string>}
     */
    private function hints(): callable
    {
        $enabled = DB::table('seo_redirects')->where('enabled', true)->pluck('destination', 'source_path')->all();
        $incoming = [];
        foreach ($enabled as $source => $destination) {
            if (($target = RedirectRules::internalPath((string) $destination)) !== null) {
                $incoming[$target][] = (string) $source;
            }
        }

        return function (string $source, string $destination) use ($enabled, $incoming): array {
            $target = RedirectRules::internalPath($destination);

            return ['chain_to' => $target !== null && isset($enabled[$target]) ? (string) $enabled[$target] : null,
                'incoming' => array_slice($incoming[$source] ?? [], 0, 5)];
        };
    }
}
