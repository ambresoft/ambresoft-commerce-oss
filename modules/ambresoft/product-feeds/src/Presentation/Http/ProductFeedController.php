<?php

namespace Ambresoft\ProductFeeds\Presentation\Http;

use Ambresoft\ProductFeeds\Application\Service\FeedXml;
use Ambresoft\ProductFeeds\Infrastructure\Jobs\GenerateFeed;
use Ambresoft\ProductFeeds\Infrastructure\ProductFeeds;
use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Storefront\Application\Command\CatalogCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ProductFeedController
{
    private const BASE = '/admin/addons/ambresoft/product-feeds';

    public function index(Request $request, ProductFeeds $feeds, AdminNavigation $navigation): Response
    {
        return Inertia::render('Admin/Addons/ProductFeeds', [...$this->shell($request, $navigation, null),
            'feeds' => array_map(fn (array $feed): array => [...$feed, 'url' => $this->url($feed['id'])], $feeds->list()),
            'canManage' => Gate::allows('ambresoft.product-feeds.manage')]);
    }

    public function edit(Request $request, ProductFeeds $feeds, AdminNavigation $navigation, ?string $feed = null): Response
    {
        $data = $feed === null ? $feeds->blank() : $feeds->find($feed);
        abort_if($data === null, 404);
        $canManage = Gate::allows('ambresoft.product-feeds.manage');
        if (! $canManage) {
            $data['cron_password'] = '';
        }

        return Inertia::render('Admin/Addons/ProductFeeds', [...$this->shell($request, $navigation, $data['id'] === null ? __('cms-product-feeds::ui.new') : $data['name']),
            'feed' => $data, 'canManage' => $canManage,
            'urls' => $data['id'] === null ? null : ['feed' => $this->url($data['id']), 'cron' => url('/storefront/product-feeds/'.$data['id'].'/generate')],
            'categories' => array_map(fn (CatalogCategory $c): array => ['id' => $c->id, 'name' => $c->name, 'parentId' => $c->parentId], $feeds->categories()),
            'languages' => app(LocaleCatalog::class)->active()->map(fn ($l): array => ['id' => $l->id, 'name' => $l->native_name])->values()->all(),
            'attributes' => FeedXml::ATTRIBUTES]);
    }

    public function save(Request $request, ProductFeeds $feeds, ?string $feed = null): RedirectResponse
    {
        $id = $feeds->save($feed, $request->all());

        return redirect(self::BASE.'/'.$id.'/edit')->with('status', __('cms-product-feeds::ui.saved'));
    }

    public function destroy(ProductFeeds $feeds, string $feed): RedirectResponse
    {
        $feeds->delete($feed);

        return redirect(self::BASE)->with('status', __('cms-product-feeds::ui.deleted'));
    }

    public function generate(ProductFeeds $feeds, string $feed): RedirectResponse
    {
        abort_if($feeds->find($feed) === null, 404);
        GenerateFeed::dispatch($feed);

        return back()->with('status', __('cms-product-feeds::ui.queued'));
    }

    private function url(string $id): string
    {
        return url('/storefront/product-feeds/'.$id.'.xml');
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, AdminNavigation $navigation, ?string $current): array
    {
        $crumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('addons.title'), 'href' => '/admin/addons'],
            ['label' => __('cms-product-feeds::ui.title'), 'href' => $current === null ? null : self::BASE]];

        return ['navigation' => $navigation->forUser($request->user()),
            'breadcrumbs' => $current === null ? $crumbs : [...$crumbs, ['label' => $current, 'href' => null]]];
    }
}
