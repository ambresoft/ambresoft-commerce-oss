<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Contract\CategoryAdministrationInterface;
use App\Catalog\Application\Contract\CategoryCsvImportInterface;
use App\Catalog\Application\Contract\FeatureAdministration;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Application\Contract\VariationGroupAdministration;
use App\Catalog\Application\Service\CategoryMutationParticipants;
use App\Catalog\Application\Service\ProductDeletionGuards;
use App\Catalog\Infrastructure\Import\CategoryCsvImporter;
use App\Catalog\Infrastructure\Persistence\EloquentCategoryAdministration;
use App\Catalog\Infrastructure\Persistence\EloquentFeatureAdministration;
use App\Catalog\Infrastructure\Persistence\EloquentProductAdministration;
use App\Catalog\Infrastructure\Persistence\EloquentVariationGroupAdministration;
use App\Localization\Domain\Event\LanguagesConfigured;
use App\Website\Infrastructure\MenuCategoryParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FeatureAdministration::class, EloquentFeatureAdministration::class);
        $this->app->singleton(CategoryMutationParticipants::class);
        $this->app->bind(CategoryAdministrationInterface::class, EloquentCategoryAdministration::class);
        $this->app->bind(CategoryCsvImportInterface::class, CategoryCsvImporter::class);
        $this->app->bind(ProductAdministration::class, EloquentProductAdministration::class);
        $this->app->singleton(ProductDeletionGuards::class);
        $this->app->bind(VariationGroupAdministration::class, EloquentVariationGroupAdministration::class);
        $this->app->scoped(VariationJoinOutcome::class);
    }

    public function boot(CategoryMutationParticipants $participants): void
    {
        $this->app->make(ProductDeletionGuards::class)->add(fn (string $id): ?string => DB::table('product_package_items')->where('product_id', $id)->exists() ? 'packages.used' : null);
        // Variation Groups veto first: deletion must be refused before products move to Uncategorized.
        $participants->add('core.variation-groups', $this->app->make(VariationGroupCategoryParticipant::class), 50);
        $participants->add('core.menus', $this->app->make(MenuCategoryParticipant::class), 40);
        $participants->add('core.products', $this->app->make(ProductCategoryParticipant::class));
        Event::listen(LanguagesConfigured::class, function ($event): void {
            $this->app->make(ProductEligibility::class)->refresh($event->actorId);
            DB::table('feature_catalog_state')->where('id', 1)->increment('version');
        });
    }
}
