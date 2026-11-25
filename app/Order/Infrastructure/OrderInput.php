<?php

namespace App\Order\Infrastructure;

use App\Customer\Infrastructure\CustomerInput;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Pricing\Domain\Repository\CurrencyRepository;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One rule set for orders from the admin editor and any internal caller. Contact and address fields reuse the customer
 * rules. A customer IP is never accepted from input: it only comes from the trusted request context (OrderOrigin).
 */
final class OrderInput
{
    private const TEXT = 'not_regex:/[\x00-\x1f\x7f]/';

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keepRegions  the order's saved "COUNTRY:CODE" regions, accepted unchanged
     * @return array<string, mixed>
     */
    public function validate(array $input, bool $create, array $keepRegions = []): array
    {
        $customer = app(CustomerInput::class);
        foreach (['first_name', 'last_name', 'email', 'phone', 'customer_note', 'internal_note', 'coupon_code', 'customer_id', 'payment_method_id', 'shipping_method_id'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]) === '' ? null : trim($input[$field]);
            }
        }
        if (is_string($input['email'] ?? null)) {
            $input['email'] = strtolower($input['email']);
        }
        foreach (['billing', 'shipping'] as $type) {
            if (is_array($input[$type] ?? null)) {
                $address = $input[$type];
                $input[$type] = $customer->normalizedAddress($address);
                foreach (['floor', 'bell_name', 'notes'] as $field) {
                    if (! array_key_exists($field, $address)) {
                        unset($input[$type][$field]);
                    }
                }
            }
        }
        $sameShipping = filter_var($input['shipping_same'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $scale = app(CurrencyRepository::class)->currentPrimary()->decimals;
        $fraction = $scale === 0 ? '' : '(?:\.\d{1,'.$scale.'})?';
        $rules = [
            'submission_key' => ['nullable', 'uuid'],
            'coupon_code' => ['nullable', 'string', 'max:20'],
            'customer_id' => ['nullable', 'integer', 'min:1', 'exists:customers,id'],
            'first_name' => ['required', 'string', 'max:100', self::TEXT],
            'last_name' => ['required', 'string', 'max:100', self::TEXT],
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
            'phone' => ['nullable', 'string', 'max:40', $customer->phoneRule()],
            'locale' => ['required', Rule::in(app(LocaleCatalog::class)->active()->pluck('code')->all())],
            'billing' => ['required', 'array'],
            'shipping_same' => ['sometimes', 'boolean'],
            'shipping' => $sameShipping ? ['nullable'] : ['required', 'array'],
            'customer_note' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'invoice_requested' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['array'],
            'items.*.item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.product_id' => ['nullable', 'required_without:items.*.item_id', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['nullable', 'regex:/^\d{1,14}'.$fraction.'$/D'],
            'confirm_final' => ['sometimes', 'boolean'],
            'payment_method_id' => ['nullable', 'integer', 'min:1'],
            'shipping_method_id' => ['nullable', 'integer', 'min:1'],
            // A staff-entered shipping charge replaces the calculated one (e.g. while a carrier is unreachable).
            'shipping_amount' => ['nullable', 'regex:/^\d{1,14}'.$fraction.'$/D'],
            'recalculate_shipping' => ['sometimes', 'boolean'],
            ...$customer->addressRules('billing.', $keepRegions),
            ...($sameShipping ? [] : $customer->addressRules('shipping.', $keepRegions)),
        ];
        $rules = $create ? [...$rules, 'status_id' => ['required', 'integer', 'min:1']] : [...$rules, 'version' => ['required', 'integer', 'min:1']];
        $valid = Validator::make($input, $rules, $customer->messages(), $this->attributes())->validate();
        $valid['shipping'] = $sameShipping ? null : $valid['shipping'];
        foreach (['billing', 'shipping'] as $type) {
            if ($valid[$type] === null) {
                continue;
            }
            $valid[$type]['line1'] = trim($valid[$type]['line1'].' '.($valid[$type]['street_number'] ?? ''));
            if (mb_strlen($valid[$type]['line1']) > 200) {
                throw ValidationException::withMessages([$type.'.line1' => __('validation.max.string', ['attribute' => __('customers.line1'), 'max' => 200])]);
            }
            unset($valid[$type]['street_number']);
        }
        $valid['items'] = array_values(array_map(fn (array $item): array => [
            'item_id' => isset($item['item_id']) ? (string) $item['item_id'] : null, 'product_id' => isset($item['product_id']) ? (string) $item['product_id'] : null,
            'quantity' => (int) $item['quantity'], 'unit_price' => isset($item['unit_price']) && $item['unit_price'] !== '' ? (string) $item['unit_price'] : null,
        ], $valid['items']));

        return $valid;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['first_name' => __('orders.first_name'), 'last_name' => __('orders.last_name'), 'email' => __('orders.email'), 'phone' => __('orders.phone'), 'items' => __('orders.items'), 'customer_note' => __('orders.customer_note'), 'internal_note' => __('orders.internal_note')];
    }
}
