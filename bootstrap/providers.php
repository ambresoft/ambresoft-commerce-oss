<?php

use App\Admin\AdminServiceProvider;
use App\Audit\Infrastructure\AuditServiceProvider;
use App\Authorization\Infrastructure\AuthorizationServiceProvider;
use App\Catalog\Infrastructure\CatalogServiceProvider;
use App\Customer\Infrastructure\CustomerServiceProvider;
use App\Geography\Infrastructure\GeographyServiceProvider;
use App\Identity\Infrastructure\IdentityServiceProvider;
use App\Identity\Presentation\Http\AuthenticationServiceProvider;
use App\Localization\Infrastructure\LocalizationServiceProvider;
use App\Order\Infrastructure\OrderServiceProvider;
use App\Payment\Infrastructure\PaymentServiceProvider;
use App\Pricing\Infrastructure\PricingServiceProvider;
use App\Search\Infrastructure\SearchServiceProvider;
use App\Shared\Infrastructure\Modules\ModuleServiceProvider;
use App\Shipping\Infrastructure\ShippingServiceProvider;
use App\Storefront\Infrastructure\StorefrontServiceProvider;
use App\Tax\Infrastructure\TaxServiceProvider;

return [
    AuditServiceProvider::class,
    AuthorizationServiceProvider::class,
    LocalizationServiceProvider::class,
    PricingServiceProvider::class,
    CatalogServiceProvider::class,
    TaxServiceProvider::class,
    CustomerServiceProvider::class,
    OrderServiceProvider::class,
    PaymentServiceProvider::class,
    ShippingServiceProvider::class,
    SearchServiceProvider::class,
    GeographyServiceProvider::class,
    IdentityServiceProvider::class,
    AuthenticationServiceProvider::class,
    AdminServiceProvider::class,
    StorefrontServiceProvider::class,
    ModuleServiceProvider::class,
];
