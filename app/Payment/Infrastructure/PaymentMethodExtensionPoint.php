<?php

namespace App\Payment\Infrastructure;

use App\Payment\Application\Contract\PaymentMethod;
use App\Payment\Application\Service\PaymentMethodRegistry;
use App\Shared\Infrastructure\Modules\AddonException;
use App\Shared\Infrastructure\Modules\ModuleExtensionPoint;
use App\Shared\Infrastructure\Modules\ModuleManifest;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * The `payment-methods` contribution: `{"payment-methods": {"method-id": "Vendor\\Addon\\SomeMethod"}}`. Each class
 * lives in the add-on's own namespace and implements PaymentMethod (or RedirectPaymentMethod). Identifiers are unique
 * across core and add-ons, and a stored method row belongs to the add-on that created it.
 */
final class PaymentMethodExtensionPoint implements ModuleExtensionPoint
{
    public function __construct(private Container $container, private PaymentMethodRegistry $registry) {}

    public function validate(ModuleManifest $manifest, mixed $declared, string $directory, array $others): void
    {
        if (! is_array($declared) || $declared === [] || array_is_list($declared)) {
            throw new AddonException('addons.error_payment_methods', ['name' => $manifest->name]);
        }
        foreach ($declared as $id => $class) {
            $id = (string) $id;
            $file = is_string($class) ? $manifest->classFile($class) : null;
            if (preg_match('/^[a-z][a-z0-9_.-]{1,59}$/D', $id) !== 1 || $file === null || ! is_file($directory.'/'.$file)) {
                throw new AddonException('addons.error_payment_methods', ['name' => $manifest->name]);
            }
            $owner = $this->registry->owner($id);
            $stored = DB::table('payment_methods')->where('code', $id)->value('owner');
            $declaredElsewhere = array_filter($others, fn (ModuleManifest $other): bool => isset($other->contributes['payment-methods'][$id]));
            if (($owner !== null && $owner !== $manifest->name) || ($stored !== null && $stored !== $manifest->name) || $declaredElsewhere !== []) {
                throw new AddonException('addons.error_payment_method_taken', ['name' => $manifest->name, 'method' => $id]);
            }
        }
    }

    public function register(ModuleManifest $manifest, mixed $declared): void
    {
        foreach ((array) $declared as $id => $class) {
            $class = (string) $class;
            $this->registry->add((string) $id, $manifest->name, function () use ($class): PaymentMethod {
                $method = $this->container->make($class);
                if (! $method instanceof PaymentMethod) {
                    throw new AddonException('addons.error_payment_methods', ['name' => $class]);
                }

                return $method;
            });
        }
    }
}
