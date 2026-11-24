<?php

namespace App\Customer\Infrastructure;

use App\Geography\Application\Contract\ShippingGeography;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Payment\Infrastructure\PaymentSettings;
use App\Shared\Infrastructure\Geography\Countries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Admin read models. Lists are filtered, sorted and paginated in SQL; related names load per page in one query. */
final class CustomerQueries
{
    public function __construct(private Countries $countries) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters): array
    {
        $query = DB::table('customers')->select(['id', 'first_name', 'last_name', 'email', 'phone', 'status', 'tax_exempt', 'created_at', 'user_id', 'deleted_at'])
            ->selectRaw(ContactNormalizer::blacklistedSql().' AS blacklisted');
        if (($filters['search'] ?? '') !== '') {
            $term = trim((string) $filters['search']);
            $like = '%'.addcslashes($term, '\\%_').'%';
            $digits = preg_replace('/\D/', '', $term);
            $query->where(function ($q) use ($like, $digits): void {
                $q->where('email', 'ilike', $like)->orWhere('first_name', 'ilike', $like)->orWhere('last_name', 'ilike', $like)
                    ->orWhereRaw("(first_name || ' ' || last_name) ILIKE ?", [$like])->orWhereRaw("(last_name || ' ' || first_name) ILIKE ?", [$like]);
                // Phone numbers match on their digits, whatever spacing or punctuation was stored.
                if (strlen((string) $digits) >= 3) {
                    $q->orWhereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') LIKE ?", ['%'.$digits.'%']);
                }
            });
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        if (($filters['blacklisted'] ?? '') !== '') {
            $query->whereRaw(($filters['blacklisted'] === 'yes' ? '' : 'NOT ').ContactNormalizer::blacklistedSql());
        }
        if (($filters['account'] ?? '') !== '') {
            $filters['account'] === 'yes' ? $query->whereNotNull('user_id') : $query->whereNull('user_id');
        }
        if (($filters['group'] ?? '') !== '') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('customer_group_members as m')->whereColumn('m.customer_id', 'customers.id')->where('m.group_id', $filters['group']));
        }
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : (($filters['direction'] ?? null) === 'desc' ? 'desc' : null);
        match ($filters['sort'] ?? 'created_at') {
            'name' => $query->orderByRaw('lower(last_name) '.($direction ?? 'asc'))->orderByRaw('lower(first_name) '.($direction ?? 'asc')),
            'email' => $query->orderBy('email', $direction ?? 'asc'),
            default => $query->orderBy('created_at', $direction ?? 'desc'),
        };
        // ID breaks ties so pages never overlap or skip rows.
        $page = $query->orderBy('id', $direction ?? (($filters['sort'] ?? 'created_at') === 'created_at' ? 'desc' : 'asc'))->paginate(25)->withQueryString();
        $groups = $this->groupNames($page->getCollection()->pluck('id')->all());
        $page->setCollection($page->getCollection()->map(fn ($row): array => [
            'id' => (string) $row->id, 'name' => trim($row->first_name.' '.$row->last_name), 'email' => $row->email, 'phone' => $row->phone,
            'status' => $row->status, 'deleted_at' => $row->deleted_at === null ? null : CarbonImmutable::parse($row->deleted_at)->toIso8601String(), 'tax_exempt' => (bool) $row->tax_exempt, 'blacklisted' => (bool) $row->blacklisted, 'has_account' => $row->user_id !== null, 'groups' => $groups[(string) $row->id] ?? [], 'created_at' => (string) $row->created_at,
        ]));

        return $page->toArray();
    }

    /** @return array<string, mixed>|null */
    public function detail(string $id): ?array
    {
        $c = DB::table('customers')->where('id', $id)->first();
        if ($c === null) {
            return null;
        }
        $rows = DB::table('customer_addresses')->where('customer_id', $id)->orderBy('id')->get();
        $regions = app(ShippingGeography::class)->regionNames($rows->filter(fn ($a): bool => $a->region_code !== null)->map(fn ($a): array => [$a->country_code, $a->region_code])->values()->all());
        $addresses = $rows->map(fn ($a): array => [
            'id' => (string) $a->id, 'label' => $a->label, 'first_name' => $a->first_name, 'last_name' => $a->last_name, 'company' => $a->company,
            'line1' => $a->line1, 'line2' => $a->line2, 'city' => $a->city, 'postal_code' => $a->postal_code, 'country_code' => $a->country_code,
            'country' => $this->countries->name($a->country_code), 'region_code' => $a->region_code, 'region' => $a->region_code === null ? null : ($regions[$a->country_code.':'.$a->region_code] ?? null), 'phone' => $a->phone,
            'street_number' => $a->street_number, 'floor' => $a->floor, 'bell_name' => $a->bell_name, 'notes' => $a->notes,
        ])->all();

        return [
            'id' => (string) $c->id, 'version' => (int) $c->lock_version, 'first_name' => $c->first_name, 'last_name' => $c->last_name, 'email' => $c->email,
            'phone' => $c->phone, 'status' => $c->status, 'deleted_at' => $c->deleted_at === null ? null : CarbonImmutable::parse($c->deleted_at)->toIso8601String(), 'tax_exempt' => (bool) $c->tax_exempt, 'notes' => $c->notes,
            'group_ids' => DB::table('customer_group_members')->where('customer_id', $id)->orderBy('group_id')->pluck('group_id')->map(strval(...))->all(),
            'groups' => $this->groupNames([$id])[$id] ?? [],
            'addresses' => $addresses,
            'account' => $this->account($c),
            'blacklist' => $this->blacklistEntries($c),
            'default_billing_address_id' => $c->default_billing_address_id === null ? null : (string) $c->default_billing_address_id,
            'default_shipping_address_id' => $c->default_shipping_address_id === null ? null : (string) $c->default_shipping_address_id,
            'created_at' => (string) $c->created_at, 'updated_at' => (string) $c->updated_at,
        ];
    }

    /**
     * Groups offered when editing a customer: active ones plus disabled groups the customer already has.
     *
     * @param  list<string>  $assigned
     * @return list<array{id: string, name: string, active: bool, default: bool}>
     */
    public function groupOptions(array $assigned = []): array
    {
        $rows = DB::table('customer_groups')->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $assigned))->orderBy('id')->get();
        $names = $this->names($rows->pluck('id')->all());

        return $rows->map(fn ($row): array => ['id' => (string) $row->id, 'name' => $names[(string) $row->id]['name'] ?? '#'.$row->id, 'active' => $row->status === 'active', 'default' => (bool) $row->is_default])->all();
    }

    /** @return list<array<string, mixed>> */
    public function groupListing(): array
    {
        $rows = DB::table('customer_groups')->orderByDesc('is_default')->orderBy('id')->get();
        $names = $this->names($rows->pluck('id')->all());
        $members = DB::table('customer_group_members')->selectRaw('group_id, count(*) as total')->groupBy('group_id')->pluck('total', 'group_id');

        return $rows->map(fn ($row): array => [
            'id' => (string) $row->id, 'version' => (int) $row->lock_version, 'name' => $names[(string) $row->id]['name'] ?? '#'.$row->id,
            'description' => $names[(string) $row->id]['description'] ?? '', 'status' => $row->status, 'default' => (bool) $row->is_default, 'members' => (int) ($members[$row->id] ?? 0),
        ])->all();
    }

    /** @return array<string, mixed>|null */
    public function groupEditor(string $id): ?array
    {
        $group = DB::table('customer_groups')->where('id', $id)->first();
        if ($group === null) {
            return null;
        }
        $texts = DB::table('customer_group_translations')->join('locales', 'locales.id', '=', 'customer_group_translations.locale_id')->where('group_id', $id)->get(['locales.code', 'customer_group_translations.name', 'customer_group_translations.description']);

        return [
            'id' => (string) $group->id, 'version' => (int) $group->lock_version, 'status' => $group->status, 'is_default' => (bool) $group->is_default,
            'names' => (object) $texts->pluck('name', 'code')->all(), 'descriptions' => (object) $texts->pluck('description', 'code')->all(),
            'members' => DB::table('customer_group_members')->where('group_id', $id)->count(),
        ];
    }

    /**
     * @param  list<int|string>  $customerIds
     * @return array<string, list<string>>
     */
    private function groupNames(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }
        $memberships = DB::table('customer_group_members')->whereIn('customer_id', $customerIds)->orderBy('group_id')->get();
        $names = $this->names($memberships->pluck('group_id')->unique()->values()->all());
        $result = [];
        foreach ($memberships as $row) {
            $result[(string) $row->customer_id][] = $names[(string) $row->group_id]['name'] ?? '#'.$row->group_id;
        }

        return $result;
    }

    /**
     * Current language, then default language, then any.
     *
     * @param  list<int|string>  $groupIds
     * @return array<string, array{name: string, description: string}>
     */
    private function names(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $active = app(LocaleCatalog::class)->active();
        $current = $active->firstWhere('code', app()->getLocale())?->id;
        $default = $active->firstWhere('is_default', true)?->id;
        $best = [];
        foreach (DB::table('customer_group_translations')->whereIn('group_id', $groupIds)->get() as $row) {
            $rank = $row->locale_id === $current ? 0 : ($row->locale_id === $default ? 1 : 2);
            $key = (string) $row->group_id;
            if (! isset($best[$key]) || $rank < $best[$key][0]) {
                $best[$key] = [$rank, ['name' => $row->name, 'description' => $row->description]];
            }
        }

        return array_map(fn (array $entry): array => $entry[1], $best);
    }

    /**
     * Every registered payment method (active or not), for choosing which ones a blacklist entry takes away.
     *
     * @return list<array{id: string, name: string}>
     */
    public function paymentMethodOptions(): array
    {
        $settings = app(PaymentSettings::class);

        return DB::table('payment_methods')->orderBy('position')->orderBy('id')->pluck('id')
            ->map(fn ($id): array => ['id' => (string) $id, 'name' => $settings->name((string) $id, app()->getLocale())])->all();
    }

    /**
     * Names of the payment methods each entry takes away (entries of scope `payment_methods`).
     *
     * @param  list<string>  $entryIds
     * @return array<string, list<string>>
     */
    public function blacklistMethods(array $entryIds): array
    {
        $settings = app(PaymentSettings::class);
        $out = [];
        foreach ($entryIds === [] ? [] : DB::table('customer_blacklist_payment_methods')->whereIn('entry_id', $entryIds)->orderBy('payment_method_id')->get() as $row) {
            $out[(string) $row->entry_id][] = $settings->name((string) $row->payment_method_id, app()->getLocale());
        }

        return $out;
    }

    /**
     * Active entries matching this customer's email or any phone it stores, with the reason.
     *
     * @return list<array<string, mixed>>
     */
    private function blacklistEntries(object $customer): array
    {
        $phones = array_values(array_filter(array_unique(array_map(ContactNormalizer::phone(...), [
            $customer->phone, ...DB::table('customer_addresses')->where('customer_id', $customer->id)->pluck('phone')->all(),
        ]))));

        $rows = DB::table('customer_blacklist_entries as e')->leftJoin('users as u', 'u.id', '=', 'e.created_by')->whereNull('e.released_at')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('e.kind', 'email')->where('e.value', $customer->email))
                ->orWhere(fn ($q) => $q->where('e.kind', 'phone')->whereIn('e.value', $phones)))
            ->orderBy('e.id')->get(['e.id', 'e.kind', 'e.value', 'e.reason', 'e.created_at', 'e.customer_id', 'e.scope', 'u.name as actor']);
        $methods = $this->blacklistMethods($rows->pluck('id')->map(strval(...))->all());

        return $rows->map(fn ($e): array => ['id' => (string) $e->id, 'kind' => $e->kind, 'value' => $e->value, 'reason' => $e->reason, 'created_at' => (string) $e->created_at, 'actor' => $e->actor, 'scope' => $e->scope, 'payment_methods' => $methods[(string) $e->id] ?? [], 'own' => (string) $e->customer_id === (string) $customer->id])->all();
    }

    /**
     * The customer's sign-in account (A99/A100): linked; an unverified identity with this e-mail (invited or registered,
     * awaiting verification); a verified identity that attaches at its next sign-in; or none.
     *
     * @return array{state: string, since: ?string, email_verified: bool, active: bool}
     */
    private function account(object $customer): array
    {
        $user = $customer->user_id !== null
            ? DB::table('users')->where('id', $customer->user_id)->first(['email_verified_at', 'is_active'])
            : DB::table('users')->where('email', $customer->email)->first(['email_verified_at', 'is_active']);
        $state = match (true) {
            $customer->user_id !== null => 'linked',
            $user === null => 'none',
            $user->email_verified_at === null => 'invited',
            default => 'registered',
        };

        return ['state' => $state, 'since' => $customer->account_linked_at === null ? null : CarbonImmutable::parse($customer->account_linked_at)->toIso8601String(),
            'email_verified' => $user !== null && $user->email_verified_at !== null, 'active' => $user === null || (bool) $user->is_active];
    }
}
