<?php

namespace App\Geography\Infrastructure;

use App\Geography\Application\Contract\GeographyAdministration;
use App\Geography\Application\Contract\RegionCsvImportInterface;
use App\Geography\Application\Contract\ShippingGeography;
use App\Geography\Infrastructure\Import\RegionCsvImporter;
use App\Geography\Infrastructure\Persistence\EloquentGeography;
use Illuminate\Support\ServiceProvider;

final class GeographyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GeographyAdministration::class, EloquentGeography::class);
        $this->app->bind(ShippingGeography::class, EloquentGeography::class);
        $this->app->bind(RegionCsvImportInterface::class, RegionCsvImporter::class);
    }
}
